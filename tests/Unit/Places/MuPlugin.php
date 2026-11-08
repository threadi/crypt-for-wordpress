<?php
/**
 * Test the MuPlugin place.
 *
 * Note: whether this place is usable at all depends on WPMU_PLUGIN_DIR being
 * writable on the current hosting. Tests that need to actually write a file
 * skip themselves (instead of failing) if that is not the case here - see
 * ForceMuPlugin.php for the same reasoning.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;
use CryptForWordPress\Tests\Fixtures\ReadOnlyDirFilesystem;

/**
 * Object to test the MuPlugin place.
 */
class MuPlugin extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'muplugin-test-' . uniqid('', true) );
	}

	/**
	 * Clean up any generated mu-plugin file.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$file = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->crypt_obj->get_slug() . '-hash.php';
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
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );
		$this->assertSame( 'muplugin', $place->get_name() );
	}

	/**
	 * Test that is_usable() always returns a bool, regardless of what this
	 * hosting allows.
	 *
	 * @return void
	 */
	public function test_is_usable_returns_bool(): void {
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );
		$this->assertIsBool( $place->is_usable() );
	}

	/**
	 * Test that save() writes a valid mu-plugin file that actually defines
	 * the constant with the given hash - and that the generated header does
	 * not break out of its doc comment.
	 *
	 * @return void
	 */
	public function test_save_writes_working_mu_plugin_file(): void {
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );

		if ( ! $place->is_usable() ) {
            $this->skipWithoutMultisite();
		}

		$constant = 'MU_PLUGIN_TEST_' . strtoupper( uniqid('', true) );
		$hash     = 'unit-test-hash-value';

		$place->set_constant( $constant );
		$this->assertFalse( $place->is_saved( $hash ) );

		$place->save( $hash );

		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->crypt_obj->get_slug() . '-hash.php';
		$this->assertFileExists( $file_path );

		// the place confirms the hash it holds, and no other one.
		$this->assertTrue( $place->is_saved( $hash ) );
		$this->assertFalse( $place->is_saved( 'another-hash-value' ) );

		// saving the key of another method keeps this one in the file.
		$place->set_constant( $constant . '_SODIUM' );
		$place->save( 'key-of-another-method' );
		$this->assertTrue( $place->is_saved( 'key-of-another-method' ) );

		$place->set_constant( $constant );
		$this->assertTrue( $place->is_saved( $hash ) );

		// mu-plugins are auto-loaded by WordPress core on every request, before
		// any of our own code runs - we simulate that here by requiring the
		// generated file ourselves, exactly like the next request would.
		$this->assertFalse( defined( $constant ) );
		require $file_path;
		$this->assertTrue( defined( $constant ) );
		$this->assertSame( $hash, constant( $constant ) );
	}

	/**
	 * Test that uninstall() removes the generated mu-plugin file again.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_generated_file(): void {
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );

		if ( ! $place->is_usable() ) {
            $this->skipWithoutMultisite();
		}

		$constant = 'MU_PLUGIN_TEST_' . strtoupper( uniqid('', true) );

		$place->set_constant( $constant );
		$place->save( 'unit-test-hash-value' );

		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->crypt_obj->get_slug() . '-hash.php';
		$this->assertFileExists( $file_path );

		$place->uninstall( $constant );

		$this->assertFileDoesNotExist( $file_path );
	}

	/**
	 * Test that uninstall() logs an error instead of fatally erroring if the
	 * generated file was never created (e.g., re-running an uninstall).
	 *
	 * @return void
	 */
	public function test_uninstall_logs_error_when_file_missing(): void {
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );

		if ( ! $place->is_usable() ) {
            $this->skipWithoutMultisite();
		}

		$place->uninstall( 'MU_PLUGIN_TEST_' . strtoupper( uniqid('', true) ) );

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'muplugin_missing', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Run the given callback with the filesystem fixture, which treats chosen
	 * paths as not writable or lets writes fail.
	 *
	 * @param array<int,string> $read_only_paths Paths, which are not writable.
	 * @param array<int,string> $failing_writes  Paths (or their beginning), to which writes fail.
	 * @param callable          $callback        The callback.
	 *
	 * @return void
	 */
	private function with_fixture_filesystem( array $read_only_paths, array $failing_writes, callable $callback ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		if ( ! class_exists( 'WP_Filesystem_ReadOnlyDir', false ) ) {
			class_alias( ReadOnlyDirFilesystem::class, 'WP_Filesystem_ReadOnlyDir' );
		}

		$use_fixture = fn() => 'ReadOnlyDir';
		add_filter( 'filesystem_method', $use_fixture );
		ReadOnlyDirFilesystem::$read_only_paths = $read_only_paths;
		ReadOnlyDirFilesystem::$failing_writes  = $failing_writes;

		try {
			$callback();
		} finally {
			ReadOnlyDirFilesystem::$read_only_paths = array();
			ReadOnlyDirFilesystem::$failing_writes  = array();
			remove_filter( 'filesystem_method', $use_fixture );
		}
	}

	/**
	 * Run the given callback without the "must-use"-plugin directory - as on
	 * a new installation. An existing directory is moved aside meanwhile and
	 * restored afterward.
	 *
	 * @param callable $callback The callback.
	 *
	 * @return void
	 */
	private function without_mu_plugin_directory( callable $callback ): void {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_writable( dirname( WPMU_PLUGIN_DIR ) ) ) {
			$this->markTestSkipped( 'The parent directory of the "must-use"-plugins is not writable here.' );
		}

		$backup = '';
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$backup = WPMU_PLUGIN_DIR . '-backup-' . uniqid();
			rename( WPMU_PLUGIN_DIR, $backup );
		}

		try {
			$callback();
		} finally {
			// remove what the test created, then restore the directory.
			if ( is_dir( WPMU_PLUGIN_DIR ) ) {
				foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*' ) as $file ) {
					unlink( (string) $file );
				}
				rmdir( WPMU_PLUGIN_DIR );
			}
			if ( '' !== $backup ) {
				rename( $backup, WPMU_PLUGIN_DIR );
			}
		}
	}

	/**
	 * Test that save() creates the "must-use"-plugin directory, if it does
	 * not exist yet - the usual case on a new installation.
	 *
	 * @return void
	 */
	public function test_save_creates_missing_directory(): void {
		$this->without_mu_plugin_directory(
			function () {
				$place    = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );
				$constant = 'MU_PLUGIN_TEST_' . strtoupper( str_replace( '.', '', uniqid( '', true ) ) );
				$place->set_constant( $constant );

				$this->assertDirectoryDoesNotExist( WPMU_PLUGIN_DIR );
				$this->assertTrue( $place->is_usable() );

				$place->save( 'unit-test-hash-value' );

				$this->assertDirectoryExists( WPMU_PLUGIN_DIR );
				$this->assertTrue( $place->is_saved( 'unit-test-hash-value' ) );
			}
		);
	}

	/**
	 * Test that this place is not usable, if the directory does not exist and
	 * cannot be created.
	 *
	 * @return void
	 */
	public function test_not_usable_without_directory_and_writable_parent(): void {
		$this->without_mu_plugin_directory(
			function () {
				$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );

				$this->with_fixture_filesystem(
					array( dirname( WPMU_PLUGIN_DIR ) ),
					array(),
					function () use ( $place ) {
						$this->assertFalse( $place->is_usable() );
					}
				);
			}
		);
	}

	/**
	 * Test that uninstall() does nothing - and reports nothing - if the
	 * directory does not exist.
	 *
	 * @return void
	 */
	public function test_uninstall_without_directory(): void {
		$this->without_mu_plugin_directory(
			function () {
				$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );
				$place->uninstall( 'MU_PLUGIN_TEST_' . strtoupper( str_replace( '.', '', uniqid( '', true ) ) ) );

				$this->assertFalse( $this->crypt_obj->has_errors() );
				$this->assertDirectoryDoesNotExist( WPMU_PLUGIN_DIR );
			}
		);
	}

	/**
	 * Test that a failing write is reported and the key is not taken as saved.
	 *
	 * @return void
	 */
	public function test_failing_write_is_reported(): void {
		$place = new \CryptForWordPress\Places\MuPlugin( $this->crypt_obj );

		if ( ! $place->is_usable() ) {
			$this->markTestSkipped( 'The "must-use"-plugin directory is not usable here.' );
		}

		$place->set_constant( 'MU_PLUGIN_TEST_' . strtoupper( str_replace( '.', '', uniqid( '', true ) ) ) );

		$this->with_fixture_filesystem(
			array(),
			array( WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->crypt_obj->get_slug() . '-hash.php' ),
			static function () use ( $place ) {
				$place->save( 'unit-test-hash-value' );
			}
		);

		$this->assertContains( 'muplugin_write_failed', $this->crypt_obj->get_errors()->get_error_codes() );
		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );
	}
}
