<?php
/**
 * A place for tests, which claims to be usable, but never saves anything.
 *
 * This is what a place looks like from the outside when a write fails: a
 * full disk, a directory without write permission, a hoster resetting files.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Object of a place, which never saves anything.
 */
class BrokenPlace extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'broken';

	/**
	 * Constructor for this object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Return whether this place could be used.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return true;
	}

	/**
	 * Pretend to save the hash - and lose it.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {}

	/**
	 * Return whether this place holds the given hash: it never does.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( string $hash ): bool {
		return false;
	}
}
