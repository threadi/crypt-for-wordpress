<?php
/**
 * File to handle the database as the place to save the key.
 *
 * ===== Warning =====
 * Using this method, key and encrypted strings are in the same place.
 * This is strictly not recommended.
 *
 * Configuration::
 * - 'force_place' => 'database', // not recommended.
 * - 'block_database' => true, // to forcibly block the usage of the database for the key.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Object to handle the database as the place to save the key.
 */
class Database extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'database';

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
		return ! ( isset( $this->configuration['block_database'] ) && $this->configuration['block_database'] );
	}

	/**
	 * Return the name of the option in the database.
	 *
	 * @return string
	 */
	private function get_option_name(): string {
		$option_name = $this->get_crypt_obj()->get_slug() . '-hash';

		/**
		 * Filter the name of the option in the database.
		 *
		 * @since 2.1.0 Available since 2.1.0.
		 * @param string $option_name The option name.
		 */
		return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_database_option_name', $option_name );
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// get the hash from the database.
		$hash = get_option( $this->get_option_name(), '' );

		// set the constant.
		if ( ! empty( $hash ) && ! defined( $this->get_constant_name() ) ) {
			define( $this->get_constant_name(), $hash );
		}

		// log a warning.
		$this->get_crypt_obj()->add_error(
			'insecure_place_database',
			'Keys and encrypted data are stored in the same data source: your projects database. This is insecure and must be addressed.'
		);
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {
		update_option( $this->get_option_name(), $hash, true );
	}

	/**
	 * Uninstall this place.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {
		delete_option( $this->get_option_name() );
	}

	/**
	 * Return the name of the constant.
	 *
	 * @return string
	 */
	private function get_constant_name(): string {
		$constant = strtoupper( $this->get_crypt_obj()->get_slug() ) . '-HASH';

		/**
		 * Filter the name of the constant.
		 *
		 * @since 1.1.2 Available since 1.1.2.
		 * @param string $constant The constant name.
		 */
		return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_constant', $constant );
	}
}
