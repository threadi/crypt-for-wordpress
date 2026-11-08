<?php
/**
 * A place for tests, which holds a key chosen by the test.
 *
 * It never saves anything: it stands for a place, which has been the
 * active one before and still holds a key.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Object of a place holding a key chosen by the test.
 */
class StaticKeyPlace extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'static_key_place';

	/**
	 * The keys of the places, by the name of the place.
	 *
	 * @var array<string,string>
	 */
	public static array $keys = array();

	/**
	 * Constructor for this object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Return whether this place could be used: never as the active one.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return false;
	}

	/**
	 * Return whether this place holds the given hash: it never saves one.
	 *
	 * @param string $hash The hash.
	 *
	 * @return bool
	 */
	public function is_saved( #[\SensitiveParameter] string $hash ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}

	/**
	 * Return the key the test has chosen for this place.
	 *
	 * @return string
	 */
	public function get_stored_key(): string {
		return self::$keys[ $this->get_name() ] ?? '';
	}
}
