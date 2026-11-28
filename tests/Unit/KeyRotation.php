<?php
/**
 * Test the rotation of the key: rotate_key(), rollback_key_rotation(),
 * reencrypt() and discard_previous_keys().
 *
 * A PHP constant cannot be removed again during a process. So every test
 * uses its own unique slug, and "a later request" is simulated by a second
 * Crypt object with the same slug - or by preparing the database the way an
 * earlier request would have left it. The test NextRequest runs real
 * requests after a rotation.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use WP_Error;

/**
 * Object to test the rotation of the key.
 */
class KeyRotation extends CryptForWordPressTests {

	/**
	 * The methods and their configuration every scenario is tested with.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function methods(): array {
		return array(
			'openssl-aes-256-gcm' => array( 'openssl', array() ),
			'openssl-aes-256-cbc' => array( 'openssl', array( 'cipher_algorithm' => 'aes-256-cbc' ) ),
			'sodium'              => array( 'sodium', array() ),
		);
	}

	/**
	 * Return a Crypt object with a unique slug, which saves its key in the
	 * database.
	 *
	 * @param string              $method        The method to force.
	 * @param array<string,mixed> $method_config The configuration of the method.
	 * @param array<string,mixed> $config        Additional configuration of the Crypt object.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method, array $method_config = array(), array $config = array() ): \CryptForWordPress\Crypt {
		if ( 'sodium' === $method && ! self::is_sodium_usable() ) {
			$this->markTestSkipped( 'Sodium is not available in this test environment.' );
		}

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'key-rotation-' . $method . '-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config( // @phpstan-ignore argument.type
			array_merge(
				array(
					'force_method' => $method,
					'force_place'  => 'database',
					$method        => $method_config,
				),
				$config
			)
		);

		return $crypt_obj;
	}

	/**
	 * Return a second Crypt object for the same plugin - as a later request
	 * would create it.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The first Crypt object.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_second_crypt( \CryptForWordPress\Crypt $crypt_obj ): \CryptForWordPress\Crypt {
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		return $second;
	}

	/**
	 * Return the method object of a Crypt object.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The Crypt object.
	 *
	 * @return \CryptForWordPress\Method_Base
	 */
	private function get_method( \CryptForWordPress\Crypt $crypt_obj ): \CryptForWordPress\Method_Base {
		$method_obj = $crypt_obj->get_method();
		$this->assertInstanceOf( \CryptForWordPress\Method_Base::class, $method_obj );

		return $method_obj;
	}

	/**
	 * Return the option with the fingerprint of the key in use.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The Crypt object.
	 *
	 * @return string
	 */
	private function get_key_id( \CryptForWordPress\Crypt $crypt_obj ): string {
		return (string) get_option( $this->get_method( $crypt_obj )->get_key_id_option_name() );
	}

	/**
	 * Return the key ID a value in the versioned format names, hex encoded.
	 *
	 * @param string $encrypted The encrypted value.
	 *
	 * @return string
	 */
	private function get_key_id_of_value( string $encrypted ): string {
		$payload = (string) base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertStringStartsWith( "\xF3CW", $payload );

		return bin2hex( substr( $payload, 5, 8 ) );
	}

	/**
	 * Return the error codes of the Crypt object.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The Crypt object.
	 *
	 * @return array<int,string|int>
	 */
	private function get_codes( \CryptForWordPress\Crypt $crypt_obj ): array {
		$errors = $crypt_obj->get_errors();

		return null === $errors ? array() : $errors->get_error_codes();
	}

	/**
	 * Return the codes of everything, which has been reported - except the
	 * warning about the place "database", which every test uses.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The Crypt object.
	 *
	 * @return array<int,string|int>
	 */
	private function get_problems( \CryptForWordPress\Crypt $crypt_obj ): array {
		return array_values( array_diff( $this->get_codes( $crypt_obj ), array( 'insecure_place_database' ) ) );
	}

	/**
	 * Return the reason a key change has been refused with.
	 *
	 * @param mixed $result The result of rotate_key() or rollback_key_rotation().
	 *
	 * @return string
	 */
	private function get_reason( mixed $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'key_rotation_failed', $result->get_error_code() );
		$this->assertContains( 'key_rotation_not_possible', $result->get_error_codes() );

