<?php
/**
 * Run the conformance tests for methods on the method "openssl".
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Conformance;

use CryptForWordPress\Testing\MethodConformance;
use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to run the conformance tests on the method "openssl".
 */
class OpenSsl extends CryptForWordPressTests {
	use MethodConformance;

	/**
	 * Return the class of the method to test.
	 *
	 * @return string
	 */
	protected function get_method_class(): string {
		return \CryptForWordPress\Methods\OpenSsl::class;
	}
}
