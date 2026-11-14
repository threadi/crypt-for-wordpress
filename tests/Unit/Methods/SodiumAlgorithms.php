<?php
/**
 * Test that the Sodium method decrypts values of every algorithm it knows.
 *
 * A server only writes values with the best algorithm it supports: AEGIS-256,
 * AES-256-GCM, XChaCha20-Poly1305 or ChaCha20-Poly1305. After a move to
 * another server, values of the other algorithms must still be decrypted -
 * or report clearly, why they cannot. A test run on one server only ever
 * writes one of them, so the others are built here directly with libsodium.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Methods;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the algorithms of the Sodium method.
 */
class SodiumAlgorithms extends CryptForWordPressTests {

	/**
	 * The raw key of the current test.
	 *
	 * @var string
	 */
	private string $key = '';

	/**
	 * Return a Crypt object and a Sodium method, initialized with a known key.
	 *
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\Sodium}
	 */
	private function get_initialized_method( array $config = array() ): array {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sodium-algorithms-' . uniqid( '', true ) );

		$method = new \CryptForWordPress\Methods\Sodium( $crypt_obj );
		$method->set_config( $config );
		$this->key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
		define( $method->get_constant(), sodium_bin2base64( $this->key, SODIUM_BASE64_VARIANT_ORIGINAL ) );
		$method->init();

		return array( $crypt_obj, $method );
	}

	/**
	 * Return the algorithm byte of an encrypted value.
	 *
	 * @param string $value The encrypted value.
	 *
	 * @return int
	 */
	private function get_algorithm_of( string $value ): int {
		return ord( sodium_base642bin( $value, SODIUM_BASE64_VARIANT_ORIGINAL )[0] );
	}

