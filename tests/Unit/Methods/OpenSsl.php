<?php
/**
 * Test tampering-resistance and the backwards-compatible legacy fallback
 * chain of the OpenSsl method.
 *
 * These tests bypass Crypt::get_method()/init()'s normal key-generation flow
 * on purpose: they define the "...-HASH" constant with a known, fixed key
 * BEFORE constructing the method, so init() picks it straight up (see
 * Method_Base::get_hash_value_from_constant()) without ever touching a real
 * Place. This keeps the tests fast, deterministic and independent of what
 * the current hosting allows to write to.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Methods;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test tampering-resistance and legacy fallback of the OpenSsl method.
 */
class OpenSsl extends CryptForWordPressTests {

    /**
     * Build a fresh Crypt object with a unique slug, and an OpenSsl method
     * already initialized with a known, random key - without ever writing
     * to a Place.
     *
     * @param string $slug_prefix A short prefix identifying the calling test.
     * @param array<string,mixed> $config Optional method configuration (e.g., a different cipher).
     *
     * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\OpenSsl} The crypt object and the initialized method.
     */
	private function get_initialized_method( string $slug_prefix, array $config = array() ): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( $slug_prefix . '-' . uniqid('', true) );

		// define the constant Method_Base::get_constant() expects, with a
		// known, fixed-length hex key - exactly the format encrypt()/decrypt()
		// expect for the default hash_type "hash".
		$constant = strtoupper( $crypt_obj->get_slug() ) . '-HASH';
        try {
            define($constant, bin2hex(random_bytes(32)));
        } catch (\Exception $e) {
            $this->fail( $e->getMessage() );
        }

