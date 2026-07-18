<?php
/**
 * Test tampering-resistance of the Sodium method.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Methods;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test tampering-resistance of the Sodium method.
 */
class Sodium extends CryptForWordPressTests {

	/**
	 * Build a fresh Crypt object with a unique slug, and a Sodium method
	 * already initialized with a known, random key - without ever writing
	 * to a Place.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\Sodium} The crypt object and the initialized method.
	 */
	private function get_initialized_method(): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sodium-tamper-' . uniqid('', true) );

		$method   = new \CryptForWordPress\Methods\Sodium( $crypt_obj );
		$constant = $method->get_constant();

		// define the constant Method_Base::get_constant() expects, with a
		// known, freshly generated key in the same base64 variant init()
		// expects it in.
        try {
            define(
                $constant,
                sodium_bin2base64(sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL)
            );
        } catch (\Exception $e) {
            $this->fail( $e->getMessage() );
        }

        try {
            $method->init();
        } catch (\Exception $e) {
            $this->fail( $e->getMessage() );
        }

        return array( $crypt_obj, $method );
	}

	/**
	 * Test that flipping a bit anywhere in an encrypted payload (nonce,
	 * ciphertext, or the trailing Poly1305/AEAD tag) makes it fail to
	 * decrypt, instead of silently returning corrupted plaintext.
	 *
	 * @return void
	 */
	public function test_tampered_payload_fails_to_decrypt(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$plain_text = 'Hallo World, this must not leak.';
		$encrypted  = $method->encrypt( $plain_text );
		$this->assertNotEmpty( $encrypted );

		// flip the very last byte of the payload - always part of the
		// trailing AEAD tag, regardless of which algorithm tier was picked.
        try {
            $payload = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException $e) {
            $this->fail( $e->getMessage() );
        }

        $payload[ strlen( $payload ) - 1 ] = chr( ord( $payload[ strlen( $payload ) - 1 ] ) ^ 0xFF );
        try {
            $tampered = sodium_bin2base64($payload, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException $e) {
            $this->fail( $e->getMessage() );
        }

        $this->assertFalse( $crypt_obj->has_errors() );

		$decrypted = $method->decrypt( $tampered );

		$this->assertSame( '', $decrypted );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'sodium_decrypt_error', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that truncating a payload down to just the algorithm byte plus a
	 * few random bytes (too short to contain a valid nonce) is rejected
	 * cleanly instead of causing a fatal error.
	 *
	 * @return void
	 */
	public function test_truncated_payload_fails_to_decrypt(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$plain_text = 'Hallo World, this must not leak.';
		$encrypted  = $method->encrypt( $plain_text );
		$this->assertNotEmpty( $encrypted );

        try {
            $payload = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
            $truncated = sodium_bin2base64( substr( $payload, 0, 2 ), SODIUM_BASE64_VARIANT_ORIGINAL );
        } catch (\SodiumException $e) {
            $this->fail( $e->getMessage() );
        }

		$decrypted = $method->decrypt( $truncated );

		$this->assertSame( '', $decrypted );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'sodium_payload_mismatch', $crypt_obj->get_errors()->get_error_codes() );
	}
}
