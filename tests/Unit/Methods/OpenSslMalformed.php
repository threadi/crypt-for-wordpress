<?php
/**
 * Test that the OpenSsl method rejects malformed values cleanly: every
 * value, which does not have the structure the method writes, is reported
 * and decrypted to an empty string - never to something else, and never
 * with a PHP warning or a fatal error.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Methods;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test malformed values with the OpenSsl method.
 */
class OpenSslMalformed extends CryptForWordPressTests {

	/**
	 * Return a Crypt object and an OpenSsl method, initialized with a known key.
	 *
	 * @param array<string,mixed> $config Optional configuration of the method.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\OpenSsl}
	 */
	private function get_initialized_method( array $config = array() ): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'openssl-malformed-' . uniqid( '', true ) );

		define( strtoupper( $crypt_obj->get_slug() ) . '-HASH', bin2hex( random_bytes( 32 ) ) );

		$method = new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );
		if ( ! empty( $config ) ) {
			$method->set_config( $config );
		}
		$method->init();

		return array( $crypt_obj, $method );
	}

	/**
	 * Assert that the value is rejected with the given error code.
	 *
	 * @param \CryptForWordPress\Crypt            $crypt_obj The crypt object.
	 * @param \CryptForWordPress\Methods\OpenSsl $method    The method.
	 * @param string                              $value     The malformed value.
	 * @param string                              $code      The expected error code.
	 * @param string                              $context   The context.
	 *
	 * @return void
	 */
	private function assert_rejected( \CryptForWordPress\Crypt $crypt_obj, \CryptForWordPress\Methods\OpenSsl $method, string $value, string $code, string $context = '' ): void {
		$crypt_obj->clear_errors();

		$this->assertSame( '', '' === $context ? $method->decrypt( $value ) : $method->decrypt_with_context( $value, $context ) );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( $code, $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test values, which are no valid base64 at all.
	 *
	 * @return void
	 */
	public function test_value_which_is_not_base64(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$this->assert_rejected( $crypt_obj, $method, '!!! not base64 !!!', 'openssl_decrypt_payload_invalid' );
	}

	/**
	 * Test AEAD values, which do not consist of IV, tag and ciphertext.
	 *
	 * @return void
	 */
	public function test_aead_value_with_missing_parts(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'only-one-part' ), 'openssl_decrypt_missing_parts' );
		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'two:parts' ), 'openssl_decrypt_missing_parts' );
		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'a:b:c:d' ), 'openssl_decrypt_missing_parts' );
		// phpcs:enable
	}

	/**
	 * Test AEAD values, whose parts are no valid base64.
	 *
	 * @return void
	 */
	public function test_aead_value_with_parts_which_are_not_base64(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$this->assert_rejected( $crypt_obj, $method, base64_encode( '!!!:!!!:!!!' ), 'openssl_decrypt_encrypted_parts_missing' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Test AEAD values with an IV of another length than the one the method writes.
	 *
	 * @return void
	 */
	public function test_aead_value_with_iv_of_wrong_length(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$parts    = explode( ':', (string) base64_decode( $method->encrypt( 'Hallo World' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$parts[0] = base64_encode( substr( (string) base64_decode( $parts[0] ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assert_rejected( $crypt_obj, $method, base64_encode( implode( ':', $parts ) ), 'openssl_decrypt_iv_invalid' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Test a value of a cipher, which this server does not know.
	 *
	 * @return void
	 */
	public function test_unknown_cipher(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'cipher_algorithm' => 'not-a-cipher' ) );

		$this->assertSame( '', $method->encrypt( 'Hallo World' ) );
		$this->assertContains( 'openssl_cipher_algo_unknown', $crypt_obj->get_errors()->get_error_codes() );

		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'a:b:c' ), 'openssl_cipher_algo_unknown' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Test values of the current format without AEAD, whose IV has another
	 * length than the cipher uses.
	 *
	 * @return void
	 */
	public function test_non_aead_value_with_iv_of_wrong_length(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'cipher_algorithm' => 'aes-256-cbc' ) );

		$parts = explode( ':', (string) base64_decode( $method->encrypt( 'Hallo World' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertCount( 3, $parts );

		$parts[1] = base64_encode( substr( (string) base64_decode( $parts[1] ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assert_rejected( $crypt_obj, $method, base64_encode( implode( ':', $parts ) ), 'openssl_decrypt_encrypted_parts_missing' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Test that a value in the format of older versions without AEAD cannot
	 * be decrypted with a context - it can never have been bound to one.
	 *
	 * @return void
	 */
	public function test_legacy_non_aead_value_with_context(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'cipher_algorithm' => 'aes-256-cbc' ) );

		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'aGFsbG8=:d29ybGQ=' ), 'openssl_decrypt_context_unsupported', 'api_secret' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Test values in the format of older versions without AEAD, whose IV,
	 * HMAC or length is broken.
	 *
	 * @return void
	 */
	public function test_broken_legacy_non_aead_values(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'cipher_algorithm' => 'aes-256-cbc' ) );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assert_rejected( $crypt_obj, $method, base64_encode( '!!!:d29ybGQ=' ), 'openssl_iv_decrypt_error' );
		$this->assert_rejected( $crypt_obj, $method, base64_encode( base64_encode( random_bytes( 16 ) ) . ':!!!' ), 'openssl_hmac_decrypt_error' );
		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'short' ), 'openssl_decrypt_iv_nonaead_invalid' );
		// phpcs:enable
	}

	/**
	 * Test that a value without AEAD cannot be checked with a hash algorithm,
	 * which this server does not know.
	 *
	 * @return void
	 */
	public function test_non_aead_value_with_unknown_hash_algorithm(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method(
			array(
				'cipher_algorithm' => 'aes-256-cbc',
				'hash_algorithm'   => 'not-a-hash-algorithm',
			)
		);

		$this->assert_rejected( $crypt_obj, $method, base64_encode( 'aGFsbG8=:d29ybGQ=' ), 'openssl_hash_algo_unknown' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