		$data = $result->get_error_data( 'key_rotation_not_possible' );
		$this->assertIsArray( $data );

		return (string) $data['reason'];
	}

	/**
	 * Test that a rotation replaces the key, and that the values encrypted
	 * before stay readable - in this request and in the next one.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_values_stay_readable_after_a_rotation( string $method, array $config ): void {
		$crypt_obj  = $this->get_crypt( $method, $config );
		$old_value  = $crypt_obj->encrypt( 'Hallo World', 'api_key' );
		$old_key_id = $this->get_key_id( $crypt_obj );
		$old_key    = get_option( $crypt_obj->get_slug() . '-hash' );

		$this->assertTrue( $crypt_obj->rotate_key() );

		// the place holds a new key, and it is the known one.
		$this->assertNotSame( $old_key, get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertNotSame( $old_key_id, $this->get_key_id( $crypt_obj ) );

		// new values name the new key.
		$new_value = $crypt_obj->encrypt( 'Hallo New World', 'api_key' );
		$this->assertSame( $this->get_key_id( $crypt_obj ), $this->get_key_id_of_value( $new_value ) );
		$this->assertSame( $old_key_id, $this->get_key_id_of_value( $old_value ) );

		// both are readable without any error.
		$crypt_obj->clear_errors();
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt_or_error( $old_value, 'api_key' ) );
		$this->assertSame( 'Hallo New World', $crypt_obj->decrypt_or_error( $new_value, 'api_key' ) );
		$this->assertSame( array(), $this->get_problems( $crypt_obj ) );

		// the next request reads both as well, and reports no changed key.
		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $second->decrypt( $old_value, 'api_key' ) );
		$this->assertSame( 'Hallo New World', $second->decrypt( $new_value, 'api_key' ) );
		$this->assertSame( array(), $this->get_problems( $second ) );

		// the previous key is not loaded with every request, and not saved in plain text.
		$option_name = $this->get_method( $crypt_obj )->get_previous_key_option_name();
		$this->assertArrayNotHasKey( $option_name, wp_load_alloptions() );
		$this->assertStringNotContainsString( (string) $old_key, (string) wp_json_encode( get_option( $option_name ) ) );
		$this->assertNotEmpty( get_option( $option_name ) );
	}

	/**
	 * Test that values in the format of version 4.2.0, which name no key,
	 * stay readable after a rotation - without the failed attempt with the
	 * new key being reported.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_values_of_the_format_of_version_4_2_0_stay_readable( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config, array( 'legacy_format' => true ) );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );
		$old_bound = $crypt_obj->encrypt( 'Hallo Context', 'field' );

		$this->assertTrue( $crypt_obj->rotate_key() );

		// a value written after the rotation, still in the old format.
		$new_value = $crypt_obj->encrypt( 'Hallo New World' );

		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $second->decrypt( $old_value ) );
		$this->assertSame( 'Hallo Context', $second->decrypt( $old_bound, 'field' ) );
		$this->assertSame( 'Hallo New World', $second->decrypt( $new_value ) );
		$this->assertSame( array(), $this->get_problems( $second ) );

		// a wrong context is still refused - with the error of the key in use.
		$this->assertInstanceOf( WP_Error::class, $second->decrypt_or_error( $old_bound, 'another_field' ) );
	}

	/**
	 * Test that reencrypt() moves a value to the new key, and leaves a value
	 * alone, which needs nothing.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_reencrypt_moves_a_value_to_the_new_key( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World', 'api_key' );

		// nothing to do before a rotation.
		$this->assertSame( $old_value, $crypt_obj->reencrypt( $old_value, 'api_key' ) );

		$this->assertTrue( $crypt_obj->rotate_key() );

		$moved = $crypt_obj->reencrypt( $old_value, 'api_key' );
		$this->assertIsString( $moved );
		$this->assertNotSame( $old_value, $moved );
		$this->assertSame( $this->get_key_id( $crypt_obj ), $this->get_key_id_of_value( $moved ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $moved, 'api_key' ) );

		// a moved value needs nothing more.
		$this->assertSame( $moved, $crypt_obj->reencrypt( $moved, 'api_key' ) );

		// empty values stay empty.
		$this->assertSame( '', $crypt_obj->reencrypt( '' ) );
		$this->assertSame( '', $crypt_obj->decrypt( (string) $crypt_obj->reencrypt( $crypt_obj->encrypt( '' ) ) ) );

		// a value, which cannot be decrypted, is an error - and is not changed.
		$result = $crypt_obj->reencrypt( $old_value, 'another_field' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
	}

	/**
	 * Test that reencrypt() moves values of the format of version 4.2.0
	 * into the versioned format, and values without the binding to their
	 * site to one with it - without a rotation.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_reencrypt_moves_a_value_to_the_current_format( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config, array( 'legacy_format' => true ) );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		// as long as the format of version 4.2.0 is requested, nothing changes.
		$this->assertSame( $old_value, $crypt_obj->reencrypt( $old_value ) );

		// without the setting the value gets the versioned format.
		$second = $this->get_second_crypt( $crypt_obj );
		$second->set_config( array_merge( $crypt_obj->get_config(), array( 'legacy_format' => false ) ) );
		$moved = $second->reencrypt( $old_value );
		$this->assertIsString( $moved );
		$this->assertSame( $this->get_key_id( $second ), $this->get_key_id_of_value( $moved ) );
		$this->assertSame( $moved, $second->reencrypt( $moved ) );

		// with 'bind_site' it gets the binding to its site.
		$third = $this->get_second_crypt( $crypt_obj );
		$third->set_config( array_merge( $crypt_obj->get_config(), array( 'legacy_format' => false, 'bind_site' => true ) ) );
		$bound = $third->reencrypt( $moved );
		$this->assertIsString( $bound );
		$this->assertNotSame( $moved, $bound );
		$this->assertSame( 1, ord( ( (string) base64_decode( $bound, true ) )[4] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( $bound, $third->reencrypt( $bound ) );
	}

	/**
	 * Test that the previous key is gone after discard_previous_keys(): its
	 * values are reported as values of another key, and the key can be
	 * rotated again.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_discard_previous_keys( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertTrue( $crypt_obj->rotate_key() );
		$moved = $crypt_obj->reencrypt( $old_value );
		$this->assertIsString( $moved );

		$crypt_obj->discard_previous_keys();
		$this->assertFalse( get_option( $this->get_method( $crypt_obj )->get_previous_key_option_name() ) );

		// the moved value is readable, the old one is not - in this request and the next one.
		foreach ( array( $crypt_obj, $this->get_second_crypt( $crypt_obj ) ) as $obj ) {
			$this->assertSame( 'Hallo World', $obj->decrypt( $moved ) );

			$result = $obj->decrypt_or_error( $old_value );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertContains( 'key_mismatch', $result->get_error_codes() );
		}

		// another rotation is possible now.
		$this->assertTrue( $crypt_obj->rotate_key() );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $moved ) );
	}

	/**
	 * Test that no second rotation is possible, as long as the previous key
	 * exists: it would be lost with it.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_second_rotation_needs_the_previous_key_to_be_discarded( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertTrue( $crypt_obj->rotate_key() );
		$key_id = $this->get_key_id( $crypt_obj );

		$this->assertSame( 'previous_key_exists', $this->get_reason( $crypt_obj->rotate_key() ) );
		$this->assertSame( 'previous_key_exists', $this->get_reason( $this->get_second_crypt( $crypt_obj )->rotate_key() ) );

		// nothing has changed.
		$this->assertSame( $key_id, $this->get_key_id( $crypt_obj ) );
		$this->assertSame( 'Hallo World', $this->get_second_crypt( $crypt_obj )->decrypt( $old_value ) );
	}

	/**
	 * Test that a rotation can be undone: the previous key is used again,
	 * and the values encrypted in the meantime stay readable.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_rollback_of_a_rotation( string $method, array $config ): void {
		$crypt_obj  = $this->get_crypt( $method, $config );
		$old_value  = $crypt_obj->encrypt( 'Hallo World' );
		$old_key_id = $this->get_key_id( $crypt_obj );

		// nothing to roll back yet.
		$this->assertSame( 'no_previous_key', $this->get_reason( $crypt_obj->rollback_key_rotation() ) );

		$this->assertTrue( $crypt_obj->rotate_key() );
		$new_key_id = $this->get_key_id( $crypt_obj );
		$new_value  = $crypt_obj->encrypt( 'Hallo New World' );

		$this->assertTrue( $crypt_obj->rollback_key_rotation() );

		// the former key is in use again.
		$this->assertSame( $old_key_id, $this->get_key_id( $crypt_obj ) );
		$this->assertSame( $old_key_id, $this->get_key_id_of_value( $crypt_obj->encrypt( 'Hallo' ) ) );

		// every value is readable - in this request and the next one.
		foreach ( array( $crypt_obj, $this->get_second_crypt( $crypt_obj ) ) as $obj ) {
			$obj->clear_errors();
			$this->assertSame( 'Hallo World', $obj->decrypt( $old_value ) );
			$this->assertSame( 'Hallo New World', $obj->decrypt( $new_value ) );
			$this->assertSame( array(), $this->get_problems( $obj ) );
		}

		// the value of the rolled back key is moved to the key in use.
		$moved = $crypt_obj->reencrypt( $new_value );
		$this->assertIsString( $moved );
		$this->assertSame( $old_key_id, $this->get_key_id_of_value( $moved ) );

		// and the rollback can be undone as well.
		$this->assertTrue( $crypt_obj->rollback_key_rotation() );
		$this->assertSame( $new_key_id, $this->get_key_id( $crypt_obj ) );
		$this->assertSame( 'Hallo World', $this->get_second_crypt( $crypt_obj )->decrypt( $old_value ) );
	}

	/**
	 * Test that a key derived by its place is never rotated.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_derived_key_is_not_rotated( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt(
			$method,
			$config,
			array(
				'force_place' => 'wordpress_salts',
				'salt'        => 'CRYPT_FOR_WORDPRESS_TEST_SALT',
			)
		);
		$value     = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $value );

		$this->assertSame( 'derived_key', $this->get_reason( $crypt_obj->rotate_key() ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $value ) );
	}

	/**
	 * Test that a key, which is not saved in the active place - e.g. given
	 * by a variable of the server -, is never rotated: the new key would
	 * not be the one loaded with the next request.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_key_from_another_source_is_not_rotated( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );

		// the key is defined, but not by the active place.
		$stored_key = 'sodium' === $method ? sodium_bin2base64( random_bytes( 32 ), SODIUM_BASE64_VARIANT_ORIGINAL ) : bin2hex( random_bytes( 32 ) );
		define( strtoupper( $crypt_obj->get_slug() ) . ( 'sodium' === $method ? '-SODIUM-HASH' : '-HASH' ), $stored_key );

		$value = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertSame( 'key_not_in_active_place', $this->get_reason( $crypt_obj->rotate_key() ) );
		$this->assertFalse( get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $value ) );
	}

	/**
	 * Test that a rotation, whose new key could not be saved, changes
	 * nothing: the key in use stays, and no previous key is left.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_failed_save_keeps_the_key_in_use( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$value     = $crypt_obj->encrypt( 'Hallo World' );
		$key_id    = $this->get_key_id( $crypt_obj );
		$old_key   = get_option( $crypt_obj->get_slug() . '-hash' );

		// the database refuses the new key.
		add_filter( 'pre_update_option_' . $crypt_obj->get_slug() . '-hash', static fn( $value, $old_value ) => $old_value, 10, 2 );

		$result = $crypt_obj->rotate_key();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'key_not_saved', $result->get_error_codes() );

		// nothing has changed.
		$this->assertSame( $old_key, get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertSame( $key_id, $this->get_key_id( $crypt_obj ) );
		$this->assertFalse( get_option( $this->get_method( $crypt_obj )->get_previous_key_option_name() ) );
		$this->assertSame( $key_id, $this->get_key_id_of_value( $crypt_obj->encrypt( 'Hallo' ) ) );

		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $second->decrypt( $value ) );
		$this->assertSame( array(), $this->get_problems( $second ) );
	}

	/**
	 * Test that a rotation, which has ended after the previous key has been
	 * kept, but before the new key has been saved, leaves nothing behind:
	 * the next request removes the entry quietly.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_rotation_ended_before_the_new_key_has_been_saved( string $method, array $config ): void {
		$crypt_obj   = $this->get_crypt( $method, $config );
		$value       = $crypt_obj->encrypt( 'Hallo World' );
		$option_name = $this->get_method( $crypt_obj )->get_previous_key_option_name();

		// the entry an interrupted rotation leaves: the key in use, encrypted with a key, which never arrived.
		update_option(
			$option_name,
			array(
				array(
					'key_id'     => $this->get_key_id( $crypt_obj ),
					'wrapped_by' => '0123456789abcdef',
					'value'      => 'anything',
				),
			),
			false
		);

		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $second->decrypt( $value ) );
		$this->assertSame( array(), $this->get_problems( $second ) );
		$this->assertFalse( get_option( $option_name ) );

		// the rotation can be done again.
		$this->assertTrue( $second->rotate_key() );
		$this->assertSame( 'Hallo World', $this->get_second_crypt( $crypt_obj )->decrypt( $value ) );
	}

	/**
	 * Test that a rotation, which has ended after the new key has been
	 * saved, but before its fingerprint has been remembered, is not
	 * reported as changed key.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_rotation_ended_before_the_fingerprint_has_been_remembered( string $method, array $config ): void {
		$crypt_obj  = $this->get_crypt( $method, $config );
		$value      = $crypt_obj->encrypt( 'Hallo World' );
		$old_key_id = $this->get_key_id( $crypt_obj );

		$this->assertTrue( $crypt_obj->rotate_key() );
		$new_key_id = $this->get_key_id( $crypt_obj );

		// as if the fingerprint had not been remembered.
		update_option( $this->get_method( $crypt_obj )->get_key_id_option_name(), $old_key_id );

		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $second->decrypt( $value ) );
		$this->assertSame( array(), $this->get_problems( $second ) );
		$this->assertSame( $new_key_id, $this->get_key_id( $second ) );
	}

	/**
	 * Test that another Crypt object of this request, which has been used
	 * before the rotation, takes over the new key - and never removes the
	 * previous key as leftover.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_other_objects_of_this_request_take_over_the_new_key( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		// another object, used before the rotation.
		$other = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $other->decrypt( $old_value ) );

		$this->assertTrue( $crypt_obj->rotate_key() );

		// it encrypts with the new key, and still reads the old value.
		$this->assertSame( $this->get_key_id( $crypt_obj ), $this->get_key_id_of_value( $other->encrypt( 'Hallo' ) ) );
		$this->assertSame( 'Hallo World', $other->decrypt( $old_value ) );
		$this->assertNotEmpty( get_option( $this->get_method( $crypt_obj )->get_previous_key_option_name() ) );

		// a third one, created afterward, as well.
		$third = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo World', $third->decrypt( $old_value ) );
		$this->assertSame( array(), $this->get_problems( $third ) );
	}

	/**
	 * Test that the action is fired with the fingerprints - never with a key.
	 *
	 * @return void
	 */
	public function test_action_after_a_rotation(): void {
		$crypt_obj  = $this->get_crypt( 'openssl' );
		$crypt_obj->encrypt( 'Hallo World' );
		$old_key_id = $this->get_key_id( $crypt_obj );

		$calls = array();
		add_action(
			$crypt_obj->get_slug() . '_crypt_key_rotated',
			static function ( ...$args ) use ( &$calls ): void {
				$calls[] = $args;
			},
			10,
			3
		);

		$this->assertTrue( $crypt_obj->rotate_key() );
		$this->assertSame( array( array( $old_key_id, $this->get_key_id( $crypt_obj ), 'openssl' ) ), $calls );

		$this->assertTrue( $crypt_obj->rollback_key_rotation() );
		$this->assertCount( 2, $calls );
		$this->assertSame( $old_key_id, $calls[1][1] );
	}

	/**
	 * Test that a changed value of the previous key is refused.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_changed_value_of_the_previous_key_is_refused( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$payload   = (string) base64_decode( $crypt_obj->encrypt( 'Hallo World' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertTrue( $crypt_obj->rotate_key() );

		// change the last byte.
		$payload[ strlen( $payload ) - 1 ] = chr( ord( $payload[ strlen( $payload ) - 1 ] ) ^ 1 );
		$result                            = $crypt_obj->decrypt_or_error( base64_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotContains( 'key_mismatch', $result->get_error_codes() );
	}

	/**
	 * Test that a previous key, which cannot be decrypted, is reported - and
	 * that the values of the key in use are still readable.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_unreadable_previous_key_is_reported( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertTrue( $crypt_obj->rotate_key() );
		$new_value = $crypt_obj->encrypt( 'Hallo New World' );

		// damage the previous key.
		$option_name          = $this->get_method( $crypt_obj )->get_previous_key_option_name();
		$entries              = get_option( $option_name );
		$entries[0]['value'] .= 'x';
		update_option( $option_name, $entries, false );

		$second = $this->get_second_crypt( $crypt_obj );
		$this->assertSame( 'Hallo New World', $second->decrypt( $new_value ) );
		$this->assertContains( 'previous_key_unreadable', $this->get_codes( $second ) );
		$this->assertInstanceOf( WP_Error::class, $second->decrypt_or_error( $old_value ) );

		// it is not replaced by another rotation.
		$this->assertSame( 'previous_key_exists', $this->get_reason( $second->rotate_key() ) );
	}

	/**
	 * Test that an uninstallation keeps the previous key with the key in
	 * use: a later installation reads the values of both again.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_the_previous_key( string $method, array $config ): void {
		$crypt_obj = $this->get_crypt( $method, $config );
		$old_value = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertTrue( $crypt_obj->rotate_key() );

		$this->get_second_crypt( $crypt_obj )->uninstall();

		$this->assertNotEmpty( get_option( $this->get_method( $crypt_obj )->get_previous_key_option_name() ) );
		$this->assertSame( 'Hallo World', $this->get_second_crypt( $crypt_obj )->decrypt( $old_value ) );

		// starting over without the kept key removes the previous key as well.
		$crypt_obj->discard_kept_keys();
		$this->assertFalse( get_option( $this->get_method( $crypt_obj )->get_previous_key_option_name() ) );
	}

	/**
	 * Test that errors held back with collect_errors() are not reported,
	 * unless they are handed to add_errors().
	 *
	 * @return void
	 */
	public function test_collect_errors(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'key-rotation-collect-' . str_replace( '.', '', uniqid( '', true ) ) );

		$reported = array();
		add_action(
			$crypt_obj->get_slug() . '_crypt_error',
			static function ( string $code ) use ( &$reported ): void {
				$reported[] = $code;
			}
		);

		list( $result, $errors ) = $crypt_obj->collect_errors(
			static function () use ( $crypt_obj ): string {
				$crypt_obj->add_error( 'outer', 'Outer.' );

				// an inner collection keeps its own errors.
				list( , $inner ) = $crypt_obj->collect_errors(
					static function () use ( $crypt_obj ): string {
						$crypt_obj->add_error( 'inner', 'Inner.' );
						return '';
					}
				);
				$crypt_obj->add_errors( $inner );

				return 'result';
			}
		);

		$this->assertSame( 'result', $result );
		$this->assertSame( array( 'outer', 'inner' ), array_column( $errors, 'code' ) );
		$this->assertFalse( $crypt_obj->has_errors() );
		$this->assertSame( array(), $reported );

		$crypt_obj->add_errors( $errors );
		$this->assertSame( array( 'outer', 'inner' ), $this->get_codes( $crypt_obj ) );
		$this->assertSame( array( 'outer', 'inner' ), $reported );
	}
}
