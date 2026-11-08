<?php
/**
 * Test that a key saved in one request is loaded in the next one - and that
 * the values encrypted with it stay readable.
 *
 * During a test run every constant stays defined: a test cannot show that a
 * key, which has been written into the wp-config.php, a Must-Use plugin, a
 * custom file, the database or the Secrets API, is really found again by the
 * next request. So every request runs in a PHP process of its own, which
 * loads WordPress with the database of the test environment - like a real
 * request. Its changes are committed: they are removed again afterward.
 *
 * In a multisite run, the processes load WordPress as the main site of the
 * network: the fingerprint of a key in a file is saved for the network
 * then, the one of a key in the database for the site.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the key across requests.
 */
class NextRequest extends CryptForWordPressTests {

	/**
	 * The files created by the current test.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	/**
	 * The arguments of the current test, for the clean up.
	 *
	 * @var array<string,mixed>
	 */
	private array $arguments = array();

	/**
	 * Remove what the requests saved.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( ! empty( $this->arguments ) ) {
			$this->run_request( 'cleanup', $this->arguments );
		}
		$this->arguments = array();

		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();

		parent::tear_down();
	}

	/**
	 * Return the combinations of method and place to test.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function combinations(): array {
		$list = array();
		foreach ( array( 'openssl', 'sodium' ) as $method ) {
			foreach ( array( 'wpconfig', 'muplugin', 'customfile', 'database', 'secrets_api' ) as $place ) {
				$list[ $method . ' in ' . $place ] = array( $method, $place );
			}
		}

		return $list;
	}

	/**
	 * Run a request in a PHP process of its own and return its result.
	 *
	 * @param string              $mode      "write", "read" or "cleanup".
	 * @param array<string,mixed> $arguments The arguments of the request.
	 *
	 * @return array<string,mixed>
	 */
	private function run_request( string $mode, array $arguments ): array {
		$arguments['mode'] = $mode;

		$script = dirname( __DIR__ ) . '/Data/next-request.php';
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( (string) wp_json_encode( $arguments ) ) . ' 2>&1' );

		// the result is the last line: WordPress may print notices before.
		$lines  = explode( "\n", trim( (string) $output ) );
		$result = json_decode( (string) end( $lines ), true );

		$this->assertIsArray( $result, (string) $output );

		return $result;
	}

	/**
	 * Test that the key saved by one request is used by the next one.
	 *
	 * @dataProvider combinations
	 *
	 * @param string $method The method.
	 * @param string $place  The place.
	 *
	 * @return void
	 */
	public function test_key_is_found_by_the_next_request( string $method, string $place ): void {
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			$this->markTestSkipped( 'Running a PHP process is not possible here.' );
		}

		if ( 'secrets_api' === $place && ! function_exists( 'wp_get_secret' ) ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}

		if ( 'muplugin' === $place && ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_writable( is_dir( WPMU_PLUGIN_DIR ) ? WPMU_PLUGIN_DIR : dirname( WPMU_PLUGIN_DIR ) ) ) ) {
			$this->markTestSkipped( 'The "must-use"-plugin directory is not writable here.' );
		}

		$slug = 'next-request-' . $method . '-' . str_replace( array( '.', '_' ), '-', strtolower( uniqid( '', true ) . '-' . $place ) );

		// the files of this test.
		$wp_config   = sys_get_temp_dir() . '/crypt-for-wordpress-next-request-wp-config-' . $slug . '.php';
		$custom_file = sys_get_temp_dir() . '/crypt-for-wordpress-next-request-key-' . $slug . '.php';
		file_put_contents( $wp_config, "<?php\n/* That's all, stop editing! Happy publishing. */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n" );
		$this->files = array( $wp_config, $wp_config . '.lock', $custom_file );

		$this->arguments = array(
			'config'      => CRYPT_FOR_WORDPRESS_TESTS_CONFIG,
			'src'         => dirname( __DIR__, 2 ) . '/src',
			'plugin_file' => self::get_plugin_path(),
			'slug'        => $slug,
			'method'      => $method,
			'place'       => $place,
			'wp_config'   => $wp_config,
			'custom_file' => $custom_file,
			'plain'       => "Hällo Wörld\nnext request",
			'context'     => 'api_secret',
			'multisite'   => is_multisite(),
		);

		// the first request: a key is generated and saved, a value encrypted.
		$written = $this->run_request( 'write', $this->arguments );
		$this->assertSame( is_multisite(), $written['multisite'] );
		$this->assertNotSame( '', $written['value'], (string) wp_json_encode( $written ) );
		$this->assertNotContains( 'key_not_saved', $written['codes'] );
		$this->assertTrue( $written['saved_in_place'], (string) wp_json_encode( $written ) );

		$this->arguments['value']            = $written['value'];
		$this->arguments['root_key_existed'] = $written['root_key_existed'];

		// the next request: the key is loaded from its place, the value decrypted.
		$read = $this->run_request( 'read', $this->arguments );
		$this->assertSame( $this->arguments['plain'], $read['plain'], (string) wp_json_encode( $read ) );
		$this->assertSame( $written['key_id'], $read['key_id'] );

		foreach ( array( 'key_missing', 'key_changed', 'key_not_saved', 'key_unavailable', 'key_in_inactive_place' ) as $code ) {
			$this->assertNotContains( $code, $read['codes'] );
		}

		// and once more: still the same key, nothing generated in between.
		$again = $this->run_request( 'read', $this->arguments );
		$this->assertSame( $this->arguments['plain'], $again['plain'] );
		$this->assertSame( $written['key_id'], $again['key_id'] );
	}
}
