<?php
/**
 * Test the binding of an encrypted value to a context.
 *
 * All values of a plugin are encrypted with the same key. Without a context
 * an encrypted value is valid wherever this key is used: whoever can write
 * to the database could copy the encrypted API secret into a field whose
 * content is displayed, and have it decrypted there. With a context the
 * value only decrypts where it has been encrypted for.
 *
 * Like the other method tests these define the "...-HASH" constant with a
 * known key BEFORE building the method, so nothing is ever written to a
 * real place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the binding of an encrypted value to a context.
 */
class EncryptionContext extends CryptForWordPressTests {

	/**
	 * Build every method available on this hosting, each with its own crypt
	 * object and its own known key.
	 *
	 * @return array<string,array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Method_Base}> Crypt object and method, keyed by a name.
	 */
	private function get_initialized_methods(): array {
		$methods = array();

		// OpenSSL with its default AEAD cipher, and with a cipher without AEAD.
		foreach ( array( 'openssl-gcm' => 'AES-256-GCM', 'openssl-cbc' => 'AES-256-CBC' ) as $name => $cipher ) {
			$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$crypt_obj->set_slug( 'context-' . $name . '-' . uniqid( '', true ) );

			define( strtoupper( $crypt_obj->get_slug() ) . '-HASH', bin2hex( random_bytes( 32 ) ) );

			$method = new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );
			$method->set_config( array( 'cipher_algorithm' => $cipher ) );
			$method->init();

			$methods[ $name ] = array( $crypt_obj, $method );
		}

