<?php
/**
 * Test the WpConfig place.
 *
 * The real wp-config.php on this hosting may or may not exist/be writable
 * (see ForceWpConfig.php), so these tests redirect the place to a
 * self-contained, temporary "wp-config.php" via the '{slug}_wp_config_path'
 * filter - this way the actual save()/uninstall() logic can be verified
 * regardless of what the current hosting allows.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the WpConfig place.
 */
class WpConfig extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Path to our temporary, fake wp-config.php for the current test.
	 *
	 * @var string
	 */
	private string $fake_wp_config_path;

	/**
	 * A minimal, but realistic wp-config.php skeleton, containing the
	 * (non-localized) ABSPATH-check block that save() anchors its insertion on.
	 *
	 * @var string
	 */
	private const FAKE_WP_CONFIG_CONTENT = <<<'PHP'
<?php
define( 'DB_NAME', 'test_db' );
define( 'DB_USER', 'test_user' );

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP;

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'wpconfig-test-' . uniqid('', true) );

		$this->fake_wp_config_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cfwp-wp-config-' . uniqid('', true) . '.php';
		file_put_contents( $this->fake_wp_config_path, self::FAKE_WP_CONFIG_CONTENT );

		// redirect this place to our temporary file instead of the real wp-config.php.
		add_filter(
			$this->crypt_obj->get_slug() . '_wp_config_path',
			fn() => $this->fake_wp_config_path
		);
	}

	/**
	 * Clean up the temporary "wp-config.php" (and its lock file, if created).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( $this->crypt_obj->get_slug() . '_wp_config_path' );

		foreach ( array( $this->fake_wp_config_path, $this->fake_wp_config_path . '.lock' ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		parent::tear_down();
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\WpConfig( $this->crypt_obj );
		$this->assertSame( 'wpconfig', $place->get_name() );
	}

	/**
	 * Test that our temporary, writable "wp-config.php" is detected as usable.
	 *
	 * @return void
	 */
	public function test_usable_with_writable_wp_config(): void {
		$place = new \CryptForWordPress\Places\WpConfig( $this->crypt_obj );
		$this->assertTrue( $place->is_usable() );
	}

	/**
	 * Test that save() inserts the constant right before the ABSPATH-check
	 * block, and that load()-time defined() picks it up correctly.
	 *
	 * @return void
	 */
	public function test_save_inserts_constant_before_abspath_check(): void {
		$place    = new \CryptForWordPress\Places\WpConfig( $this->crypt_obj );
		$constant = 'WP_CONFIG_TEST_' . strtoupper( uniqid('', true) );
		$hash     = 'unit-test-hash-value';

		$place->set_constant( $constant );
		$place->save( $hash );

		$content = file_get_contents( $this->fake_wp_config_path );

		$this->assertStringContainsString( "define( '" . $constant . "', '" . $hash . "' )", $content );

		// the define must appear before the ABSPATH check, not after it.
		$define_pos  = strpos( $content, $constant );
		$abspath_pos = strpos( $content, "if ( ! defined( 'ABSPATH' ) )" );
		$this->assertIsInt( $define_pos );
		$this->assertIsInt( $abspath_pos );
		$this->assertLessThan( $abspath_pos, $define_pos );
	}

	/**
	 * Test that saving the same constant twice replaces the old value instead
	 * of appending a second, conflicting define() line.
	 *
	 * @return void
	 */
	public function test_save_replaces_previous_value(): void {
		$place    = new \CryptForWordPress\Places\WpConfig( $this->crypt_obj );
		$constant = 'WP_CONFIG_TEST_' . strtoupper( uniqid('', true) );

		$place->set_constant( $constant );
		$place->save( 'first-hash-value' );
		$place->save( 'second-hash-value' );

		$content = file_get_contents( $this->fake_wp_config_path );

		$this->assertSame( 1, substr_count( $content, "define( '" . $constant . "'" ) );
		$this->assertStringContainsString( "define( '" . $constant . "', 'second-hash-value' )", $content );
		$this->assertStringNotContainsString( 'first-hash-value', $content );
	}

	/**
	 * Test that uninstall() removes the previously saved constant again.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_constant(): void {
		$place    = new \CryptForWordPress\Places\WpConfig( $this->crypt_obj );
		$constant = 'WP_CONFIG_TEST_' . strtoupper( uniqid('', true) );

		$place->set_constant( $constant );
		$place->save( 'unit-test-hash-value' );
		$this->assertStringContainsString( $constant, file_get_contents( $this->fake_wp_config_path ) );

		$place->uninstall( $constant );

		$this->assertStringNotContainsString( $constant, file_get_contents( $this->fake_wp_config_path ) );
	}
}
