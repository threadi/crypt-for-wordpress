<?php
/**
 * Test which key is used, if several places hold one - and what happens if
 * two processes generate the first key at the same time.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use CryptForWordPress\Tests\Fixtures\SecondStaticKeyPlace;
use CryptForWordPress\Tests\Fixtures\StaticKeyPlace;

/**
 * Object to test the selection of the key.
 */
class KeySelection extends CryptForWordPressTests {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		StaticKeyPlace::$keys = array();
		parent::tear_down();
	}

	/**
	 * Return the methods to test.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function methods(): array {
		return array(
			'openssl' => array( 'openssl' ),
			'sodium'  => array( 'sodium' ),
		);
	}

	/**
	 * Return a new Crypt object with a unique slug, using the database as
	 * active place, and the two places holding keys chosen by the test.
	 *
	 * @param string $method The method name.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method ): \CryptForWordPress\Crypt {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'key-selection-' . $method . '-' . uniqid( '', true ) );
		$crypt_obj->set_config(
			array(
				'force_method' => $method,
				'force_place'  => 'database',
			)
		);

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function ( array $places ): array {
				$places[] = StaticKeyPlace::class;
				$places[] = SecondStaticKeyPlace::class;
				return $places;
			}
		);

		return $crypt_obj;
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
	 * Return a valid key in the format the given method stores it in.
	 *
	 * @param string $method The method name.
	 *
	 * @return string
	 */
	private function get_valid_key( string $method ): string {
		return 'sodium' === $method ? base64_encode( random_bytes( 32 ) ) : bin2hex( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Return the name of the option holding the fingerprint of the key.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method    The method name.
	 *
	 * @return string
	 */
	private function get_key_id_option( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return $crypt_obj->get_slug() . '_crypt_key_id_' . $method;
	}

	/**
	 * Test that the known key is used, although another place, which is
	 * asked first, holds another valid key.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_known_key_wins_over_another_key_found_first( string $method ): void {
		// an earlier request: values have been encrypted with a key.
		$earlier   = $this->get_crypt( $method );
		$encrypted = $earlier->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );
		$known_key = (string) get_option( $earlier->get_slug() . '-hash' );
		$known_id  = (string) get_option( $this->get_key_id_option( $earlier, $method ) );

		// now: the key is gone from the database, but two other places hold one.
		$crypt_obj = $this->get_crypt( $method );
		update_option( $this->get_key_id_option( $crypt_obj, $method ), $known_id );
		StaticKeyPlace::$keys = array(
			'static_key_place'        => $this->get_valid_key( $method ),
			'second_static_key_place' => $known_key,
		);

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertSame( $known_key, constant( $this->get_constant( $crypt_obj, $method ) ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_in_inactive_place', $codes );
		$this->assertNotContains( 'key_changed', $codes );
		$this->assertNotContains( 'key_missing', $codes );
		$this->assertSame( $known_id, get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that the first valid key is used and reported as changed, if no
	 * place holds the known key - a key in another format is skipped.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_first_valid_key_is_used_if_the_known_one_is_nowhere( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$valid_key            = $this->get_valid_key( $method );
		StaticKeyPlace::$keys = array(
			'static_key_place'        => 'not a key of this method',
			'second_static_key_place' => $valid_key,
		);

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertSame( $valid_key, constant( $this->get_constant( $crypt_obj, $method ) ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_changed', $codes );
		$this->assertNotContains( 'key_missing', $codes );
		$this->assertNotSame( '0123456789abcdef', get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that only one of two processes, which generate the first key at
	 * the same time, saves its key. The other one encrypts nothing.
	 *
	 * The other process is simulated: right after this one claimed the key,
	 * the claim of the other one is read back.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_only_one_of_two_processes_saves_the_first_key( string $method ): void {
		$crypt_obj   = $this->get_crypt( $method );
		$option_name = $this->get_key_id_option( $crypt_obj, $method );

		// the other process claims the key right after this one.
		$claimed = false;
		$claim   = static function ( string $option ) use ( $option_name, &$claimed ): void {
			if ( $option === $option_name ) {
				$claimed = true;
			}
		};
		$read    = static function ( $value ) use ( &$claimed ) {
			return $claimed ? 'ffffffffffffffff' : $value;
		};
		add_action( 'added_option', $claim );
		add_filter( 'option_' . $option_name, $read );

		try {
			$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		} finally {
			remove_action( 'added_option', $claim );
			remove_filter( 'option_' . $option_name, $read );
		}

		$this->assertTrue( $claimed );
		$this->assertContains( 'key_not_saved', $crypt_obj->get_errors()->get_error_codes() );

		// the key of this process has neither been saved nor used.
		$this->assertFalse( get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertFalse( defined( $this->get_constant( $crypt_obj, $method ) ) );
	}
}
