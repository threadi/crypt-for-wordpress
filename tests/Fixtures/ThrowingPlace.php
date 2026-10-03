<?php
/**
 * A place for tests, which fails with an exception while saving the key.
 *
 * It does not mark its own parameter as sensitive: the tests use it to make
 * sure that arguments are part of the stack trace at all.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;
use RuntimeException;

/**
 * Object of a place, which fails with an exception while saving the key.
 */
class ThrowingPlace extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'throwing';

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
	 * Fail while saving the hash.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 * @throws RuntimeException Always.
	 */
	public function save( string $hash ): void {
		throw new RuntimeException( 'Saving failed.' );
	}
}