        $method = new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );
		if ( ! empty( $config ) ) {
			$method->set_config( $config );
		}
		$method->init();

		return array( $crypt_obj, $method );
	}

	/**
	 * Test that flipping a bit in the ciphertext of a default (AEAD/GCM)
	 * encrypted value makes it fail to decrypt, instead of silently
	 * returning corrupted plaintext.
	 *
	 * @return void
	 */
	public function test_tampered_ciphertext_fails_to_decrypt(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( 'openssl-tamper-ct' );

		$plain_text = 'Hallo World, this must not leak.';
		$encrypted  = $method->encrypt( $plain_text );
		$this->assertNotEmpty( $encrypted );

		$parts = explode( ':', (string) base64_decode( $encrypted, true ) );
		$this->assertCount( 3, $parts );

		// flip the last bit of the ciphertext part.
		$ciphertext                                        = (string) base64_decode( $parts[2], true );
		$ciphertext[ strlen( $ciphertext ) - 1 ]            = chr( ord( $ciphertext[ strlen( $ciphertext ) - 1 ] ) ^ 0xFF );
		$parts[2]                                           = base64_encode( $ciphertext );
		$tampered                                           = base64_encode( implode( ':', $parts ) );

		$this->assertFalse( $crypt_obj->has_errors() );

		$decrypted = $method->decrypt( $tampered );

		$this->assertSame( '', $decrypted );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'openssl_decrypt_aead_error', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that flipping a bit in the AEAD tag of a default (GCM) encrypted
	 * value makes it fail to decrypt - this is the actual authentication
	 * check, distinct from tampering with the ciphertext itself.
	 *
	 * @return void
	 */
	public function test_tampered_tag_fails_to_decrypt(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( 'openssl-tamper-tag' );

		$plain_text = 'Hallo World, this must not leak.';
		$encrypted  = $method->encrypt( $plain_text );
		$this->assertNotEmpty( $encrypted );

		$parts = explode( ':', (string) base64_decode( $encrypted, true ) );
		$this->assertCount( 3, $parts );

		// flip the last bit of the AEAD tag.
		$tag                              = (string) base64_decode( $parts[1], true );
		$tag[ strlen( $tag ) - 1 ]        = chr( ord( $tag[ strlen( $tag ) - 1 ] ) ^ 0xFF );
		$parts[1]                         = base64_encode( $tag );
		$tampered                         = base64_encode( implode( ':', $parts ) );

		$decrypted = $method->decrypt( $tampered );

		$this->assertSame( '', $decrypted );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'openssl_decrypt_aead_error', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that flipping a bit in the HMAC of a non-AEAD (e.g. CBC) encrypted
	 * value is detected and rejected, instead of returning unauthenticated
	 * plaintext.
	 *
	 * @return void
	 */
	public function test_tampered_hmac_fails_to_decrypt_non_aead(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( 'openssl-tamper-hmac', array( 'cipher_algorithm' => 'AES-256-CBC' ) );

		$plain_text = 'must not be readable if tampered';
		$encrypted  = $method->encrypt( $plain_text );
		$this->assertNotEmpty( $encrypted );

		$parts = explode( ':', (string) base64_decode( $encrypted, true ) );
		$this->assertCount( 2, $parts );

		// flip the first byte of the HMAC (the first 32 bytes of the second part).
		$hmac_and_ciphertext     = (string) base64_decode( $parts[1], true );
		$hmac_and_ciphertext[0]  = chr( ord( $hmac_and_ciphertext[0] ) ^ 0xFF );
		$parts[1]                = base64_encode( $hmac_and_ciphertext );
		$tampered                = base64_encode( implode( ':', $parts ) );

		$decrypted = $method->decrypt( $tampered );

		$this->assertSame( '', $decrypted );
		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'openssl_decrypt_hmac_error', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a value encrypted by an OLD version of this library - which
	 * used the raw, undecoded hash string directly as the AES-256-GCM key,
	 * instead of first hex-decoding it via get_decoded_master_key() - can
	 * still be decrypted today via the legacy fallback in decrypt().
	 *
	 * @return void
	 */
	public function test_legacy_undecoded_key_fallback_still_decryptable(): void {
		list( , $method ) = $this->get_initialized_method( 'openssl-legacy-aead' );

		$cipher     = 'aes-256-gcm';
		$iv         = random_bytes( (int) openssl_cipher_iv_length( $cipher ) );
		$plain_text = 'legacy secret value';

		// this is the OLD scheme: the raw hex string used directly as the key.
		$legacy_key = $method->get_hash();
		$tag        = '';
		$ciphertext = openssl_encrypt( $plain_text, $cipher, $legacy_key, OPENSSL_RAW_DATA, $iv, $tag );
		$this->assertIsString( $ciphertext );

		$legacy_encrypted_text = base64_encode(
			base64_encode( $iv ) . ':' .
			base64_encode( $tag ) . ':' .
			base64_encode( $ciphertext )
		);

		// today's decrypt() must still be able to read this old-format value.
		$this->assertSame( $plain_text, $method->decrypt( $legacy_encrypted_text ) );
	}

	/**
	 * Test that non-AEAD values (e.g. CBC) encrypted by the two older key
	 * schemes - "state B" (decoded master key used directly, no HKDF
	 * separation) and "state A" (raw undecoded hash string used directly) -
	 * can still be decrypted today via the legacy fallback chain.
	 *
	 * @return void
	 */
	public function test_legacy_non_aead_fallback_chain(): void {
		list( , $method ) = $this->get_initialized_method( 'openssl-legacy-cbc', array( 'cipher_algorithm' => 'AES-256-CBC' ) );

		$cipher      = 'AES-256-CBC';
		$iv_length   = (int) openssl_cipher_iv_length( $cipher );
		$hash_algo   = 'sha256';
		$decoded_key = (string) hex2bin( $method->get_hash() );
		$raw_key     = $method->get_hash();
		$plain_text  = 'very old legacy value';

		// "state B": the decoded master key used directly for both
		// encryption and HMAC, without HKDF key separation.
		$iv             = random_bytes( $iv_length );
		$ciphertext_raw = openssl_encrypt( $plain_text, $cipher, $decoded_key, OPENSSL_RAW_DATA, $iv );
		$this->assertIsString( $ciphertext_raw );
		$hmac          = hash_hmac( $hash_algo, $ciphertext_raw, $decoded_key, true );
		$state_b_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );

		$this->assertSame( $plain_text, $method->decrypt( $state_b_value ) );

		// "state A": the very original scheme, using the raw undecoded hash
		// string directly for both encryption and HMAC.
		$iv             = random_bytes( $iv_length );
		$ciphertext_raw = openssl_encrypt( $plain_text, $cipher, $raw_key, OPENSSL_RAW_DATA, $iv );
		$this->assertIsString( $ciphertext_raw );
		$hmac          = hash_hmac( $hash_algo, $ciphertext_raw, $raw_key, true );
		$state_a_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );

		$this->assertSame( $plain_text, $method->decrypt( $state_a_value ) );
	}
}
