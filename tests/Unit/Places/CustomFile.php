<?php
/**
 * Test the CustomFile place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the CustomFile place.
 */
class CustomFile extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Paths created during a test, to be removed again in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $created_files = array();

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj     = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'customfile-test-' . uniqid('', true) );
		$this->created_files = array();
	}

	/**
	 * Clean up any file we wrote during a test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->created_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * Return a fresh, unique file path in the system temp directory for this test.
	 *
	 * @return string
	 */
	private function get_temp_file_path(): string {
		$path                  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cfwp-test-' . uniqid('', true) . '.php';
		$this->created_files[] = $path;
		return $path;
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$this->assertSame( 'customfile', $place->get_name() );
	}

	/**
	 * Test that this place is unusable without a configured path.
	 *
	 * @return void
	 */
	public function test_unusable_without_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a non-string path is rejected instead of causing a TypeError later on.
	 *
	 * @return void
	 */
	public function test_unusable_with_non_string_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => 123 ) );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a path in a writable directory is usable.
	 *
	 * @return void
	 */
	public function test_usable_with_writable_directory(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $this->get_temp_file_path() ) );
		$this->assertTrue( $place->is_usable() );
	}

	/**
	 * Test that save() writes a file that actually defines the constant with
	 * the given hash, and that load() picks that same file back up.
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$path     = $this->get_temp_file_path();
		$constant = 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) );
		$hash     = 'unit-test-hash-value';

		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $path ) );
		$place->set_constant( $constant );

		$place->save( $hash );

		$this->assertFileExists( $path );
		$this->assertFalse( defined( $constant ) );

		$place->load();

		$this->assertTrue( defined( $constant ) );
		$this->assertSame( $hash, constant( $constant ) );
	}

	/**
	 * Test that load() logs an error and does not fatally error if the
	 * configured file does not exist.
	 *
	 * @return void
	 */
	public function test_load_logs_error_when_file_missing(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $this->get_temp_file_path() ) );

		$this->assertFalse( $this->crypt_obj->has_errors() );

		$place->load();

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'custom_file_path_not_exists', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that save() refuses a stream-wrapper path (e.g. "phar://...")
	 * instead of writing to it.
	 *
	 * @return void
	 */
	public function test_save_rejects_stream_wrapper_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => 'phar://some/evil/path.php' ) );
		$place->set_constant( 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) ) );

		$place->save( 'unit-test-hash-value' );

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'custom_file_wrong_path', $this->crypt_obj->get_errors()->get_error_codes() );
	}
}
