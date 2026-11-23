<?php
/**
 * Run the conformance tests for places on the place "secrets_api" - with the
 * Secrets API of WordPress itself.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Conformance;

use CryptForWordPress\Testing\PlaceConformance;
use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to run the conformance tests on the place "secrets_api".
 */
class SecretsApi extends CryptForWordPressTests {
	use PlaceConformance;

	/**
	 * Skip every test without the Secrets API (WordPress before 7.2).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! \CryptForWordPress\Places\SecretsApi::is_api_available() ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}
	}

	/**
	 * Return the class of the place to test.
	 *
	 * @return string
	 */
	protected function get_place_class(): string {
		return \CryptForWordPress\Places\SecretsApi::class;
	}
}
