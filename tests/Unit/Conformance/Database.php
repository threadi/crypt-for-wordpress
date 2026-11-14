<?php
/**
 * Run the conformance tests for places on the place "database".
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Conformance;

use CryptForWordPress\Testing\PlaceConformance;
use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to run the conformance tests on the place "database".
 */
class Database extends CryptForWordPressTests {
	use PlaceConformance;

	/**
	 * Return the class of the place to test.
	 *
	 * @return string
	 */
	protected function get_place_class(): string {
		return \CryptForWordPress\Places\Database::class;
	}
}
