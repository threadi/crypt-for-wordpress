<?php
/**
 * Test encrypt_or_error() and decrypt_or_error(): they tell a failure apart
 * from a value without content.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use WP_Error;

/**
 * Object to test encrypt_or_error() and decrypt_or_error().
 */
class CryptOrError extends CryptForWordPressTests {

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
	 * Build a crypt object with a unique slug.
	 *
	 * @param string              $method The method to force.
	 * @param array<string,mixed> $config Additional configuration.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method, array $config = array() ): \CryptForWordPress\Crypt {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'crypt-or-error-' . $method . '-' . uniqid( '', true ) );
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
	 * Return a copy of the given encrypted value with one changed byte of
	 * its ciphertext.
	 *
	 * A changed character of a base64 encoding may not change anything, so
	 * the bytes of the ciphertext are changed themselves.
	 *
	 * @param string $encrypted The encrypted value.
	 *
	 * @return string
	 */
	private function tamper( string $encrypted ): string {
		$raw = base64_decode( $encrypted, true );
		$this->assertIsString( $raw );

		// OpenSSL: base64 parts, separated by colons - the last one is the ciphertext.
		if ( 1 === preg_match( '/^[A-Za-z0-9+\/=:]+$/', $raw ) && str_contains( $raw, ':' ) ) {
			$parts      = explode( ':', $raw );
			$ciphertext = (string) base64_decode( (string) end( $parts ), true );

			$ciphertext[0]                = chr( ord( $ciphertext[0] ) ^ 1 );
			$parts[ count( $parts ) - 1 ] = base64_encode( $ciphertext );

			return base64_encode( implode( ':', $parts ) );
		}

		// Sodium: the raw payload, ending with the authentication tag.
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );

		return base64_encode( $raw );
	}

	/**
	 * Test that both return the same strings as encrypt() and decrypt() on
	 * success - also with a context.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_success_returns_the_strings( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );

		$encrypted = $crypt_obj->encrypt_or_error( 'Hallo World' );
		$this->assertIsString( $encrypted );
		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt_or_error( $encrypted ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		$encrypted = $crypt_obj->encrypt_or_error( 'Hallo World', 'api_secret' );
		$this->assertIsString( $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt_or_error( $encrypted, 'api_secret' ) );

		// the database place has been reported, but it did not prevent anything.
		$this->assertContains( 'insecure_place_database', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that an empty value is no error.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_empty_value_is_no_error( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );

		$this->assertSame( '', $crypt_obj->decrypt_or_error( '' ) );

		// what encrypt() returns for an empty text - an empty string or a
		// value, whose content is empty - is no error either.
		$encrypted = $crypt_obj->encrypt_or_error( '' );
		$this->assertIsString( $encrypted );
		$this->assertSame( $crypt_obj->encrypt( '' ) === '', '' === $encrypted );
		$this->assertSame( '', $crypt_obj->decrypt_or_error( $encrypted ) );
	}

	/**
	 * Test that a value, whose content is empty, is no error - also with the
	 * warnings of the first usage in this request.
	 *
	 * @return void
	 */
	public function test_value_with_empty_content_is_no_error(): void {
		$crypt_obj = $this->get_crypt( 'sodium' );

		// Sodium encrypts an empty text.
		$encrypted = $crypt_obj->encrypt( '' );
		$this->assertNotSame( '', $encrypted );

		// a second object, whose first usage reports the database place.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( '', $second->decrypt_or_error( $encrypted ) );
		$this->assertContains( 'insecure_place_database', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a changed value is a WP_Error with the reason - while
	 * decrypt() still returns an empty string.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_changed_value_is_an_error( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$encrypted = $this->tamper( $crypt_obj->encrypt( 'Hallo World' ) );
		$crypt_obj->clear_errors();

		$result = $crypt_obj->decrypt_or_error( $encrypted, 'api_secret' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
		$this->assertSame( array( 'context' => 'api_secret' ), $result->get_error_data() );
		$this->assertGreaterThan( 1, count( $result->get_error_codes() ) );

		// the reasons are collected in the Crypt object as well.
		foreach ( array_slice( $result->get_error_codes(), 1 ) as $code ) {
			$this->assertContains( $code, $crypt_obj->get_errors()->get_error_codes() );
		}

		// nothing changed for decrypt().
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted, 'api_secret' ) );
	}

	/**
	 * Test that a value, which belongs to another context, is a WP_Error.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_value_of_another_context_is_an_error( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$encrypted = $crypt_obj->encrypt( 'Hallo World', 'api_secret' );

		$result = $crypt_obj->decrypt_or_error( $encrypted, 'another_field' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
	}

	/**
	 * Test that only the errors of the call are part of the WP_Error - not
	 * the ones of an earlier call.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_error_holds_only_the_errors_of_its_call( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		// an error of an earlier call.
		$crypt_obj->add_error( 'earlier_error', 'An error of an earlier call.' );

		$result = $crypt_obj->decrypt_or_error( $this->tamper( $encrypted ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotContains( 'earlier_error', $result->get_error_codes() );
		$this->assertNotContains( 'insecure_place_database', $result->get_error_codes() );

		// also after the errors have been cleared.
		$crypt_obj->clear_errors();
		$result = $crypt_obj->decrypt_or_error( $this->tamper( $encrypted ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertGreaterThan( 1, count( $result->get_error_codes() ) );
		$this->assertSame( count( $result->get_error_codes() ) - 1, count( $crypt_obj->get_errors()->get_error_codes() ) );
	}

	/**
	 * Test that a missing method is a WP_Error.
	 *
	 * @return void
	 */
	public function test_missing_method_is_an_error(): void {
		$crypt_obj = $this->get_crypt( 'openssl' );
		add_filter( $crypt_obj->get_slug() . '_crypt_methods', '__return_empty_array' );

		$result = $crypt_obj->encrypt_or_error( 'Hallo World' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'encrypt_failed', $result->get_error_code() );
		$this->assertContains( 'no_method_available', $result->get_error_codes() );

		$result = $crypt_obj->decrypt_or_error( 'some-encrypted-value' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
		$this->assertContains( 'no_method_available', $result->get_error_codes() );

		// also for an empty text: it cannot be encrypted either.
		$this->assertInstanceOf( WP_Error::class, $crypt_obj->encrypt_or_error( '' ) );
	}

	/**
	 * Test that a key, which could not be saved, is a WP_Error with the
	 * reasons of the first usage: they are the reasons of the failure.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_missing_key_is_an_error_with_its_reasons( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => '' ) );
		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function (): array {
				return array( 'CryptForWordPress\Tests\Fixtures\BrokenPlace' );
			}
		);

		$result = $crypt_obj->encrypt_or_error( 'Hallo World' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'encrypt_failed', $result->get_error_code() );
		$this->assertContains( 'key_not_saved', $result->get_error_codes() );
		$this->assertContains( 'no_key_available', $result->get_error_codes() );

		// the same without a key, with a second value.
		$result = $crypt_obj->decrypt_or_error( 'some-encrypted-value' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'no_key_available', $result->get_error_codes() );
		$this->assertNotContains( 'key_not_saved', $result->get_error_codes() );
	}

	/**
	 * Test that the error action is fired once per error - not again for
	 * the WP_Error, which is returned.
	 *
	 * @return void
	 */
	public function test_errors_are_reported_once(): void {
		$crypt_obj = $this->get_crypt( 'sodium' );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$codes = array();
		add_action(
			$crypt_obj->get_slug() . '_crypt_error',
			static function ( string $code ) use ( &$codes ): void {
				$codes[] = $code;
			}
		);

		$result = $crypt_obj->decrypt_or_error( $this->tamper( $encrypted ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array_slice( $result->get_error_codes(), 1 ), array_values( array_unique( $codes ) ) );
		$this->assertNotContains( 'decrypt_failed', $codes );
	}
}