	/**
	 * Return the algorithms the method knows: id, nonce length, encrypt function.
	 *
	 * @return array<string,array{0:int,1:int,2:string}>
	 */
	public function algorithms(): array {
		return array(
			'aegis256'          => array( 1, 32, 'sodium_crypto_aead_aegis256_encrypt' ),
			'aes256gcm'         => array( 2, 12, 'sodium_crypto_aead_aes256gcm_encrypt' ),
			'xchacha20poly1305' => array( 3, 24, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ),
			'chacha20poly1305'  => array( 4, 12, 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt' ),
		);
	}

	/**
	 * Return whether the given algorithm can be used on this server.
	 *
	 * @param int    $algorithm The id of the algorithm.
	 * @param string $function  The encrypt function.
	 *
	 * @return bool
	 */
	private function is_available( int $algorithm, string $function ): bool {
		if ( ! function_exists( $function ) ) {
			return false;
		}

		return 2 !== $algorithm || sodium_crypto_aead_aes256gcm_is_available();
	}

	/**
	 * Return a value in the format the method writes: [algorithm][nonce][ciphertext].
	 *
	 * @param int    $algorithm    The id of the algorithm.
	 * @param int    $nonce_length The length of the nonce.
	 * @param string $function     The encrypt function.
	 * @param string $plain_text   The plain text.
	 * @param string $context      The context.
	 *
	 * @return string
	 */
	private function build_value( int $algorithm, int $nonce_length, string $function, string $plain_text, string $context ): string {
		$nonce = random_bytes( $nonce_length );

		return sodium_bin2base64( chr( $algorithm ) . $nonce . $function( $plain_text, $context, $nonce, $this->key ), SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Test the value of every algorithm the method knows:
	 *
	 * - if this server supports it, a value written by another server is
	 *   decrypted - with its context, and only with it;
	 * - if not, it is reported why - instead of failing silently.
	 *
	 * @dataProvider algorithms
	 *
	 * @param int    $algorithm    The id of the algorithm.
	 * @param int    $nonce_length The length of the nonce.
	 * @param string $function     The encrypt function.
	 *
	 * @return void
	 */
	public function test_value_of_every_algorithm( int $algorithm, int $nonce_length, string $function ): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		// supported here: the value is decrypted.
		if ( $this->is_available( $algorithm, $function ) ) {
			$value = $this->build_value( $algorithm, $nonce_length, $function, 'Hallo World', '' );
			$this->assertSame( 'Hallo World', $method->decrypt( $value ) );

			// with a context, which is authenticated as well.
			$value = $this->build_value( $algorithm, $nonce_length, $function, 'Hallo World', 'api_secret' );
			$this->assertSame( 'Hallo World', $method->decrypt_with_context( $value, 'api_secret' ) );
			$this->assertSame( '', $method->decrypt_with_context( $value, 'another_field' ) );

			$this->assertNotContains( 'sodium_algorithm_unknown', (array) $crypt_obj->get_errors()?->get_error_codes() );

			return;
		}

		// not supported here: a value as another server wrote it - the
		// ciphertext cannot be built here - is reported with the reason.
		$value = sodium_bin2base64( chr( $algorithm ) . random_bytes( $nonce_length ) . random_bytes( 32 ), SODIUM_BASE64_VARIANT_ORIGINAL );

		$this->assertSame( '', $method->decrypt( $value ) );
		$this->assertContains( 'sodium_decrypt_error', $crypt_obj->get_errors()->get_error_codes() );

		// AES-256-GCM can be missing on this server while its function exists:
		// without hardware support, libsodium refuses it.
		$messages = implode( ' ', $crypt_obj->get_errors()->get_error_messages( 'sodium_decrypt_error' ) );
		if ( ! function_exists( str_replace( '_encrypt', '_decrypt', $function ) ) ) {
			$this->assertStringContainsString( 'not supported by this server', $messages );
		}
	}

	/**
	 * Test that the method writes new values with XChaCha20-Poly1305 without
	 * any configuration - whatever else this server supports. Only then a
	 * value stays readable after a move to another server.
	 *
	 * @return void
	 */
	public function test_encrypt_uses_xchacha20poly1305_by_default(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$encrypted = $method->encrypt( 'Hallo World' );

		$this->assertSame( 3, $this->get_algorithm_of( $encrypted ) );
		$this->assertSame( 'Hallo World', $method->decrypt( $encrypted ) );
		$this->assertFalse( $crypt_obj->has_errors() );
	}

	/**
	 * Test that the Crypt object writes new values with XChaCha20-Poly1305
	 * without any configuration.
	 *
	 * @return void
	 */
	public function test_crypt_uses_xchacha20poly1305_by_default(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sodium-algorithms-default-' . uniqid( '', true ) );
		$crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'database',
			)
		);

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertSame( 3, $this->get_algorithm_of( $encrypted ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
	}

	/**
	 * Test that values of the faster algorithms, which versions up to 4.2.0
	 * wrote on servers supporting them, stay readable with the default
	 * configuration.
	 *
	 * @return void
	 */
	public function test_values_of_older_versions_stay_readable(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$tested = 0;
		foreach ( array( 'aegis256', 'aes256gcm' ) as $name ) {
			list( $algorithm, $nonce_length, $function ) = $this->algorithms()[ $name ];

			// bail if this server cannot write such a value.
			if ( ! $this->is_available( $algorithm, $function ) ) {
				continue;
			}

			$value = $this->build_value( $algorithm, $nonce_length, $function, 'Hallo World', '' );
			$this->assertSame( 'Hallo World', $method->decrypt( $value ), $name );

			$value = $this->build_value( $algorithm, $nonce_length, $function, 'Hallo World', 'api_secret' );
			$this->assertSame( 'Hallo World', $method->decrypt_with_context( $value, 'api_secret' ), $name );

			++$tested;
		}

		if ( 0 === $tested ) {
			$this->markTestSkipped( 'This server supports neither AEGIS-256 nor AES-256-GCM.' );
		}

		$this->assertFalse( $crypt_obj->has_errors() );
	}

	/**
	 * Test that 'algorithm' => 'auto' writes values with the fastest
	 * algorithm this server supports, as versions up to 4.2.0 did.
	 *
	 * @return void
	 */
	public function test_auto_uses_the_fastest_supported_algorithm(): void {
		list( , $method ) = $this->get_initialized_method( array( 'algorithm' => 'auto' ) );

		$expected = 3;
		if ( function_exists( 'sodium_crypto_aead_aegis256_encrypt' ) ) {
			$expected = 1;
		} elseif ( function_exists( 'sodium_crypto_aead_aes256gcm_is_available' ) && sodium_crypto_aead_aes256gcm_is_available() ) {
			$expected = 2;
		}

		$encrypted = $method->encrypt( 'Hallo World' );
		$this->assertSame( $expected, $this->get_algorithm_of( $encrypted ) );
		$this->assertSame( 'Hallo World', $method->decrypt( $encrypted ) );
	}

	/**
	 * Test that a configured algorithm is used, if this server supports it.
	 * Otherwise XChaCha20-Poly1305 is used, and this is reported once.
	 *
	 * @dataProvider algorithms
	 *
	 * @param int    $algorithm    The id of the algorithm.
	 * @param int    $nonce_length The length of the nonce.
	 * @param string $function     The encrypt function.
	 *
	 * @return void
	 */
	public function test_configured_algorithm_is_used( int $algorithm, int $nonce_length, string $function ): void {
		$name                       = (string) array_search( array( $algorithm, $nonce_length, $function ), $this->algorithms(), true );
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'algorithm' => strtoupper( $name ) ) );

		$encrypted = $method->encrypt( 'Hallo World' );
		$this->assertSame( 'Hallo World', $method->decrypt( $encrypted ) );

		// supported here: it is used.
		if ( $this->is_available( $algorithm, $function ) ) {
			$this->assertSame( $algorithm, $this->get_algorithm_of( $encrypted ) );
			$this->assertFalse( $crypt_obj->has_errors() );

			return;
		}

		// not supported here: the default is used, and it is reported once.
		$method->encrypt( 'Hallo World' );
		$this->assertSame( 3, $this->get_algorithm_of( $encrypted ) );
		$this->assertCount( 1, $crypt_obj->get_errors()->get_error_messages( 'sodium_algorithm_not_available' ) );
	}

