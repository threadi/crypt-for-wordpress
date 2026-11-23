<?php
/**
 * Test the versioned payload format: every value names the version of its
 * format and the ID of its key, and both are authenticated together with
 * the value - as the site it belongs to, if requested.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the versioned payload format.
 */
class VersionedPayload extends CryptForWordPressTests {

	/**
	 * The first bytes of every value in the versioned format.
	 */
	private const MAGIC = "\xF3CW";

	/**
	 * Length of the header: magic, version, flags, key ID.
	 */
	private const HEADER_LENGTH = 13;

	/**
	 * The blog ID to restore after a test, which has changed it on a single site.
	 *
	 * @var int|null
	 */
	private ?int $blog_id = null;

	/**
	 * Restore what a test has changed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		// restore the blog ID of a single site.
		if ( null !== $this->blog_id ) {
			$GLOBALS['blog_id'] = $this->blog_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->blog_id      = null;
		}

		// leave every site, which a test has switched to.
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
		}

		parent::tear_down();
	}

	/**
	 * Return the methods and ciphers to test the format with.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function methods(): array {
		return array(
			'openssl-aes-256-gcm'       => array( 'openssl', array() ),
			'openssl-chacha20-poly1305' => array( 'openssl', array( 'cipher_algorithm' => 'chacha20-poly1305' ) ),
			'openssl-aes-256-cbc'       => array( 'openssl', array( 'cipher_algorithm' => 'aes-256-cbc' ) ),
			'sodium'                    => array( 'sodium', array() ),
		);
	}

	/**
	 * Return a Crypt object and an initialized method with a known key.
	 *
	 * @param string              $method        The method.
	 * @param array<string,mixed> $config        The configuration of the method.
	 * @param array<string,mixed> $crypt_config  The configuration of the Crypt object.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Method_Base}
	 */
	private function get_method( string $method, array $config = array(), array $crypt_config = array() ): array {
		if ( 'sodium' === $method && ! self::is_sodium_usable() ) {
			$this->markTestSkipped( 'Sodium is not available in this test environment.' );
		}

		$cipher = $config['cipher_algorithm'] ?? '';
		if ( '' !== $cipher && ! in_array( strtolower( $cipher ), openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'This server does not support ' . $cipher . '.' );
		}

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'versioned-' . $method . '-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config( $crypt_config ); // @phpstan-ignore argument.type

		$method_obj = 'sodium' === $method ? new \CryptForWordPress\Methods\Sodium( $crypt_obj ) : new \CryptForWordPress\Methods\OpenSsl( $crypt_obj );
		$method_obj->set_config( $config );

		define( $method_obj->get_constant(), 'sodium' === $method ? base64_encode( random_bytes( 32 ) ) : bin2hex( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$method_obj->init();

		return array( $crypt_obj, $method_obj );
	}

	/**
	 * Return the decoded payload of an encrypted value.
	 *
	 * @param string $encrypted The encrypted value.
	 *
	 * @return string
	 */
	private function decode( string $encrypted ): string {
		return (string) base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Return the encrypted value of a payload.
	 *
	 * @param string $payload The payload.
	 *
	 * @return string
	 */
	private function encode( string $payload ): string {
		return base64_encode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Return the error codes of the Crypt object.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The Crypt object.
	 *
	 * @return array<int,string|int>
	 */
	private function get_codes( \CryptForWordPress\Crypt $crypt_obj ): array {
		$errors = $crypt_obj->get_errors();

		return null === $errors ? array() : $errors->get_error_codes();
	}

	/**
	 * Return the fingerprint remembered for the key of a method: for the
	 * site, or - on a multisite network with a key of the network - for the
	 * network.
	 *
	 * @param \CryptForWordPress\Method_Base $method_obj The method.
	 *
	 * @return string
	 */
	private function get_key_id( \CryptForWordPress\Method_Base $method_obj ): string {
		$key_id = (string) get_option( $method_obj->get_key_id_option_name(), '' );

		return '' !== $key_id ? $key_id : (string) get_site_option( $method_obj->get_key_id_option_name(), '' );
	}

	/**
	 * Switch to another site: on a multisite network to a new site, on a
	 * single site by changing its blog ID for this test.
	 *
	 * @return void
	 */
	private function switch_to_another_site(): void {
		if ( is_multisite() ) {
			switch_to_blog( self::factory()->blog->create() );
			return;
		}

		$this->blog_id      = get_current_blog_id();
		$GLOBALS['blog_id'] = $this->blog_id + 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Return to the site the test began on.
	 *
	 * @return void
	 */
	private function switch_back(): void {
		if ( is_multisite() ) {
			restore_current_blog();
			return;
		}

		$GLOBALS['blog_id'] = (int) $this->blog_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->blog_id      = null;
	}

	/**
	 * Test that a new value begins with the header: magic, version 1, no
	 * flags and the ID of the key, which is the fingerprint remembered for
	 * this installation.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_new_value_begins_with_the_header( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );

		$payload = $this->decode( $method_obj->encrypt( 'Hallo World' ) );

		$this->assertSame( self::MAGIC, substr( $payload, 0, 3 ) );
		$this->assertSame( 1, ord( $payload[3] ) );
		$this->assertSame( 0, ord( $payload[4] ) );
		$this->assertSame( $this->get_key_id( $method_obj ), bin2hex( substr( $payload, 5, 8 ) ) );
		$this->assertSame( array(), $this->get_codes( $crypt_obj ) );
	}

	/**
	 * Test that a value is decrypted with and without context - and only
	 * with the context it has been encrypted with.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_round_trip( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );

		$plain = "Hällo Wörld – 🔐\nwith a second line";

		$this->assertSame( $plain, $method_obj->decrypt( $method_obj->encrypt( $plain ) ) );
		$this->assertSame( $plain, $method_obj->decrypt_with_context( $method_obj->encrypt_with_context( $plain, 'api_secret' ), 'api_secret' ) );
		$this->assertSame( array(), $this->get_codes( $crypt_obj ) );

		// another context, or none, does not decrypt it - and it is no value of another key.
		$encrypted = $method_obj->encrypt_with_context( $plain, 'api_secret' );
		$this->assertSame( '', $method_obj->decrypt_with_context( $encrypted, 'other_field' ) );
		$this->assertSame( '', $method_obj->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_mismatch', $this->get_codes( $crypt_obj ) );
		$this->assertNotEmpty( $this->get_codes( $crypt_obj ) );
	}

	/**
	 * Test that no byte of a value can be changed without the value failing
	 * to decrypt: neither of the header, nor of the value itself.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_every_byte_is_authenticated( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );

		foreach ( array( '', 'api_secret' ) as $context ) {
			$payload = $this->decode( $method_obj->encrypt_with_context( 'Hallo World, this must not leak.', $context ) );

			for ( $i = 0, $length = strlen( $payload ); $i < $length; $i++ ) {
				foreach ( array( 0x01, 0x80 ) as $bit ) {
					$tampered       = $payload;
					$tampered[ $i ] = chr( ord( $tampered[ $i ] ) ^ $bit );

					$crypt_obj->clear_errors();
					$this->assertSame( '', $method_obj->decrypt_with_context( $this->encode( $tampered ), $context ), 'Byte ' . $i . ', bit ' . $bit . ', context "' . $context . '"' );
					$this->assertNotEmpty( $this->get_codes( $crypt_obj ), 'Byte ' . $i . ', bit ' . $bit );
				}
			}
		}

		// the untouched value is still fine.
		$this->assertSame( 'Hallo World', $method_obj->decrypt( $method_obj->encrypt( 'Hallo World' ) ) );
	}

	/**
	 * Test that a value cannot be passed off as one in the format of version
	 * 4.2.0 by removing its header: the header is part of what has been
	 * authenticated.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_value_cannot_be_rewrapped_in_the_format_of_version_4_2_0( string $method, array $config ): void {
		list( , $method_obj ) = $this->get_method( $method, $config );

		foreach ( array( '', 'api_secret' ) as $context ) {
			$payload = $this->decode( $method_obj->encrypt_with_context( 'sk_live_this-is-the-secret', $context ) );
			$rest    = substr( $payload, self::HEADER_LENGTH );

			if ( 'sodium' === $method ) {
				// [algorithm][nonce][ciphertext].
				$forged = sodium_bin2base64( $rest, SODIUM_BASE64_VARIANT_ORIGINAL );
			} elseif ( 'aes-256-cbc' === ( $config['cipher_algorithm'] ?? '' ) ) {
				// "v2:[IV]:[HMAC][ciphertext]".
				$forged = $this->encode( 'v2:' . $this->encode( substr( $rest, 0, 16 ) ) . ':' . $this->encode( substr( $rest, 16 ) ) );
			} else {
				// "[IV]:[tag]:[ciphertext]".
				$forged = $this->encode( $this->encode( substr( $rest, 0, 12 ) ) . ':' . $this->encode( substr( $rest, 12, 16 ) ) . ':' . $this->encode( substr( $rest, 28 ) ) );
			}

			$this->assertSame( '', $method_obj->decrypt_with_context( $forged, $context ), 'Context "' . $context . '"' );
		}
	}

	/**
	 * Test that a value of another key is reported as such - with both key
	 * IDs, and instead of the error of the method.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_value_of_another_key_is_reported( string $method, array $config ): void {
		list( , $method_obj )              = $this->get_method( $method, $config );
		list( $other_crypt, $other_method ) = $this->get_method( $method, $config );

		$encrypted = $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' );

		$this->assertSame( '', $other_method->decrypt_with_context( $encrypted, 'api_secret' ) );
		$this->assertSame( array( 'key_mismatch' ), $this->get_codes( $other_crypt ) );

		$data = (array) $other_crypt->get_errors()?->get_error_data( 'key_mismatch' );
		$this->assertSame( $this->get_key_id( $method_obj ), $data['key_id'] );
		$this->assertSame( $this->get_key_id( $other_method ), $data['current_key_id'] );
		$this->assertSame( $method, $data['method'] );
		$this->assertSame( 'api_secret', $data['context'] );
	}

	/**
	 * Test that a value of a newer version of the format - or with flags this
	 * version does not know - is reported as such.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_value_of_a_newer_format_is_reported( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );

		$payload = $this->decode( $method_obj->encrypt( 'Hallo World' ) );

		foreach ( array( 'version' => array( 3, chr( 2 ) ), 'flags' => array( 4, chr( 0x80 ) ) ) as $name => list( $offset, $byte ) ) {
			$changed            = $payload;
			$changed[ $offset ] = $byte;

			$crypt_obj->clear_errors();
			$this->assertSame( '', $method_obj->decrypt( $this->encode( $changed ) ), $name );
			$this->assertSame( array( 'payload_version_unknown' ), $this->get_codes( $crypt_obj ), $name );
		}

		$this->assertSame( array( 'method' => $method, 'version' => 1, 'flags' => 0x80, 'context' => '' ), $crypt_obj->get_errors()?->get_error_data( 'payload_version_unknown' ) );
	}

	/**
	 * Test that values too short to hold every part are rejected with an
	 * error - never with a PHP warning.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_truncated_value_is_rejected( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config );

		$payload = $this->decode( $method_obj->encrypt( 'Hallo World' ) );

		foreach ( array( self::HEADER_LENGTH, self::HEADER_LENGTH + 1, self::HEADER_LENGTH + 12, self::HEADER_LENGTH + 28, strlen( $payload ) - 1 ) as $length ) {
			$crypt_obj->clear_errors();
			$this->assertSame( '', $method_obj->decrypt( $this->encode( substr( $payload, 0, $length ) ) ), 'Length: ' . $length );
			$this->assertNotEmpty( $this->get_codes( $crypt_obj ), 'Length: ' . $length );
		}
	}

	/**
	 * Test that the Crypt object writes the versioned format by default.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_crypt_writes_the_versioned_format_by_default( string $method, array $config ): void {
		list( , $method_obj ) = $this->get_method( $method, $config );
		unset( $method_obj );

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'versioned-crypt-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config(
			array(
				'force_method' => $method,
				'force_place'  => 'database',
				$method        => $config,
			)
		);

		$encrypted = $crypt_obj->encrypt( 'Hallo World', 'api_secret' );

		$this->assertStringStartsWith( self::MAGIC, $this->decode( $encrypted ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt_or_error( $encrypted, 'api_secret' ) );
	}

	/**
	 * Test that 'legacy_format' => true writes values in the format of
	 * version 4.2.0, and that values of both formats are read with and
	 * without this setting.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_legacy_format( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config, array( 'legacy_format' => true ) );

		$legacy = $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' );
		$this->assertStringStartsNotWith( self::MAGIC, $this->decode( $legacy ) );
		if ( 'sodium' === $method ) {
			$this->assertSame( 3, ord( $this->decode( $legacy )[0] ) );
		} else {
			$this->assertCount( 3, explode( ':', $this->decode( $legacy ) ) );
		}

		// switch to the versioned format.
		$crypt_obj->set_config( array() );
		$versioned = $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' );
		$this->assertStringStartsWith( self::MAGIC, $this->decode( $versioned ) );

		// both are read with both settings.
		foreach ( array( array(), array( 'legacy_format' => true ) ) as $crypt_config ) {
			$crypt_obj->set_config( $crypt_config ); // @phpstan-ignore argument.type
			$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $legacy, 'api_secret' ) );
			$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $versioned, 'api_secret' ) );
		}

		$this->assertSame( array(), $this->get_codes( $crypt_obj ) );
	}

	/**
	 * Test that a value is never encrypted without the binding to its site,
	 * if it is requested: the format of version 4.2.0 cannot hold it.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_bind_site_needs_the_versioned_format( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method(
			$method,
			$config,
			array(
				'legacy_format' => true,
				'bind_site'     => true,
			)
		);

		$this->assertSame( '', $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' ) );
		$this->assertSame( array( 'bind_site_not_possible' ), $this->get_codes( $crypt_obj ) );
		$this->assertSame( array( 'method' => $method, 'context' => 'api_secret' ), $crypt_obj->get_errors()?->get_error_data( 'bind_site_not_possible' ) );
	}

	/**
	 * Test that a value bound to its site is decrypted on this site only -
	 * also after the setting has been removed. A value, which is not bound,
	 * is decrypted on every site with the same key.
	 *
	 * @dataProvider methods
	 *
	 * @param string              $method The method.
	 * @param array<string,mixed> $config The configuration of the method.
	 *
	 * @return void
	 */
	public function test_bind_site( string $method, array $config ): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( $method, $config, array( 'bind_site' => true ) );

		$bound = $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' );
		$this->assertSame( 1, ord( $this->decode( $bound )[4] ) );

		// a value, which is not bound to its site.
		$crypt_obj->set_config( array() );
		$unbound = $method_obj->encrypt_with_context( 'Hallo World', 'api_secret' );
		$this->assertSame( 0, ord( $this->decode( $unbound )[4] ) );

		// on this site both are decrypted - whatever the setting is.
		foreach ( array( array(), array( 'bind_site' => true ) ) as $crypt_config ) {
			$crypt_obj->set_config( $crypt_config ); // @phpstan-ignore argument.type
			$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $bound, 'api_secret' ) );
			$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $unbound, 'api_secret' ) );
		}
		$this->assertSame( array(), $this->get_codes( $crypt_obj ) );

		// on another site only the value, which is not bound.
		$this->switch_to_another_site();
		$this->assertSame( '', $method_obj->decrypt_with_context( $bound, 'api_secret' ) );
		$this->assertNotContains( 'key_mismatch', $this->get_codes( $crypt_obj ) );
		$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $unbound, 'api_secret' ) );

		// back on its site it is decrypted again.
		$this->switch_back();
		$this->assertSame( 'Hallo World', $method_obj->decrypt_with_context( $bound, 'api_secret' ) );
	}

	/**
	 * Test that a value in the very first format of OpenSSL for ciphers
	 * without AEAD stays readable, if its random IV begins like the header
	 * of the versioned format - which happens for one of about 16 million.
	 *
	 * @return void
	 */
	public function test_first_format_value_beginning_like_the_header_stays_readable(): void {
		list( $crypt_obj, $method_obj ) = $this->get_method( 'openssl', array( 'cipher_algorithm' => 'aes-256-cbc' ) );

		// the very first format: [IV][HMAC][ciphertext], with the keys derived for it.
		$master   = (string) hex2bin( $method_obj->get_hash() );
		$enc_key  = hash_hkdf( 'sha256', $master, 32, 'encryption' );
		$hmac_key = hash_hkdf( 'sha256', $master, 32, 'authentication' );

		// an IV, which begins like a header of version 1 without flags.
		$iv  = self::MAGIC . chr( 1 ) . chr( 0 ) . random_bytes( 11 );
		$raw = (string) openssl_encrypt( 'an old value', 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );

		$value = $this->encode( $iv . hash_hmac( 'sha256', $raw, $hmac_key, true ) . $raw );

		$this->assertSame( 'an old value', $method_obj->decrypt( $value ) );
		$this->assertSame( array(), $this->get_codes( $crypt_obj ) );

		// it has never been bound to a context.
		$this->assertSame( '', $method_obj->decrypt_with_context( $value, 'api_secret' ) );
	}

	/**
	 * Test that decrypt_or_error() names a value of another key as the
	 * reason - e.g. of another plugin, or of a key, which has been replaced.
	 *
	 * @return void
	 */
	public function test_decrypt_or_error_names_a_value_of_another_key(): void {
		$crypt_objs = array();
		foreach ( array( 'first', 'second' ) as $name ) {
			$crypt_objs[ $name ] = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$crypt_objs[ $name ]->set_slug( 'versioned-or-error-' . $name . '-' . str_replace( '.', '', uniqid( '', true ) ) );
			$crypt_objs[ $name ]->set_config( array( 'force_place' => 'database' ) );
		}

		$result = $crypt_objs['second']->decrypt_or_error( $crypt_objs['first']->encrypt( 'Hallo World' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
		$this->assertContains( 'key_mismatch', $result->get_error_codes() );
	}
}
