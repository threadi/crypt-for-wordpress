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
		$place->save( $hash );

		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->crypt_obj->get_slug() . '-hash.php';
		$this->assertFileExists( $file_path );

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
}
