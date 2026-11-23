<?php
/**
 * Classes using the conformance traits - for PHPStan only.
 *
 * PHPStan analyses a trait only in a class, which uses it. The traits of
 * this package are used by the tests only, which PHPStan does not analyse.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\PHPStan;

use CryptForWordPress\Places\Database;
use CryptForWordPress\Methods\Sodium;
use CryptForWordPress\Testing\MethodConformance;
use CryptForWordPress\Testing\PlaceConformance;
use PHPUnit\Framework\TestCase;

/**
 * Class using the conformance tests for places.
 */
final class PlaceConformanceUsage extends TestCase {
	use PlaceConformance;

	/**
	 * Return the class of the place to test.
	 *
	 * @return string
	 */
	protected function get_place_class(): string {
		return Database::class;
	}
}

/**
 * Class using the conformance tests for methods.
 */
final class MethodConformanceUsage extends TestCase {
	use MethodConformance;

	/**
	 * Return the class of the method to test.
	 *
	 * @return string
	 */
	protected function get_method_class(): string {
		return Sodium::class;
	}
}
