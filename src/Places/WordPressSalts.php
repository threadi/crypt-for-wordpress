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
	 * Return raw key material derived from the configured WordPress salt.
	 *
	 * @return string
	 */
	public function get_derived_key(): string {
		// get the raw value of the configured salt constant.
		$salt_value = ( is_string( $this->configuration['salt'] ) && defined( $this->configuration['salt'] ) )
			? constant( $this->configuration['salt'] )
			: '';

		// bail if the configured salt does not exist or is empty.
		if ( empty( $salt_value ) ) {
			return '';
		}

		// return raw key material - the method takes care of the encoding.
		return hash_hkdf( 'sha256', $salt_value, 32, $this->get_crypt_obj()->get_slug() . '-wordpress-salts' );
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// log a warning.
		$this->get_crypt_obj()->add_error(
			'insecure_place_wordpress_salts',
			'Using salts is insecure and must be addressed.'
		);
	}
}
