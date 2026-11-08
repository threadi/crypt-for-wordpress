<?php
/**
 * A second place for tests, which holds a key chosen by the test.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

/**
 * Object of a second place holding a key chosen by the test.
 */
class SecondStaticKeyPlace extends StaticKeyPlace {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'second_static_key_place';
}
