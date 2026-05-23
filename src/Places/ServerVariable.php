<?php
/**
 * File to handle the usage of a server variable for the key.
 *
 * Configuration:
 * - 'force_place' => 'server_variable',
 * - 'server_variable' => 'CRYPT-FOR-WORDPRESS-DEMO-HASH',
 *
 * Hint:
 * - You need to configure your hosting with a custom key to use this place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Object to handle a server variable for the key.
 */
class ServerVariable extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'server_variable';

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
		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// bail if no name for the server variable is given.
		if ( empty( $config['server_variable'] ) ) {
			return false;
		}

		// bail if given value is not a string.
		if ( ! is_string( $config['server_variable'] ) ) {
			return false;
		}

		// return true if the server variable is set and filled.
		return ! empty( $_SERVER[ $config['server_variable'] ] );
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// bail if no name for the server variable is given.
		if ( empty( $config['server_variable'] ) ) {
			return;
		}

		// bail if given value is not a string.
		if ( ! is_string( $config['server_variable'] ) ) {
			return;
		}

		// bail if given server variable does not exist.
		if ( empty( $_SERVER[ $config['server_variable'] ] ) ) {
			return;
		}

		// set the variable as constant.
		define( $config['server_variable'], sanitize_text_field( wp_unslash( $_SERVER[ $config['server_variable'] ] ) ) );
	}
}
