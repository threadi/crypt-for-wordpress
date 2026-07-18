<?php
/**
 * Round-trip tests for every supported method.
 *
 * These tests check what actually goes through the encryption: multibyte
 * text, binary data, very long values and - most importantly - values which
 * contain the ":" character the OpenSSL payload format uses as its own
 * separator.
 *
 * Like the other method tests they define the "...-HASH" constant with a
 * known key BEFORE building the method, so init() picks it straight up and
 * nothing is ever written to a real place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test round-trips of all supported methods.
 */
class EncryptionRoundTrip extends CryptForWordPressTests {

	/**
	 * Build every method available on this hosting, each with its own crypt
	 * object and its own known key.
	 *
	 * @return array<string,\CryptForWordPress\Method_Base> The methods, keyed by their name.
	 */
	private function get_initialized_methods(): array {
		$methods = array();

		// OpenSSL, keyed with a hex string - the format the default
		// hash_type "hash" produces.
		if ( function_exists( 'openssl_encrypt' ) ) {
			$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$crypt_obj->set_slug( 'roundtrip-openssl-' . uniqid( '', true ) );

			try {
				define( strtoupper( $crypt_obj->get_slug() ) . '-HASH', bin2hex( random_bytes( 32 ) ) );
			} catch ( \Exception $e ) {
				$this->fail( $e->getMessage() );
			}

			$method = new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );
			$method->init();

			$methods['openssl'] = $method;
		}

		// Sodium, keyed with a base64 encoded key in the variant init() expects.
		if ( extension_loaded( 'sodium' ) ) {
			$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$crypt_obj->set_slug( 'roundtrip-sodium-' . uniqid( '', true ) );

			$method = new \CryptForWordPress\Methods\Sodium( $crypt_obj );

			try {
				define(
					$method->get_constant(),
					sodium_bin2base64( sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL )
				);
				$method->init();
			} catch ( \Exception $e ) {
				$this->fail( $e->getMessage() );
			}

			$methods['sodium'] = $method;
		}

		$this->assertNotEmpty( $methods, 'No crypt method is available on this hosting.' );

		return $methods;
	}

	/**
	 * Run a full encrypt/decrypt round-trip through every available method.
	 *
	 * @param string $plain_text The value to run through.
	 *
	 * @return void
	 */
	private function assert_round_trip( string $plain_text ): void {
		foreach ( $this->get_initialized_methods() as $name => $method ) {
			$encrypted = $method->encrypt( $plain_text );

			$this->assertIsString( $encrypted, 'Method: ' . $name );
			$this->assertNotEmpty( $encrypted, 'Method: ' . $name );
			$this->assertNotSame( $plain_text, $encrypted, 'Method: ' . $name );

			$this->assertSame( $plain_text, $method->decrypt( $encrypted ), 'Method: ' . $name );
		}
	}

	/**
	 * Test a plain ASCII value.
	 *
	 * @return void
	 */
	public function test_round_trip_ascii(): void {
		$this->assert_round_trip( 'Hallo World' );
	}

	/**
	 * Test that multibyte content survives the round-trip byte for byte -
	 * API tokens and passwords are not always ASCII.
	 *
	 * @return void
	 */
	public function test_round_trip_multibyte(): void {
		$this->assert_round_trip( 'Grüße aus Köln – 世界 – 🔐 – Ünïcödé' );
	}

	/**
	 * Test a value containing the ":" separator used by the OpenSSL payload
	 * format. If the payload were ever split naively, this value would come
	 * back truncated.
	 *
	 * @return void
	 */
	public function test_round_trip_value_with_separator(): void {
		$this->assert_round_trip( 'user:password:with:colons' );
		$this->assert_round_trip( 'aGVsbG8=:d29ybGQ=:::' );
	}

	/**
	 * Test a value which looks like an already encrypted payload, so a
	 * double encryption cannot confuse the format detection.
	 *
	 * @return void
	 */
	public function test_round_trip_value_that_looks_encrypted(): void {
		$this->assert_round_trip( base64_encode( 'abc:def:ghi' ) );
	}

	/**
	 * Test a value containing line breaks and null bytes.
	 *
	 * @return void
	 */
	public function test_round_trip_binary_content(): void {
		$this->assert_round_trip( "line one\r\nline two\n\tindented\x00trailing" );
	}

	/**
	 * Test a long value, well above one cipher block.
	 *
	 * @return void
	 */
	public function test_round_trip_long_value(): void {
		$this->assert_round_trip( str_repeat( 'Hallo World. ', 2000 ) );
	}

	/**
	 * Test a single space - it is not empty, so it has to be encrypted and
	 * returned unchanged.
	 *
	 * @return void
	 */
	public function test_round_trip_whitespace_only(): void {
		$this->assert_round_trip( ' ' );
	}

	/**
	 * Test that an empty input never produces something that decrypts into
	 * anything else than an empty string.
	 *
	 * Note that the methods differ here: OpenSSL bails out early and returns
	 * an empty string, Sodium encrypts the empty string. Both are fine, as
	 * long as the value survives the round-trip.
	 *
	 * @return void
	 */
	public function test_round_trip_empty_value(): void {
		foreach ( $this->get_initialized_methods() as $name => $method ) {
			$this->assertSame( '', $method->decrypt( $method->encrypt( '' ) ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that the very same plain text never produces the very same
	 * ciphertext twice - a fresh IV/nonce has to be used for every call.
	 *
	 * @return void
	 */
	public function test_ciphertext_is_never_deterministic(): void {
		foreach ( $this->get_initialized_methods() as $name => $method ) {
			$first  = $method->encrypt( 'Hallo World' );
			$second = $method->encrypt( 'Hallo World' );

			$this->assertNotSame( $first, $second, 'Method: ' . $name );

			// but both still decrypt to the same value.
			$this->assertSame( 'Hallo World', $method->decrypt( $first ), 'Method: ' . $name );
			$this->assertSame( 'Hallo World', $method->decrypt( $second ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that a value encrypted with one key cannot be decrypted with
	 * another one, and that this is reported as an error.
	 *
	 * @return void
	 */
	public function test_value_is_not_decryptable_with_a_foreign_key(): void {
		$mine    = $this->get_initialized_methods();
		$foreign = $this->get_initialized_methods();

		foreach ( $mine as $name => $method ) {
			$encrypted = $method->encrypt( 'Hallo World' );
			$this->assertNotEmpty( $encrypted, 'Method: ' . $name );

			$this->assertSame( '', $foreign[ $name ]->decrypt( $encrypted ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that arbitrary garbage handed to decrypt() is rejected with an
	 * empty string instead of an exception or a PHP warning.
	 *
	 * Heads-up: OpenSsl::decrypt() currently explodes the decoded payload on
	 * ":" and reads $c_exploded[1] and [2] without checking how many parts
	 * it actually got, so a malformed value raises "Undefined array key"
	 * warnings. This test is expected to fail until a count() guard is added
	 * there - it documents the missing input validation on purpose.
	 *
	 * @return void
	 */
	public function test_garbage_input_is_rejected(): void {
		$garbage = array(
			'not-base64-at-all',
			'!!!',
			base64_encode( 'no-separator-and-way-too-short' ),
			str_repeat( 'A', 1024 ),
		);

		foreach ( $this->get_initialized_methods() as $name => $method ) {
			foreach ( $garbage as $value ) {
				$this->assertSame( '', $method->decrypt( $value ), 'Method: ' . $name . ', value: ' . $value );
			}
		}
	}
}
