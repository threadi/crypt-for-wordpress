<?php
/**
 * Test that the method Sodium works without the PHP extension "sodium":
 * with the sodium_compat of WordPress, which WordPress loads if the
 * extension is missing.
 *
 * The extension cannot be unloaded during a test run. So this happens in a
 * PHP process of its own, which loads WordPress with the functions of the
 * extension disabled ("disable_functions") - as on a server without it.
 * The values have to be the same with both: a value written with the
 * extension is decrypted with sodium_compat, and the other way round.
 *
 * In the test run without the extension on GitHub, sodium_compat is used
 * everywhere - then this test compares sodium_compat with itself, and every
 * other test of Sodium runs with sodium_compat as well.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the method Sodium with sodium_compat.
 */
class SodiumCompat extends CryptForWordPressTests {

	/**
	 * The plain text of the values.
	 */
	private const PLAIN = "Hällo Wörld – 🔐\nwith sodium_compat";

	/**
	 * The context of the values.
	 */
	private const CONTEXT = 'api_secret';

	/**
	 * The result of the request without the extension, shared by the tests
	 * of this class: the request loads WordPress, which takes some time.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $result = null;

	/**
	 * The values written with the extension, which the request decrypts.
	 *
	 * @var array<int,array{key:string,context:string,value:string}>
	 */
	private static array $values = array();

	/**
	 * Return a Sodium method of this process, initialized with the given key.
	 *
	 * @param string              $key          The key, as it is stored.
	 * @param array<string,mixed> $config       The configuration of the method.
	 * @param array<string,mixed> $crypt_config The configuration of the Crypt object.
	 *
	 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\Sodium}
	 */
	private function get_method( string $key, array $config = array(), array $crypt_config = array() ): array {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'compat-test-here-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config( $crypt_config ); // @phpstan-ignore argument.type

		$method = new \CryptForWordPress\Methods\Sodium( $crypt_obj );
		$method->set_config( $config );
		define( $method->get_constant(), $key );
		$method->init();

		return array( $crypt_obj, $method );
	}

	/**
	 * Return the result of the request without the extension - run once for
	 * this class.
	 *
	 * @return array<string,mixed>
	 */
	private function get_result(): array {
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			$this->markTestSkipped( 'Running a PHP process is not possible here.' );
		}

		if ( ! self::is_sodium_usable() ) {
			$this->markTestSkipped( 'Sodium is not available in this test environment.' );
		}

		if ( ! file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			$this->markTestSkipped( 'This WordPress does not ship sodium_compat.' );
		}

		// bail if the request has run already.
		if ( null !== self::$result ) {
			return self::$result;
		}

		// values written in this process - with the extension, if it is loaded.
		self::$values = array();
		foreach ( array( array(), array( 'legacy_format' => true ) ) as $crypt_config ) {
			$key = sodium_bin2base64( random_bytes( 32 ), SODIUM_BASE64_VARIANT_ORIGINAL );

			list( , $method ) = $this->get_method( $key, array(), $crypt_config );

			self::$values[] = array(
				'key'     => $key,
				'context' => self::CONTEXT,
				'value'   => $method->encrypt_with_context( self::PLAIN, self::CONTEXT ),
			);
		}

