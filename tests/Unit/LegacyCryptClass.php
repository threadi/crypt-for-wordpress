<?php
/**
 * Test that the methods work with the Crypt class of version 3.1.0.
 *
 * Several plugins of a website may ship this package, in different versions.
 * PHP loads each class once - so the Crypt class of version 3.1.0, shipped by
 * a plugin which has not been updated yet, may end up working with the
 * methods of this version. That Crypt class has no get_stored_keys(),
 * is_saved_in_place() and is_stored_key_unavailable(): the methods do these
 * things themselves then, with what that Crypt class offers.
 *
 * Two Crypt classes cannot be loaded during one test run, so this happens in
 * a PHP process of its own: with a Crypt class offering only the API of
 * version 3.1.0, the methods and places of this version, and the few
 * functions of WordPress they need - keeping the options in memory.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the methods with the Crypt class of version 3.1.0.
 */
class LegacyCryptClass extends CryptForWordPressTests {

	/**
	 * The script run in the other process.
	 *
	 * @var string
	 */
	private string $script_path = '';

	/**
	 * Remove the script.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->script_path && file_exists( $this->script_path ) ) {
			unlink( $this->script_path );
		}
		$this->script_path = '';

		parent::tear_down();
	}

	/**
	 * Run a scenario in a PHP process of its own and return its result.
	 *
	 * @param string $scenario The scenario: "inactive_place", "new_key" or "unavailable_key".
	 *
	 * @return array<string,mixed>
	 */
	private function run_scenario( string $scenario ): array {
		// bail if this hosting does not allow running a process.
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			$this->markTestSkipped( 'Running a PHP process is not possible here.' );
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'The sodium extension is not available in this test environment.' );
		}

		$script = <<<'PHP'
<?php
namespace {
	list( , $src_path, $scenario ) = $argv;

	define( 'ABSPATH', '/' );

	// the options of WordPress, in memory.
	$GLOBALS['options'] = array();
	function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( isset( $GLOBALS['options'][ $name ] ) ) {
			return false;
		}
		$GLOBALS['options'][ $name ] = $value;
		return true;
	}
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
	function delete_option( $name ) { unset( $GLOBALS['options'][ $name ] ); return true; }
	function get_site_option( $name, $default = false ) { return get_option( $name, $default ); }
	function add_site_option( $name, $value ) { return add_option( $name, $value ); }
	function update_site_option( $name, $value ) { return update_option( $name, $value ); }
	function delete_site_option( $name ) { return delete_option( $name ); }
	function is_multisite() { return false; }
	function get_current_blog_id() { return 1; }
	function apply_filters( $hook, $value ) { return $value; }
	function wp_kses_post( $value ) { return $value; }
}

namespace CryptForWordPress {
	/**
	 * The API of the Crypt class of version 3.1.0: no get_stored_keys(),
	 * no is_saved_in_place(), no is_stored_key_unavailable().
	 */
	class Crypt {
		public array $codes = array();
		public array $places = array();
		private array $configuration = array( 'force_method' => 'sodium' );

		public function get_slug(): string { return 'legacy-crypt'; }
		public function get_config(): array { return $this->configuration; }
		public function get_method_config( string $name ): array { return array(); }
		public function add_error( string $code, string $message, array $data = array() ): void { $this->codes[] = $code; }
		public function get_places_as_objects(): array { return $this->places; }
		public function get_place(): false|Place_Base {
			foreach ( $this->places as $place ) {
				if ( $place->is_usable() ) {
					return $place;
				}
			}
			return false;
		}
		public function save_in_place( string $constant, string $hash ): void {
			$place = $this->get_place();
			$place->set_constant( $constant );
			$place->save( $hash );
		}

		// what get_method() of version 3.1.0 does.
		public function get_method(): Methods\Sodium {
			$method = new Methods\Sodium( $this );
			$place  = $this->get_place();
			$place->set_constant( $method->get_constant() );
			$place->load();
			$method->set_place( $place );
			$method->init();
			return $method;
		}
	}
}

namespace {
	foreach ( array( 'Helper.php', 'Method_Base.php', 'Place_Base.php', 'Methods/Sodium.php', 'Places/Database.php' ) as $file ) {
		require $src_path . '/' . $file;
	}

