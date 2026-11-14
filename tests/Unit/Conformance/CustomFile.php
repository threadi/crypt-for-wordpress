<?php
/**
 * Run the conformance tests for places on the place "customfile".
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Conformance;

use CryptForWordPress\Testing\PlaceConformance;
use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to run the conformance tests on the place "customfile".
 */
class CustomFile extends CryptForWordPressTests {
	use PlaceConformance;

	/**
	 * The file of the current test.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Remove the file of the current test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->path && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
		$this->path = '';

		parent::tear_down();
	}

	/**
	 * Return the class of the place to test.
	 *
	 * @return string
	 */
	protected function get_place_class(): string {
		return \CryptForWordPress\Places\CustomFile::class;
	}

	/**
	 * Return that uninstall() leaves the key in the file: the file is
	 * managed by the user of the plugin.
	 *
	 * @return bool
	 */
	protected function place_conformance_removes_key_on_uninstall(): bool {
		return false;
	}

	/**
	 * Return the configuration: a file of its own for every test.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_place_conformance_config(): array {
		$this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cfwp-conformance-' . str_replace( '.', '', uniqid( '', true ) ) . '.php';

		return array( 'custom_file_path' => $this->path );
	}
}
