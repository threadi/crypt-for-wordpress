<?php
/**
 * Test that values written by earlier versions of this package can still be
 * decrypted.
 *
 * The values are in tests/Data/test-vectors.php, with the fixed keys they
 * have been encrypted with. A change of a method, which fails one of these
 * tests, would make existing data of websites unreadable.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the values of earlier versions.
 */
class TestVectors extends CryptForWordPressTests {

	/**
	 * Return the test vectors.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function vectors(): array {
		$vectors = require dirname( __DIR__ ) . '/Data/test-vectors.php';

		$list = array();
		foreach ( $vectors as $name => $vector ) {
			$list[ $name ] = array( $vector );
		}

		return $list;
	}

	/**
	 * Return the test vectors, which are bound to a context.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function context_vectors(): array {
		return array_filter(
			$this->vectors(),
			static fn( array $entry ): bool => '' !== $entry[0]['context']
		);
	}

	/**
	 * Return a method, initialized with the key and the configuration of the vector.
	 *
	 * @param array<string,mixed> $vector The test vector.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Method_Base}
	 */
	private function get_method( array $vector ): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'test-vector-' . str_replace( '.', '', uniqid( '', true ) ) );

		$method = 'sodium' === $vector['method']
			? new \CryptForWordPress\Methods\Sodium( $crypt_obj )
			: new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );

		if ( ! empty( $vector['config'] ) ) {
			$method->set_config( $vector['config'] );
		}

		define( $method->get_constant(), $vector['key'] );
		$method->init();

		return array( $crypt_obj, $method );
	}

	/**
	 * Return whether this server can decrypt the vector.
	 *
	 * @param array<string,mixed> $vector The test vector.
	 *
	 * @return bool
	 */
	private function is_supported( array $vector ): bool {
		if ( 'sodium' === $vector['method'] && ! extension_loaded( 'sodium' ) ) {
			return false;
		}

		if ( 'aes256gcm' === $vector['requires'] ) {
			return function_exists( 'sodium_crypto_aead_aes256gcm_is_available' ) && sodium_crypto_aead_aes256gcm_is_available();
		}

		if ( '' !== $vector['requires'] && ! function_exists( $vector['requires'] ) ) {
			return false;
		}

		$cipher = $vector['config']['cipher_algorithm'] ?? '';

		return '' === $cipher || in_array( strtolower( $cipher ), openssl_get_cipher_methods(), true );
	}

	/**
	 * Test that every vector is decrypted to its plain text - without any error.
	 *
	 * @dataProvider vectors
	 *
	 * @param array<string,mixed> $vector The test vector.
	 *
	 * @return void
	 */
	public function test_vector_is_decrypted( array $vector ): void {
		if ( ! $this->is_supported( $vector ) ) {
			$this->markTestSkipped( 'This server cannot decrypt this vector.' );
		}

		list( $crypt_obj, $method ) = $this->get_method( $vector );

		$this->assertSame( $vector['plain'], $method->decrypt_with_context( $vector['value'], $vector['context'] ) );
		$this->assertFalse( $crypt_obj->has_errors(), (string) wp_json_encode( $crypt_obj->get_errors() ) );
	}

	/**
	 * Test that a vector bound to a context is not decrypted without it, or
	 * with another one.
	 *
	 * @dataProvider context_vectors
	 *
	 * @param array<string,mixed> $vector The test vector.
	 *
	 * @return void
	 */
	public function test_vector_with_context_needs_the_context( array $vector ): void {
		if ( ! $this->is_supported( $vector ) ) {
			$this->markTestSkipped( 'This server cannot decrypt this vector.' );
		}

		list( , $method ) = $this->get_method( $vector );

		$this->assertSame( '', $method->decrypt( $vector['value'] ) );
		$this->assertSame( '', $method->decrypt_with_context( $vector['value'], 'another_field' ) );
	}

	/**
	 * Test that a vector is not decrypted with another key.
	 *
	 * @dataProvider vectors
	 *
	 * @param array<string,mixed> $vector The test vector.
	 *
	 * @return void
	 */
	public function test_vector_needs_its_key( array $vector ): void {
		if ( ! $this->is_supported( $vector ) ) {
			$this->markTestSkipped( 'This server cannot decrypt this vector.' );
		}

		// another key in the same format.
		$vector['key'] = 'sodium' === $vector['method'] || ( $vector['config']['hash_type'] ?? '' ) === 'hash_pbkdf2'
			? base64_encode( random_bytes( 32 ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			: bin2hex( random_bytes( 32 ) );

		list( , $method ) = $this->get_method( $vector );

		$this->assertSame( '', $method->decrypt_with_context( $vector['value'], $vector['context'] ) );
	}
}
