<?php
/**
 * Test where an uninstallation keeps the key, and that a later installation
 * finds it there again.
 *
 * With the Secrets API of WordPress 7.2 or newer the key is kept as secret,
 * encrypted by WordPress. Without it, it is kept in an option, which is not
 * loaded with every request - so it does not end up in a persistent object
 * cache.
 *
 * The tests with the Secrets API use a fake of it: WordPress keeps its
 * provider for the whole request, so a test could not make it fail.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use CryptForWordPress\Tests\Fixtures\FakeSecretsApi;

/**
 * Object to test where an uninstallation keeps the key.
 */
class UninstallKeptKey extends CryptForWordPressTests {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		FakeSecretsApi::reset();
		parent::tear_down();
	}

	/**
	 * The methods every scenario is tested with.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function methods(): array {
		return array(
			'openssl' => array( 'openssl' ),
			'sodium'  => array( 'sodium' ),
		);
	}

	/**
	 * Build a crypt object with a unique slug, which uses the database.
	 *
	 * @param string              $method The method to force.
	 * @param array<string,mixed> $config Additional configuration.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method, array $config = array() ): \CryptForWordPress\Crypt {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'uninstall-kept-key-' . $method . '-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config(
			array_merge(
				array(
					'force_method' => $method,
					'force_place'  => 'database',
				),
				$config
			)
		);

		return $crypt_obj;
	}

	/**
	 * Use the fake of the Secrets API for the given crypt object.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 *
	 * @return void
	 */
	private function use_fake_secrets_api( \CryptForWordPress\Crypt $crypt_obj ): void {
		if ( ! class_exists( 'WP_Secret' ) ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}

		FakeSecretsApi::reset();

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function ( array $places ): array {
				return array_map(
					static fn( string $place ): string => 'CryptForWordPress\Places\SecretsApi' === $place ? FakeSecretsApi::class : $place,
					$places
				);
			}
		);
	}

	/**
	 * Return the constant the given method reads its key from.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method    The method name.
	 *
	 * @return string
	 */
	private function get_constant( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return strtoupper( $crypt_obj->get_slug() ) . ( 'sodium' === $method ? '-SODIUM-HASH' : '-HASH' );
	}

	/**
	 * Return the option an uninstallation keeps the key of the given method in.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method    The method name.
	 *
	 * @return string
	 */
	private function get_option_name( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' );
	}

	/**
	 * Return the name of the secret an uninstallation keeps the key of the
	 * given method in.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method    The method name.
	 *
	 * @return string
	 */
	private function get_secret_name( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return $crypt_obj->get_slug() . '/uninstalled-' . $method;
	}

	/**
	 * Return a valid key in the format the given method stores it in.
	 *
	 * @param string $method The method name.
	 *
	 * @return string
	 */
	private function get_stored_key( string $method ): string {
		if ( 'sodium' === $method ) {
			return sodium_bin2base64( sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL );
		}

		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Remember the given key as the key this installation is known to use -
	 * as an earlier installation would have done.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj  The crypt object.
	 * @param string                   $method     The method name.
	 * @param string                   $stored_key The key as it is stored.
	 *
	 * @return void
	 */
	private function remember_key( \CryptForWordPress\Crypt $crypt_obj, string $method, string $stored_key ): void {
		update_option( $crypt_obj->get_slug() . '_crypt_key_id_' . $method, substr( hash_hmac( 'sha256', 'crypt-for-wordpress-key-id', $stored_key ), 0, 16 ) );
	}

	/**
	 * Return the error codes reported so far.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 *
	 * @return array<int,string|int>
	 */
	private function get_codes( \CryptForWordPress\Crypt $crypt_obj ): array {
		return $crypt_obj->has_errors() ? $crypt_obj->get_errors()->get_error_codes() : array();
	}

	/**
	 * Test that uninstall() keeps the key as secret, if the Secrets API can
	 * take it - never in plain text in the database.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_kept_as_secret( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] ?? null );
		$this->assertFalse( get_option( $this->get_option_name( $crypt_obj, $method ) ) );

		// the place itself has been cleaned up.
		$this->assertFalse( get_option( $crypt_obj->get_slug() . '-hash' ) );
	}

	/**
	 * Test that the name of the kept secret is one WordPress accepts.
	 *
	 * @return void
	 */
	public function test_name_of_the_kept_secret_is_valid(): void {
		$crypt_obj = $this->get_crypt( 'sodium' );
		$crypt_obj->set_slug( 'Uninstall Kept Key/With Unusual Characters ' . uniqid() );
		$place = new \CryptForWordPress\Places\SecretsApi( $crypt_obj );

		$name = $place->get_kept_key_name( 'sodium' );

		$this->assertMatchesRegularExpression( '@^[a-z0-9][a-z0-9_-]*[a-z0-9]/[a-z0-9][a-z0-9_-]*[a-z0-9]$@', $name );
		$this->assertStringEndsWith( '/uninstalled-sodium', $name );
		$this->assertLessThanOrEqual( 172, strlen( $name ) );

		if ( function_exists( 'wp_secrets_validate_name' ) ) {
			$this->assertTrue( wp_secrets_validate_name( $name ) );
		}
	}

	/**
	 * Test that a key, which an older version has kept in plain text, is
	 * removed from the database, once the Secrets API holds it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_kept_by_an_older_version_is_moved_into_the_secret( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );
		update_option( $this->get_option_name( $crypt_obj, $method ), $stored_key, true );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] ?? null );
		$this->assertFalse( get_option( $this->get_option_name( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that the key is kept in the database, if the Secrets API does not
	 * confirm it holds the key - first checked, then trusted.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_kept_in_the_database_if_the_secret_is_not_confirmed( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		// the provider accepts the secret, but does not hand it over.
		FakeSecretsApi::$withheld = true;

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, get_option( $this->get_option_name( $crypt_obj, $method ) ) );
		$this->assertArrayNotHasKey( $this->get_option_name( $crypt_obj, $method ), wp_load_alloptions() );
	}

	/**
	 * Test that the key is kept in the database, if the Secrets API does not
	 * accept new secrets.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_kept_in_the_database_with_a_read_only_provider( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		FakeSecretsApi::$writable = false;

		$crypt_obj->uninstall();

		$this->assertSame( array(), FakeSecretsApi::$secrets );
		$this->assertSame( $stored_key, get_option( $this->get_option_name( $crypt_obj, $method ) ) );
		$this->assertArrayNotHasKey( $this->get_option_name( $crypt_obj, $method ), wp_load_alloptions() );
	}

	/**
	 * Test that the key is kept in an option without autoload, if the usage
	 * of the Secrets API is blocked - or WordPress has none (before 7.2).
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_kept_in_the_database_without_the_secrets_api( string $method ): void {
		$crypt_obj  = $this->get_crypt( $method, array( 'block_secrets_api' => true ) );
		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		// an older version has kept a key with autoload.
		update_option( $this->get_option_name( $crypt_obj, $method ), $stored_key, true );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, get_option( $this->get_option_name( $crypt_obj, $method ) ) );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			$this->assertArrayNotHasKey( $this->get_option_name( $crypt_obj, $method ), wp_load_alloptions() );
		}

		// nothing has been kept as secret.
		if ( \CryptForWordPress\Places\SecretsApi::is_api_available() ) {
			$this->assertSame( '', ( new \CryptForWordPress\Places\SecretsApi( $crypt_obj ) )->get_kept_key( $method ) );
		}
	}

	/**
	 * Test that a later installation uses the key kept as secret: the values
	 * encrypted before stay readable, the key is saved in its place, and the
	 * kept secret is removed.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_later_installation_uses_the_key_kept_as_secret( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		// as the uninstallation has left it.
		$stored_key = $this->get_stored_key( $method );
		$this->remember_key( $crypt_obj, $method, $stored_key );
		FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] = $stored_key;

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted, (string) wp_json_encode( $this->get_codes( $crypt_obj ) ) );

		// the kept key is used.
		$this->assertSame( $stored_key, constant( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertNotContains( 'key_changed', $this->get_codes( $crypt_obj ) );
		$this->assertNotContains( 'key_missing', $this->get_codes( $crypt_obj ) );

		// it is in its place now, and the kept secret is gone.
		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertArrayNotHasKey( $this->get_secret_name( $crypt_obj, $method ), FakeSecretsApi::$secrets );
	}

	/**
	 * Test that the key kept as secret stays, if it could not be saved in a
	 * place - it is the only copy of it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_kept_as_secret_survives_a_failed_save( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => '' ) );
		$this->use_fake_secrets_api( $crypt_obj );
		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function (): array {
				return array( 'CryptForWordPress\Tests\Fixtures\BrokenPlace', FakeSecretsApi::class );
			},
			20
		);

		$stored_key = $this->get_stored_key( $method );
		FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] = $stored_key;
		FakeSecretsApi::$set_fails = true;

		// the key is still usable, as the secret holds it ...
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		// ... the failed save is reported ...
		$this->assertContains( 'key_not_saved', $this->get_codes( $crypt_obj ) );

		// ... and the only copy of the key has not been deleted.
		$this->assertSame( $stored_key, FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] ?? null );
	}

	/**
	 * Test that no new key is generated, if the key kept as secret cannot be
	 * read: it is the key all values have been encrypted with.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_no_new_key_while_the_kept_key_is_unavailable( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		$this->remember_key( $crypt_obj, $method, $stored_key );
		FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] = $stored_key;
		FakeSecretsApi::$withheld = true;

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$this->assertContains( 'secrets_api_unavailable', $this->get_codes( $crypt_obj ) );
		$this->assertContains( 'key_unavailable', $this->get_codes( $crypt_obj ) );
		$this->assertNotContains( 'key_missing', $this->get_codes( $crypt_obj ) );

		// no key has been saved, and the kept one is still there.
		$this->assertFalse( get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertFalse( defined( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertArrayHasKey( $this->get_secret_name( $crypt_obj, $method ), FakeSecretsApi::$secrets );
	}

	/**
	 * Test that a later installation uses the key, which an older version has
	 * kept in the database, and removes it there.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_later_installation_uses_the_key_kept_in_the_database( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'block_secrets_api' => true ) );

		$stored_key = $this->get_stored_key( $method );
		$this->remember_key( $crypt_obj, $method, $stored_key );
		update_option( $this->get_option_name( $crypt_obj, $method ), $stored_key, true );

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$this->assertSame( $stored_key, constant( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertNotContains( 'key_changed', $this->get_codes( $crypt_obj ) );
		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertFalse( get_option( $this->get_option_name( $crypt_obj, $method ) ) );
	}

	/**
	 * Test the full round trip with the Secrets API of WordPress itself: a
	 * value encrypted before the uninstallation is readable again after it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_round_trip_with_the_secrets_api_of_wordpress( string $method ): void {
		if ( ! \CryptForWordPress\Places\SecretsApi::is_api_available() || ! wp_secrets_provider_is_writable() ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}

		// the key in the database: a later request does not find it in a constant.
		$crypt_obj  = $this->get_crypt( $method );
		$stored_key = $this->get_stored_key( $method );
		$this->remember_key( $crypt_obj, $method, $stored_key );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		$crypt_obj->uninstall();

		$place = new \CryptForWordPress\Places\SecretsApi( $crypt_obj );
		$this->assertSame( $stored_key, $place->get_kept_key( $method ) );
		$this->assertFalse( get_option( $this->get_option_name( $crypt_obj, $method ) ) );

		// the key is not in plain text in the database.
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $stored_key ) . '%' ) ) );

		// installed again.
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertSame( $stored_key, constant( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertSame( '', $place->get_kept_key( $method ) );
	}

	/**
	 * Test that discard_kept_keys() allows starting over with a new key, if
	 * the key kept as secret cannot be read anymore - and that this is
	 * reported as missing key.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_discard_kept_keys_starts_over_with_a_new_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_secrets_api( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		$this->remember_key( $crypt_obj, $method, $stored_key );
		FakeSecretsApi::$secrets[ $this->get_secret_name( $crypt_obj, $method ) ] = $stored_key;
		FakeSecretsApi::$withheld = true;

		// blocked: the kept key cannot be read.
		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_unavailable', $this->get_codes( $crypt_obj ) );
		$this->assertTrue( $crypt_obj->get_errors()->get_error_data( 'secrets_api_unavailable' )['kept_key'] );

		$crypt_obj->discard_kept_keys();
		$this->assertArrayNotHasKey( $this->get_secret_name( $crypt_obj, $method ), FakeSecretsApi::$secrets );

		// the same object starts over with a new key - and reports the lost one.
		$crypt_obj->clear_errors();
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted, (string) wp_json_encode( $this->get_codes( $crypt_obj ) ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertContains( 'key_missing', $this->get_codes( $crypt_obj ) );
		$this->assertNotSame( $stored_key, constant( $this->get_constant( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that discard_kept_keys() removes a key kept in the database as
	 * well, and does nothing harmful without a kept key.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_discard_kept_keys_removes_the_option( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'block_secrets_api' => true ) );

		// nothing kept: nothing happens.
		$crypt_obj->discard_kept_keys();
		$this->assertFalse( $crypt_obj->has_errors() );

		update_option( $this->get_option_name( $crypt_obj, $method ), $this->get_stored_key( $method ), false );

		$crypt_obj->discard_kept_keys();

		$this->assertFalse( get_option( $this->get_option_name( $crypt_obj, $method ) ) );
	}
}