		// Sodium.
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'context-sodium-' . uniqid( '', true ) );

		$method = new \CryptForWordPress\Methods\Sodium( $crypt_obj );
		define(
			$method->get_constant(),
			sodium_bin2base64( sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL )
		);
		$method->init();

		$methods['sodium'] = array( $crypt_obj, $method );

		return $methods;
	}

	/**
	 * Test that a value decrypts with the context it has been encrypted with.
	 *
	 * @return void
	 */
	public function test_round_trip_with_context(): void {
		foreach ( $this->get_initialized_methods() as $name => list( $crypt_obj, $method ) ) {
			$encrypted = $method->encrypt_with_context( 'Hallo World', 'api_secret' );

			$this->assertNotEmpty( $encrypted, 'Method: ' . $name );
			$this->assertSame( 'Hallo World', $method->decrypt_with_context( $encrypted, 'api_secret' ), 'Method: ' . $name );
			$this->assertFalse( $crypt_obj->has_errors(), 'Method: ' . $name );
		}
	}

	/**
	 * Test the purpose of it: an encrypted value copied into another field
	 * does not decrypt there.
	 *
	 * @return void
	 */
	public function test_value_does_not_decrypt_in_another_context(): void {
		foreach ( $this->get_initialized_methods() as $name => list( $crypt_obj, $method ) ) {
			$encrypted = $method->encrypt_with_context( 'sk_live_123', 'api_secret' );

			$this->assertSame( '', $method->decrypt_with_context( $encrypted, 'display_name' ), 'Method: ' . $name );
			$this->assertTrue( $crypt_obj->has_errors(), 'Method: ' . $name );
		}
	}

	/**
	 * Test that a value bound to a context does not decrypt without one.
	 *
	 * @return void
	 */
	public function test_value_with_context_does_not_decrypt_without_context(): void {
		foreach ( $this->get_initialized_methods() as $name => list( , $method ) ) {
			$encrypted = $method->encrypt_with_context( 'sk_live_123', 'api_secret' );

			$this->assertSame( '', $method->decrypt( $encrypted ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that a value encrypted without a context cannot be passed off as
	 * belonging to one.
	 *
	 * @return void
	 */
	public function test_value_without_context_does_not_decrypt_with_context(): void {
		foreach ( $this->get_initialized_methods() as $name => list( , $method ) ) {
			$encrypted = $method->encrypt( 'sk_live_123' );

			$this->assertSame( '', $method->decrypt_with_context( $encrypted, 'api_secret' ), 'Method: ' . $name );

			// without context it is the value it always was.
			$this->assertSame( 'sk_live_123', $method->decrypt( $encrypted ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that contexts are compared as a whole: a context is not allowed
	 * to match because it is the beginning of another one.
	 *
	 * @return void
	 */
	public function test_similar_contexts_are_different_contexts(): void {
		foreach ( $this->get_initialized_methods() as $name => list( , $method ) ) {
			$encrypted = $method->encrypt_with_context( 'sk_live_123', 'post:12:secret' );

			foreach ( array( 'post:1:secret', 'post:12:secre', 'post:12:secret ', 'Post:12:secret', "post:12:secret\0" ) as $context ) {
				$this->assertSame( '', $method->decrypt_with_context( $encrypted, $context ), 'Method: ' . $name . ', context: ' . $context );
			}
		}
	}

	/**
	 * Test that a context may contain anything, including the separator of
	 * the OpenSSL payload format and multibyte characters.
	 *
	 * @return void
	 */
	public function test_context_may_contain_any_character(): void {
		foreach ( $this->get_initialized_methods() as $name => list( , $method ) ) {
			foreach ( array( 'a:b:c', 'Größe – 世界', "line\nbreak", str_repeat( 'x', 5000 ) ) as $context ) {
				$this->assertSame( 'Hallo World', $method->decrypt_with_context( $method->encrypt_with_context( 'Hallo World', $context ), $context ), 'Method: ' . $name );
			}
		}
	}

	/**
	 * Test that values encrypted before the context existed stay readable:
	 * no context is the same as it has always been.
	 *
	 * @return void
	 */
	public function test_values_of_older_versions_stay_readable_without_context(): void {
		$methods = $this->get_initialized_methods();

		// OpenSSL / GCM as written by the previous version: no additional data at all.
		$method     = $methods['openssl-gcm'][1];
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( 'old value', 'aes-256-gcm', (string) hex2bin( $method->get_hash() ), OPENSSL_RAW_DATA, $iv, $tag );
		$old_value  = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( (string) $ciphertext ) );

		$this->assertSame( 'old value', $method->decrypt( $old_value ) );

		// Sodium as written by the previous version: empty additional data.
		$method    = $methods['sodium'][1];
		$nonce     = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$old_value = sodium_bin2base64(
			chr( 3 ) . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( 'old value', '', $nonce, $method->get_hash() ),
			SODIUM_BASE64_VARIANT_ORIGINAL
		);

		$this->assertSame( 'old value', $method->decrypt( $old_value ) );
	}

	/**
	 * Test that a value in the old format for ciphers without AEAD is
	 * refused with a context - it cannot have been encrypted with one - and
	 * that the error tells what to do.
	 *
	 * @return void
	 */
	public function test_old_non_aead_format_is_refused_with_a_context(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_methods()['openssl-cbc'];

		// the previous format: HKDF-derived keys, HMAC over the ciphertext only.
		$master    = (string) hex2bin( $method->get_hash() );
		$enc_key   = hash_hkdf( 'sha256', $master, 32, 'encryption' );
		$hmac_key  = hash_hkdf( 'sha256', $master, 32, 'authentication' );
		$iv        = random_bytes( 16 );
		$raw       = (string) openssl_encrypt( 'old value', 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv );
		$old_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( hash_hmac( 'sha256', $raw, $hmac_key, true ) . $raw ) );

		$this->assertSame( '', $method->decrypt_with_context( $old_value, 'api_secret' ) );
		$this->assertContains( 'openssl_decrypt_context_unsupported', $crypt_obj->get_errors()->get_error_codes() );

		// without context it is still readable, so it can be migrated.
		$this->assertSame( 'old value', $method->decrypt( $old_value ) );
	}

	/**
	 * Test the migration of an existing value to a context, as described in
	 * the documentation.
	 *
	 * @return void
	 */
	public function test_existing_value_can_be_migrated_to_a_context(): void {
		foreach ( $this->get_initialized_methods() as $name => list( , $method ) ) {
			$stored = $method->encrypt( 'sk_live_123' );

			// read: with context first, without as fallback - and save it again.
			$plain = $method->decrypt_with_context( $stored, 'api_secret' );
			if ( '' === $plain ) {
				$plain = $method->decrypt( $stored );
				$this->assertSame( 'sk_live_123', $plain, 'Method: ' . $name );

				$stored = $method->encrypt_with_context( $plain, 'api_secret' );
			}

			// from now on it only decrypts in its context.
			$this->assertSame( 'sk_live_123', $method->decrypt_with_context( $stored, 'api_secret' ), 'Method: ' . $name );
			$this->assertSame( '', $method->decrypt( $stored ), 'Method: ' . $name );
		}
	}

	/**
	 * Test that the context is passed through by the Crypt object.
	 *
	 * @return void
	 */
	public function test_context_is_passed_through_by_the_crypt_object(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'context-crypt-' . uniqid( '', true ) );
		$crypt_obj->set_config( array( 'force_place' => 'database' ) );

		$encrypted = $crypt_obj->encrypt( 'sk_live_123', 'api_secret' );

		$this->assertNotEmpty( $encrypted );
		$this->assertSame( 'sk_live_123', $crypt_obj->decrypt( $encrypted, 'api_secret' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted, 'display_name' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted ) );
	}

	/**
	 * Test that a value bound to a context cannot be passed off as a value
	 * in the format of older versions, which knows no context.
	 *
	 * The HMAC of the current format covers "marker, context, IV,
	 * ciphertext". If the old format used the same keys, these very bytes
	 * could be presented as its ciphertext, with the HMAC still matching -
	 * and for some context lengths the original value would come out behind
	 * some garbage, without any context given.
	 *
	 * @return void
	 */
	public function test_value_with_context_cannot_be_rewrapped_in_the_old_format(): void {
		list( , $method ) = $this->get_initialized_methods()['openssl-cbc'];

		// with these lengths the IV and the ciphertext stay aligned to the cipher blocks.
		foreach ( array( 'secret', str_repeat( 'x', 22 ) ) as $context ) {
			$encrypted = $method->encrypt_with_context( 'sk_live_this-is-the-bound-secret', $context );

			list( , $iv_encoded, $payload_encoded ) = explode( ':', (string) base64_decode( $encrypted, true ) );

			$iv      = (string) base64_decode( $iv_encoded, true );
			$payload = (string) base64_decode( $payload_encoded, true );
			$hmac    = substr( $payload, 0, 32 );

			// the old format: IV, then HMAC and ciphertext. Its "ciphertext" is
			// exactly what the HMAC of the current format covers.
			$rewrapped = 'v2' . pack( 'J', strlen( $context ) ) . $context . $iv . substr( $payload, 32 );
			$forged    = base64_encode( base64_encode( str_repeat( "\0", 16 ) ) . ':' . base64_encode( $hmac . $rewrapped ) );

			$this->assertSame( '', $method->decrypt( $forged ), 'Context length: ' . strlen( $context ) );
		}
	}

	/**
	 * Test that a method written before the context existed keeps working:
	 * its encrypt() and decrypt() take one parameter only. Without a
	 * context nothing changes for it. With one it reports that it cannot
	 * bind a value - instead of ignoring the context silently.
	 *
	 * @return void
	 */
	public function test_method_written_before_the_context_existed_keeps_working(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'context-legacy-method-' . uniqid( '', true ) );
		$crypt_obj->set_config( array( 'force_place' => 'database' ) );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_methods',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\LegacyMethod' );
			}
		);

		// without a context it works as it always did.
		$this->assertSame( 'dlroW ollaH', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( 'dlroW ollaH' ) );
		$this->assertNotContains( 'context_not_supported', (array) $crypt_obj->get_errors()?->get_error_codes() );

		// with a context nothing is returned, and the reason is reported.
		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World', 'api_secret' ) );
		$this->assertSame( '', $crypt_obj->decrypt( 'dlroW ollaH', 'api_secret' ) );
		$this->assertContains( 'context_not_supported', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a class extending a built-in method, which overrides
	 * encrypt() and decrypt() with one parameter, keeps working - and that
	 * its methods are the ones called.
	 *
	 * @return void
	 */
	public function test_class_extending_a_built_in_method_keeps_working(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'context-legacy-openssl-' . uniqid( '', true ) );
		$crypt_obj->set_config( array( 'force_place' => 'database' ) );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_methods',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\LegacyOpenSsl' );
			}
		);

		\CryptForWordPress\Tests\Fixtures\LegacyOpenSsl::$calls = 0;

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertSame( 2, \CryptForWordPress\Tests\Fixtures\LegacyOpenSsl::$calls );

		// the context is available to it as well.
		$bound = $crypt_obj->encrypt( 'Hallo World', 'api_secret' );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $bound, 'api_secret' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $bound ) );
	}
}
