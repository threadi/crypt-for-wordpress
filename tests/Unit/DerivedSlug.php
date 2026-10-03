<?php
/**
 * Test the report about a derived slug, which is not suited to name the key.
 *
 * The slug names the key: the constant, the options, the Must-Use plugin.
 * It is derived from the directory of the plugin. Where there is no such
 * directory, the derived slug is either shared with others or depends on
 * the path of the installation - and is reported, so the developer can set
 * one. It is never changed: that would be a new key for every installation
 * already using it.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the report about a derived slug.
 */
class DerivedSlug extends CryptForWordPressTests {

	/**
	 * Return the error codes a Crypt object for the given file reports as
	 * soon as it is used.
	 *
	 * The database is used as place, so nothing is written into a file -
	 * and what is written into the database is removed after each test.
	 *
	 * @param string $file The file of the plugin or theme.
	 * @param string $slug A slug to set, or an empty string.
	 *
	 * @return array{0:Crypt,1:array<int,string>} The object and the error codes.
	 */
	private function get_error_codes( string $file, string $slug = '' ): array {
		$crypt_obj = new Crypt( $file );
		if ( '' !== $slug ) {
			$crypt_obj->set_slug( $slug );
		}
		$crypt_obj->set_config(
			array(
				'force_method' => 'openssl',
				'force_place'  => 'database',
			)
		);

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $crypt_obj->encrypt( 'Hallo World' ) ) );

		return array( $crypt_obj, (array) $crypt_obj->get_errors()?->get_error_codes() );
	}

	/**
	 * Test that the slug of a plugin in its own directory is not reported.
	 *
	 * @return void
	 */
	public function test_plugin_in_its_own_directory_is_not_reported(): void {
		list( $crypt_obj, $codes ) = $this->get_error_codes( WP_PLUGIN_DIR . '/derived-slug-plugin/derived-slug-plugin.php' );

		$this->assertSame( 'derived-slug-plugin', $crypt_obj->get_slug() );
		$this->assertNotContains( 'slug_not_unique', $codes );
		$this->assertNotContains( 'slug_depends_on_path', $codes );
	}

	/**
	 * Test that a file in a subdirectory of a plugin is not reported: its
	 * slug contains a slash, but not the path of the installation.
	 *
	 * @return void
	 */
	public function test_file_in_a_subdirectory_of_a_plugin_is_not_reported(): void {
		list( $crypt_obj, $codes ) = $this->get_error_codes( WP_PLUGIN_DIR . '/derived-slug-plugin/includes/class-settings.php' );

		$this->assertSame( 'derived-slug-plugin/includes', $crypt_obj->get_slug() );
		$this->assertNotContains( 'slug_not_unique', $codes );
		$this->assertNotContains( 'slug_depends_on_path', $codes );
	}

	/**
	 * Test that a plugin consisting of a single file and a Must-Use plugin
	 * are reported: both get the same slug, and with it the same key.
	 *
	 * @return void
	 */
	public function test_file_without_a_directory_is_reported(): void {
		foreach ( array( WP_PLUGIN_DIR . '/single-file-plugin.php', WPMU_PLUGIN_DIR . '/must-use-plugin.php' ) as $file ) {
			list( $crypt_obj, $codes ) = $this->get_error_codes( $file );

			$this->assertSame( '.', $crypt_obj->get_slug(), $file );
			$this->assertContains( 'slug_not_unique', $codes, $file );
			$this->assertNotContains( 'slug_depends_on_path', $codes, $file );
		}
	}

	/**
	 * Test that a file which is not part of a plugin, like the one of a
	 * theme, is reported: its slug contains the path of the installation.
	 *
	 * @return void
	 */
	public function test_file_of_a_theme_is_reported(): void {
		$file = get_theme_root() . '/derived-slug-theme/functions.php';

		list( $crypt_obj, $codes ) = $this->get_error_codes( $file );

		$this->assertStringEndsWith( '/derived-slug-theme', $crypt_obj->get_slug() );
		$this->assertStringContainsString( trim( wp_normalize_path( get_theme_root() ), '/' ), $crypt_obj->get_slug() );
		$this->assertContains( 'slug_depends_on_path', $codes );
		$this->assertNotContains( 'slug_not_unique', $codes );

		// the error names the slug.
		$data = $crypt_obj->get_errors()->get_error_data( 'slug_depends_on_path' );
		$this->assertIsArray( $data );
		$this->assertSame( $crypt_obj->get_slug(), $data['slug'] ?? null );
	}

	/**
	 * Test that nothing is reported as soon as the developer sets a slug.
	 *
	 * @return void
	 */
	public function test_set_slug_is_not_reported(): void {
		$files = array(
			'derived-slug-single-file' => WP_PLUGIN_DIR . '/single-file-plugin.php',
			'derived-slug-must-use'    => WPMU_PLUGIN_DIR . '/must-use-plugin.php',
			'derived-slug-theme'       => get_theme_root() . '/derived-slug-theme/functions.php',
		);

		foreach ( $files as $slug => $file ) {
			list( $crypt_obj, $codes ) = $this->get_error_codes( $file, $slug );

			$this->assertSame( $slug, $crypt_obj->get_slug() );
			$this->assertNotContains( 'slug_not_unique', $codes, $slug );
			$this->assertNotContains( 'slug_depends_on_path', $codes, $slug );
		}
	}

	/**
	 * Test that the slug is reported once per object, however often the
	 * object is used.
	 *
	 * @return void
	 */
	public function test_slug_is_reported_once(): void {
		list( $crypt_obj ) = $this->get_error_codes( WP_PLUGIN_DIR . '/single-file-plugin.php' );

		$crypt_obj->encrypt( 'Hallo World' );
		$crypt_obj->get_method();

		$this->assertCount( 1, $crypt_obj->get_errors()->get_error_messages( 'slug_not_unique' ) );
	}

	/**
	 * Test that the slug itself is left alone: the key of an installation
	 * already using it is named after it.
	 *
	 * @return void
	 */
	public function test_reported_slug_is_not_changed(): void {
		$file = WP_PLUGIN_DIR . '/single-file-plugin.php';

		list( $crypt_obj ) = $this->get_error_codes( $file );

		$this->assertSame( dirname( plugin_basename( $file ) ), $crypt_obj->get_slug() );
		$this->assertSame( '.-HASH', $crypt_obj->get_method()->get_constant() );
	}
}