	// a place, which has been the active one before and holds a key.
	class Holding_Place extends CryptForWordPress\Place_Base {
		public static string $key = '';
		protected string $name = 'holding';
		public function __construct( CryptForWordPress\Crypt $crypt_obj ) { $this->crypt_obj = $crypt_obj; }
		public function is_usable(): bool { return false; }
		public function get_stored_key(): string { return self::$key; }
	}

	// a place, which holds a key it cannot hand over right now.
	class Unavailable_Place extends Holding_Place {
		protected string $name = 'unavailable';
		public function get_stored_key(): string { return ''; }
		public function is_key_unavailable(): bool { return true; }
	}

	$crypt = new CryptForWordPress\Crypt();
	$key   = base64_encode( random_bytes( 32 ) );

	switch ( $scenario ) {
		case 'inactive_place':
			// the database is the active place and empty, the key is in another place.
			Holding_Place::$key = $key;
			$crypt->places      = array( new CryptForWordPress\Places\Database( $crypt ), new Holding_Place( $crypt ) );
			break;
		case 'new_key':
			// nothing anywhere: a new key is generated and saved in the database.
			$crypt->places = array( new CryptForWordPress\Places\Database( $crypt ) );
			break;
		case 'unavailable_key':
			// a key has been used before, and a place holds it, but cannot hand it over.
			$GLOBALS['options']['legacy-crypt_crypt_key_id_sodium'] = 'abcdef0123456789';
			$crypt->places = array( new CryptForWordPress\Places\Database( $crypt ), new Unavailable_Place( $crypt ) );
			break;
	}

	$method    = $crypt->get_method();
	$encrypted = $method->encrypt( 'Hallo World' );

	echo json_encode(
		array(
			'encrypted'  => '' !== $encrypted,
			'decrypted'  => '' !== $encrypted ? $method->decrypt( $encrypted ) : '',
			'uses_key'   => defined( $method->get_constant() ) && constant( $method->get_constant() ) === $key,
			'stored_key' => (string) ( $GLOBALS['options']['legacy-crypt-hash'] ?? '' ),
			'key_id'     => (string) ( $GLOBALS['options']['legacy-crypt_crypt_key_id_sodium'] ?? '' ),
			'codes'      => $crypt->codes,
		)
	);
}
PHP;
		$this->script_path = sys_get_temp_dir() . '/crypt-for-wordpress-legacy-crypt-' . uniqid( '', true ) . '.php';
		file_put_contents( $this->script_path, $script );

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->script_path ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/src' ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1' );
		$result = json_decode( (string) $output, true );

		$this->assertIsArray( $result, (string) $output );

		return $result;
	}

	/**
	 * Test that a key in a place, which is not the active one, is found and
	 * used - although the Crypt class cannot look into the places itself.
	 *
	 * @return void
	 */
	public function test_key_in_an_inactive_place_is_used(): void {
		$result = $this->run_scenario( 'inactive_place' );

		$this->assertTrue( $result['encrypted'], (string) wp_json_encode( $result ) );
		$this->assertSame( 'Hallo World', $result['decrypted'] );
		$this->assertTrue( $result['uses_key'] );
		$this->assertContains( 'key_in_inactive_place', $result['codes'] );
		$this->assertNotContains( 'key_missing', $result['codes'] );
		$this->assertSame( '', $result['stored_key'] );
	}

	/**
	 * Test that a new key is saved and checked in its place - although the
	 * Crypt class cannot check that itself.
	 *
	 * @return void
	 */
	public function test_new_key_is_saved_and_checked(): void {
		$result = $this->run_scenario( 'new_key' );

		$this->assertTrue( $result['encrypted'], (string) wp_json_encode( $result ) );
		$this->assertSame( 'Hallo World', $result['decrypted'] );
		$this->assertNotSame( '', $result['stored_key'] );
		$this->assertNotSame( '', $result['key_id'] );
		$this->assertNotContains( 'key_not_saved', $result['codes'] );
	}

	/**
	 * Test that no new key is generated, while a place holds the key but
	 * cannot hand it over - although the Crypt class cannot ask the places.
	 *
	 * @return void
	 */
	public function test_no_new_key_while_a_key_is_unavailable(): void {
		$result = $this->run_scenario( 'unavailable_key' );

		$this->assertFalse( $result['encrypted'], (string) wp_json_encode( $result ) );
		$this->assertContains( 'key_unavailable', $result['codes'] );
		$this->assertNotContains( 'key_missing', $result['codes'] );
		$this->assertSame( '', $result['stored_key'] );
		$this->assertSame( 'abcdef0123456789', $result['key_id'] );
	}
}
