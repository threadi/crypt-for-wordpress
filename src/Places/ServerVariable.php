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
		// bail if no name for the server variable is given.
		if ( empty( $this->configuration['server_variable'] ) ) {
			return false;
		}

		// bail if given value is not a string.
		if ( ! is_string( $this->configuration['server_variable'] ) ) {
			return false;
		}

		// return true if the server variable is set and filled.
		return ! empty( $_SERVER[ $this->configuration['server_variable'] ] );
	}

	/**
	 * Return whether this place holds the given hash: it never does, as
	 * nothing can be saved in the server configuration by this package.
	 *
	 * So a key generated here is never taken into use - it would be gone
	 * with the next request.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( string $hash ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- nothing can be saved here.
		return false;
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {

		// bail if no name for the server variable is given.
		if ( empty( $this->configuration['server_variable'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'server_variable_missing',
				'The server variable is missing in configuration for Crypt for WordPress.'
			);

			// do nothing more.
			return;
		}

		// bail if given value is not a string.
		if ( ! is_string( $this->configuration['server_variable'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'server_variable_not_a_string',
				'The server variable is not a string.'
			);

			// do nothing more.
			return;
		}

		// bail if the given server variable does not exist.
		if ( empty( $_SERVER[ $this->configuration['server_variable'] ] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'server_variable_missing_in_server',
				'The variable is missing in server variable. Did you miss the configuration in your hosting?',
				array(
					'server_variable' => $this->configuration['server_variable'],
				)
			);

			// do nothing more.
			return;
		}

		// get the key.
		$key = sanitize_text_field( wp_unslash( $_SERVER[ $this->configuration['server_variable'] ] ) );

		// set the variable as constant.
		if ( ! defined( $this->configuration['server_variable'] ) ) {
			define( $this->configuration['server_variable'], $key );
		}

		// hand the key over in the constant the method reads it from. The
		// variable may have any name - without this the key would only be
		// used if the variable happened to be named like that constant.
		$constant = $this->get_constant();
		if ( '' !== $constant && ! defined( $constant ) ) {
			define( $constant, $key );
		}
	}
}
