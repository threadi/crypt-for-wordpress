<?php
/**
 * A method for tests, written against the API as it was before the context
 * existed: encrypt() and decrypt() take one parameter.
 *
 * Such a class - added via the "{slug}_crypt_methods" filter - has to keep
 * working after an update of this package.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Crypt;
use CryptForWordPress\Method_Base;

/**
 * Object of a method written before the context existed.
 */
class LegacyMethod extends Method_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'legacy';

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
	 * "Encrypt" a given string.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		return strrev( $plain_text );
	}

	/**
	 * "Decrypt" a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		return strrev( $encrypted_text );
	}
}
