<?php
/**
 * File to handle tasks to not encrypt or decrypt anything.
 *
 * Hint: this method is not available in the automatic detection. It could only be used manually.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Methods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Method_Base;

/**
 * Object to handle crypt tasks with Sodium.
 */
class Plain extends Method_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'plain';

	/**
	 * Initialize the object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Constructor for this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// log this as warning.
		$this->get_crypt_obj()->add_error(
			'plain_method',
			'Method without encryption is used.',
		);
	}

	/**
	 * Encrypt a given string.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		return $plain_text;
	}

	/**
	 * Decrypt a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		return $encrypted_text;
	}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// return true if plain is explicit forced to be used.
		return isset( $config['force_method'] ) && 'plain' === $config['force_method'];
	}
}
