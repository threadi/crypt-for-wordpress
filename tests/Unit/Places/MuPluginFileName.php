<?php
/**
 * Test the name of the file the MuPlugin place writes.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;
use ReflectionMethod;

/**
 * Object to test the name of the file the MuPlugin place writes.
 */
class MuPluginFileName extends CryptForWordPressTests {

	/**
	 * The file names written during the current test.
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	/**
	 * Clean up any generated mu-plugin file.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->written_files = array();

		parent::tear_down();
	}

	/**
	 * Return the file name the place uses for the given slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string
	 */
	private function get_file_name( string $slug ): string {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( $slug );

		$method = new ReflectionMethod( \CryptForWordPress\Places\MuPlugin::class, 'get_mu_plugin_filename' );

		return (string) $method->invoke( new \CryptForWordPress\Places\MuPlugin( $crypt_obj ) );
	}

	/**
	 * Test that every slug without a path is used unchanged - also one, which
	 * is not a clean file name. A file saved under it before must be found
	 * under the same name again.
	 *
	 * @return void
	 */
	public function test_slug_without_path_is_used_unchanged(): void {
		$this->assertSame( 'my-plugin-hash.php', $this->get_file_name( 'my-plugin' ) );
		$this->assertSame( 'My_Plugin-hash.php', $this->get_file_name( 'My_Plugin' ) );
		$this->assertSame( '.-hash.php', $this->get_file_name( '.' ) );
	}

	/**
	 * Test that a slug containing a path results in one file name directly in
	 * the "must-use"-plugin-directory.
	 *
	 * @return void
	 */
	public function test_slug_with_path_results_in_one_file_name(): void {
		foreach ( array( 'var/www/html/wp-content/themes/my-theme', '/var/www/my theme/', 'C:\\inetpub\\wwwroot\\theme', '../../wp-config' ) as $slug ) {
			$file_name = $this->get_file_name( $slug );

			$this->assertStringNotContainsString( '/', $file_name, $slug );
			$this->assertStringNotContainsString( '\\', $file_name, $slug );
			$this->assertStringNotContainsString( '..', $file_name, $slug );
			$this->assertStringNotContainsString( ' ', $file_name, $slug );
			$this->assertStringEndsWith( '-hash.php', $file_name, $slug );
			$this->assertNotSame( '-hash.php', $file_name, $slug );
		}

		$this->assertSame( 'var-www-html-wp-content-themes-my-theme-hash.php', $this->get_file_name( 'var/www/html/wp-content/themes/my-theme' ) );
	}

	/**
	 * Test that a slug with a path, of which nothing usable is left, still
	 * results in a file name.
	 *
	 * @return void
	 */
	public function test_slug_with_nothing_usable_left_gets_a_derived_name(): void {
		$this->assertSame( 'crypt-' . md5( '/' ) . '-hash.php', $this->get_file_name( '/' ) );
	}

	/**
	 * Test that the key of a file, which is not part of a plugin, is saved
	 * directly in the "must-use"-plugin-directory - where WordPress loads it.
	 *
	 * @return void
	 */
	public function test_key_for_slug_with_path_is_saved_in_the_directory(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'var/www/html/wp-content/themes/muplugin-test-' . uniqid( '', true ) );

		$place = new \CryptForWordPress\Places\MuPlugin( $crypt_obj );
		if ( ! $place->is_usable() ) {
			$this->markTestSkipped( 'The "must-use"-plugin-directory is not writable here.' );
		}

		$file_path             = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->get_file_name( $crypt_obj->get_slug() );
		$this->written_files[] = $file_path;

		$place->set_constant( 'MU_PLUGIN_FILE_NAME_TEST_' . strtoupper( str_replace( '.', '', uniqid( '', true ) ) ) );
		$place->save( 'unit-test-hash-value' );

		$this->assertFileExists( $file_path );
		$this->assertSame( realpath( WPMU_PLUGIN_DIR ), realpath( dirname( $file_path ) ) );
		$this->assertTrue( $place->is_saved( 'unit-test-hash-value' ) );

		// the uninstallation finds and deletes it.
		$place->uninstall( '' );
		$this->assertFileDoesNotExist( $file_path );
	}
}
