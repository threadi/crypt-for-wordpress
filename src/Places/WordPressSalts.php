<?php
/**
 * File to use the WordPress-salts as key.
 *
 * ===== Warning =====
 * Salts might change. It is insecure to use this for encrypted data.
 * This is strictly not recommended.
 *
 * Configuration::
 * - 'force_place' => 'wordpress_salts', // not recommended.
 * - 'salt' => 'SECURE_AUTH_SALT', // the salt to use, as string name, defaults to "SECURE_AUTH_SALT".
 * - 'block_salts' => true, // to forcibly block the usage of salts as key.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Object to use the WordPress-salts as key.
 */
class WordPressSalts extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'wordpress_salts';

	/**
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array(
		'salt' => 'SECURE_AUTH_SALT',
	);

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
		// bail if the usage of salts should be blocked.
		if ( isset( $this->configuration['block_salts'] ) && $this->configuration['block_salts'] ) {
			return false;
		}

		// return whether the salt setting is a string.
		return isset( $this->configuration['salt'] ) && is_string( $this->configuration['salt'] );
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
        // get the raw value of the configured salt constant.
        $salt_value = ( is_string( $this->configuration['salt'] ) && defined( $this->configuration['salt'] ) )
            ? constant( $this->configuration['salt'] )
            : '';

        // set the constant.
        if ( ! empty( $salt_value ) && ! defined( $this->get_constant_name() ) ) {
            // Derive a key and hex-encode it, matching the format
            // OpenSsl::get_decoded_master_key() expects (hex2bin()). The raw
            // salt is plain ASCII, not hex, and would break hex2bin() as-is.
            $derived_key = hash_hkdf( 'sha256', $salt_value, 32, $this->get_crypt_obj()->get_slug() . '-wordpress-salts' );
            define( $this->get_constant_name(), bin2hex( $derived_key ) );
        }

        // log a warning.
        $this->get_crypt_obj()->add_error(
            'insecure_place_wordpress_salts',
            'Using salts is insecure and must be addressed.'
        );
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
