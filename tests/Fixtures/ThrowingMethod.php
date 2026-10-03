<?php
/**
 * A method for tests, which fails with an exception while encrypting.
 *
 * It does not mark its own parameter as sensitive: the tests use it to make
 * sure that arguments are part of the stack trace at all.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Crypt;
use CryptForWordPress\Method_Base;
use RuntimeException;

/**
 * Object of a method, which fails with an exception while encrypting.
 */
class ThrowingMethod extends Method_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'throwing';

	/**
	 * Initialize the object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return true;
	}

	/**
	 * Fail while encrypting.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 * @throws RuntimeException Always.
	 */
	public function encrypt( string $plain_text ): string {
		throw new RuntimeException( 'Encryption failed.' );
	}
}
