<?php
/**
 * A method for tests, which extends the OpenSSL method and overrides
 * encrypt() and decrypt() the way it was possible before the context
 * existed: with one parameter.
 *
 * Such a class has to load without a fatal error, and its methods have to
 * be the ones used.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Methods\OpenSsl;

/**
 * Object of a method extending the OpenSSL method with the signatures as
 * they were before the context existed.
 */
class LegacyOpenSsl extends OpenSsl {

	/**
	 * How often encrypt() of this class has been called.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * Encrypt a given string.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		++self::$calls;

		return parent::encrypt( $plain_text );
	}

	/**
	 * Decrypt a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		++self::$calls;

		return parent::decrypt( $encrypted_text );
	}
}
