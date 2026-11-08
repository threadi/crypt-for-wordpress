<?php
/**
 * Test that decrypt() copes with anything it may get from the database.
 *
 * A field may hold a value of another method, of another plugin, a value cut
 * off by a too short database column, double encoded, or just garbage. For
 * every such value decrypt() must return an empty string - never something
 * else, and never with a PHP warning, notice or fatal error.
 *
 * The inputs are generated with a fixed seed: every run tests the same ones.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test decrypt() with arbitrary input.
 */
class DecryptRobustness extends CryptForWordPressTests {

	/**
	 * Return the methods and configurations to test.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function methods(): array {
		return array(
			'openssl aes-256-gcm'       => array( 'openssl', array() ),
			'openssl aes-256-cbc'       => array( 'openssl', array( 'cipher_algorithm' => 'aes-256-cbc' ) ),
			'openssl chacha20-poly1305' => array( 'openssl', array( 'cipher_algorithm' => 'chacha20-poly1305' ) ),
			'sodium'                    => array( 'sodium', array() ),
		);
	}

	/**
	 * Return a Crypt object and a method of the given kind with a new key.
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Method_Base}
	 */
	private function get_method( string $method, array $config ): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'decrypt-robustness-' . str_replace( '.', '', uniqid( '', true ) ) );

		$method_obj = 'sodium' === $method
			? new \CryptForWordPress\Methods\Sodium( $crypt_obj )
			: new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );

		if ( ! empty( $config ) ) {
			$method_obj->set_config( $config );
		}

		define( $method_obj->get_constant(), 'sodium' === $method ? base64_encode( random_bytes( 32 ) ) : bin2hex( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$method_obj->init();

		return array( $crypt_obj, $method_obj );
	}

	/**
	 * Return bytes from the seeded generator.
	 *
	 * @param int $length The length.
	 *
	 * @return string
	 */
	private function seeded_bytes( int $length ): string {
		$bytes = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$bytes .= chr( mt_rand( 0, 255 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		}

		return $bytes;
	}

	/**
	 * Return the inputs to test, derived from a real value of the method and
	 * one of another method.
	 *
	 * @param string $real_value  A real value of the method.
	 * @param string $other_value A real value of another method.
	 *
	 * @return array<string,string>
	 */
	private function get_inputs( string $real_value, string $other_value ): array {
		mt_srand( 20261108 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$inputs = array(
			'one space'               => ' ',
			'one character'           => 'a',
			'padding only'            => '====',
			'separators'              => ':::',
			'separators as base64'    => base64_encode( ':::' ),
			'format marker'           => base64_encode( 'v2::' ),
			'format marker and parts' => base64_encode( 'v2:a:b' ),
			'null bytes'              => str_repeat( "\0", 64 ),
			'null bytes as base64'    => base64_encode( str_repeat( "\0", 64 ) ),
			'unicode'                 => 'Hällo Wörld – 🔐',
			'serialized array'        => serialize( array( 'a' => 'b' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			'json'                    => '{"a":"b"}',
			'html'                    => '<script>alert(1)</script>',
			'value of another method' => $other_value,
			'value encoded twice'     => base64_encode( $real_value ),
			'value with appendix'     => $real_value . 'AAAA',
			'value reversed'          => strrev( $real_value ),
			'value in lower case'     => strtolower( $real_value ),
			'very long garbage'       => base64_encode( $this->seeded_bytes( 100000 ) ),
		);

		// the real value, cut off at every length a database column may have.
		foreach ( array( 1, 2, 4, 8, 16, 24, 32, 44, 64, (int) ( strlen( $real_value ) / 2 ), strlen( $real_value ) - 1 ) as $length ) {
			if ( $length < strlen( $real_value ) ) {
				$inputs[ 'value cut off at ' . $length ] = substr( $real_value, 0, $length );
			}
		}

		// the decoded real value with one bit changed, everywhere.
		$decoded = (string) base64_decode( $real_value, true );
		for ( $position = 0; $position < strlen( $decoded ); $position++ ) {
			$changed              = $decoded;
			$changed[ $position ] = chr( ord( $changed[ $position ] ) ^ 0x01 );

			$inputs[ 'bit changed at ' . $position ] = base64_encode( $changed );
		}

		// random bytes, raw and as base64, with and without separators.
		foreach ( array( 1, 7, 12, 13, 24, 25, 32, 33, 48, 64, 100, 255, 1000 ) as $length ) {
			$bytes = $this->seeded_bytes( $length );

			$inputs[ 'random raw ' . $length ]            = $bytes;
			$inputs[ 'random base64 ' . $length ]         = base64_encode( $bytes );
			$inputs[ 'random parts ' . $length ]          = base64_encode( base64_encode( $bytes ) . ':' . base64_encode( strrev( $bytes ) ) . ':' . base64_encode( $bytes ) );
			$inputs[ 'random algorithm byte ' . $length ] = base64_encode( chr( mt_rand( 0, 8 ) ) . $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		}
		// phpcs:enable

		return $inputs;
	}

	/**
	 * Test that every input is decrypted to an empty string, without any
	 * warning or notice - with and without a context.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_arbitrary_input_is_rejected_cleanly( string $method, array $config ): void {
		if ( 'sodium' === $method && ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		$cipher = $config['cipher_algorithm'] ?? '';
		if ( '' !== $cipher && ! in_array( $cipher, openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'This cipher is not available on this server.' );
		}

		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );
		list( , $other_obj )            = $this->get_method( 'sodium' === $method ? 'openssl' : 'sodium', array() );

		$plain_text = 'Hallo World';
		$real_value = $method_obj->encrypt( $plain_text );
		$this->assertNotSame( '', $real_value );

		// collect every warning, notice and deprecation.
		$messages = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$messages ): bool {
				$messages[] = $errstr . ' in ' . $errfile . ':' . $errline;
				return true;
			}
		);

		try {
			foreach ( $this->get_inputs( $real_value, $other_obj->encrypt( $plain_text ) ) as $name => $input ) {
				foreach ( array( '', 'api_secret' ) as $context ) {
					$crypt_obj->clear_errors();

					$result = '' === $context ? $method_obj->decrypt( $input ) : $method_obj->decrypt_with_context( $input, $context );

					// a value derived from the real one may still decode to the
					// very same bytes - base64 ignores missing padding and the
					// unused bits of its last character. Then it is the real
					// value. It must never decrypt to anything else.
					if ( '' === $context && ( str_starts_with( $name, 'value cut off' ) || str_starts_with( $name, 'bit changed' ) ) ) {
						$this->assertContains( $result, array( '', $plain_text ), $name );
						continue;
					}

					$this->assertSame( '', $result, $name . ( '' !== $context ? ' with context' : '' ) );
				}
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $messages );

		// whitespace around the real value - e.g. from a form field - is
		// ignored: the value is decrypted correctly, or not at all.
		$this->assertContains( $method_obj->decrypt( ' ' . $real_value . "\n" ), array( '', $plain_text ) );

		// the real value is still decrypted: nothing above changed the method.
		$this->assertSame( $plain_text, $method_obj->decrypt( $real_value ) );
	}
}