	/**
	 * Test that an unknown algorithm is reported, and the default is used.
	 *
	 * @return void
	 */
	public function test_unknown_algorithm_uses_the_default(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method( array( 'algorithm' => 'rot13' ) );

		$encrypted = $method->encrypt( 'Hallo World' );

		$this->assertSame( 3, $this->get_algorithm_of( $encrypted ) );
		$this->assertSame( 'Hallo World', $method->decrypt( $encrypted ) );
		$this->assertSame( array( 'algorithm' => 'rot13' ), $crypt_obj->get_errors()->get_error_data( 'sodium_algorithm_not_available' ) );
	}

	/**
	 * Test that a payload too short for the algorithm byte and a nonce is rejected.
	 *
	 * @return void
	 */
	public function test_payload_of_one_byte_is_rejected(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();

		$this->assertSame( '', $method->decrypt( sodium_bin2base64( chr( 3 ), SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
		$this->assertContains( 'sodium_payload_not_set', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that nothing is encrypted or decrypted without a slug.
	 *
	 * @return void
	 */
	public function test_empty_slug_is_rejected(): void {
		list( $crypt_obj, $method ) = $this->get_initialized_method();
		$encrypted                  = $method->encrypt( 'Hallo World' );

		$crypt_obj->set_slug( '' );

		$this->assertSame( '', $method->encrypt( 'Hallo World' ) );
		$this->assertSame( '', $method->decrypt( $encrypted ) );
		$this->assertCount( 2, $crypt_obj->get_errors()->get_error_messages( 'sodium_slug_missing' ) );
	}

	/**
	 * Return the configurable types of the generated key.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function hash_types(): array {
		return array(
			'sodium_crypto_aead_xchacha20poly1305_ietf_keygen' => array( 'sodium_crypto_aead_xchacha20poly1305_ietf_keygen' ),
			'sodium_crypto_secretbox_keygen'                   => array( 'sodium_crypto_secretbox_keygen' ),
			'sodium_crypto_auth_keygen'                        => array( 'sodium_crypto_auth_keygen' ),
			'sodium_crypto_generichash_keygen'                 => array( 'sodium_crypto_generichash_keygen' ),
			'sodium_crypto_kdf_keygen'                         => array( 'sodium_crypto_kdf_keygen' ),
			'random_bytes'                                     => array( 'random_bytes' ),
		);
	}

	/**
	 * Test that every configurable type of key generates a usable key of 32
	 * bytes, which is saved and found again.
	 *
	 * @dataProvider hash_types
	 *
	 * @param string $hash_type The type of the key.
	 *
	 * @return void
	 */
	public function test_every_hash_type_generates_a_usable_key( string $hash_type ): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sodium-hash-type-' . uniqid( '', true ) );
		$crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'database',
				'sodium'       => array( 'hash_type' => $hash_type ),
			)
		);

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		// the key in the database: 32 bytes.
		$stored_key = (string) get_option( $crypt_obj->get_slug() . '-hash' );
		$this->assertSame( 32, strlen( sodium_base642bin( $stored_key, SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
	}
}