		// the functions of the extension: disabled in the request - together
		// with the ones disabled here already.
		$functions = array_filter( get_defined_functions()['internal'], static fn( string $name ): bool => str_starts_with( $name, 'sodium_' ) );
		$functions = array_unique( array_merge( $functions, array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) ) ) );

		$arguments = array(
			'config'      => CRYPT_FOR_WORDPRESS_TESTS_CONFIG,
			'src'         => dirname( __DIR__, 2 ) . '/src',
			'plugin_file' => self::get_plugin_path(),
			'slug'        => 'sodium-compat-' . str_replace( '.', '', uniqid( '', true ) ),
			'multisite'   => is_multisite(),
			'vectors'     => dirname( __DIR__ ) . '/Data/test-vectors.php',
			'values'      => self::$values,
			'plain'       => self::PLAIN,
			'context'     => self::CONTEXT,
			'hash_types'  => array(
				'sodium_crypto_aead_xchacha20poly1305_ietf_keygen',
				'sodium_crypto_secretbox_keygen',
				'sodium_crypto_auth_keygen',
				'sodium_crypto_generichash_keygen',
				'sodium_crypto_kdf_keygen',
				'random_bytes',
			),
		);

		$command = escapeshellarg( PHP_BINARY );
		if ( ! empty( $functions ) ) {
			$command .= ' -d ' . escapeshellarg( 'disable_functions=' . implode( ',', $functions ) );
		}
		$command .= ' ' . escapeshellarg( dirname( __DIR__ ) . '/Data/sodium-compat-request.php' ) . ' ' . escapeshellarg( (string) wp_json_encode( $arguments ) ) . ' 2>&1';

		$output = shell_exec( $command );

		// the result is the last line: WordPress may print notices before.
		$lines  = explode( "\n", trim( (string) $output ) );
		$result = json_decode( (string) end( $lines ), true );

		$this->assertIsArray( $result, (string) $output );
		self::$result = $result;

		return $result;
	}

	/**
	 * Test that the request really runs with sodium_compat, and that the
	 * method is usable there.
	 *
	 * @return void
	 */
	public function test_method_is_usable_with_sodium_compat(): void {
		$result = $this->get_result();

		$this->assertTrue( $result['compat'], 'The request does not use sodium_compat.' );
		$this->assertTrue( $result['usable'] );
		$this->assertSame( is_multisite(), $result['multisite'] );
	}

	/**
	 * Test that sodium_compat decrypts the test vectors of Sodium - written
	 * with the extension by earlier versions.
	 *
	 * @return void
	 */
	public function test_sodium_compat_decrypts_the_test_vectors(): void {
		$result = $this->get_result();

		$this->assertNotEmpty( $result['vectors'] );
		foreach ( $result['vectors'] as $name => $vector ) {
			$this->assertTrue( $vector['ok'], $name . ': ' . (string) wp_json_encode( $vector['codes'] ) );
			$this->assertSame( array(), $vector['codes'], $name );
		}
	}

	/**
	 * Test that sodium_compat decrypts values written in this process, in
	 * the versioned format and in the one of version 4.2.0.
	 *
	 * @return void
	 */
	public function test_sodium_compat_decrypts_values_of_the_extension(): void {
		$result = $this->get_result();

		$this->assertSame( array_fill( 0, count( self::$values ), self::PLAIN ), $result['from_extension'] );
	}

	/**
	 * Test that values written with sodium_compat are decrypted there and in
	 * this process - with every algorithm both support, in both formats.
	 *
	 * @return void
	 */
	public function test_values_of_sodium_compat_are_decrypted_here(): void {
		$result = $this->get_result();

		$tested = array();
		foreach ( $result['written'] as $written ) {
			$name = $written['algorithm'] . ( $written['legacy_format'] ? ', format of 4.2.0' : '' );

			$this->assertNotSame( '', $written['value'], $name . ': ' . (string) wp_json_encode( $written['codes'] ) );
			$this->assertTrue( $written['round_trip'], $name );

			// bail if this process cannot decrypt this algorithm.
			$function = 'sodium_crypto_aead_' . ( 'xchacha20poly1305' === $written['algorithm'] || 'chacha20poly1305' === $written['algorithm'] ? $written['algorithm'] . '_ietf' : $written['algorithm'] ) . '_decrypt';
			if ( ! function_exists( $function ) || ( 'aes256gcm' === $written['algorithm'] && ! sodium_crypto_aead_aes256gcm_is_available() ) ) {
				continue;
			}

			list( $crypt_obj, $method ) = $this->get_method( $written['key'] );

			$this->assertSame( self::PLAIN, $method->decrypt_with_context( $written['value'], self::CONTEXT ), $name );
			$this->assertFalse( $crypt_obj->has_errors(), $name . ': ' . (string) wp_json_encode( $crypt_obj->get_errors() ) );

			$tested[] = $name;
		}

		$this->assertContains( 'xchacha20poly1305', $tested );
		$this->assertContains( 'xchacha20poly1305, format of 4.2.0', $tested );
	}

	/**
	 * Test that sodium_compat generates a usable key with every hash type,
	 * and that the Crypt object uses it as a plugin does.
	 *
	 * @return void
	 */
	public function test_sodium_compat_generates_keys_of_every_hash_type(): void {
		$result = $this->get_result();

		foreach ( $result['hash_types'] as $hash_type => $entry ) {
			$this->assertSame( 'sodium', $entry['method'], $hash_type );
			$this->assertTrue( $entry['ok'], $hash_type . ': ' . (string) wp_json_encode( $entry['codes'] ) );
			$this->assertSame( array( 'insecure_place_database' ), $entry['codes'], $hash_type );
		}
	}
}
