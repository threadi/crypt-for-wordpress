<?php
/**
 * File to use the WordPress-salts as key.
 *
 * ===== Warning =====
 * Salts might change. It is insecure to use this for encrypted data.
 * This is strictly not recommended.
 *
 * A salt which is still the placeholder of wp-config-sample.php
 * ("put your unique phrase here") is never used.
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
	 * The text wp-config-sample.php ships for every salt.
	 *
	 * A website still using it never had its salts generated. Every such
	 * website shares this value: a key derived from it is known to anybody.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Return whether this place could be used.
	 *
	 * Not if the configured salt is still the placeholder of
	 * wp-config-sample.php: the key derived from it would be public.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		// bail if the usage of salts should be blocked.
		if ( isset( $this->configuration['block_salts'] ) && $this->configuration['block_salts'] ) {
			return false;
		}

		// bail if the salt setting is not a string.
		if ( ! isset( $this->configuration['salt'] ) || ! is_string( $this->configuration['salt'] ) ) {
			return false;
		}

		// bail if the configured salt is the placeholder.
		if ( self::is_placeholder( $this->get_salt_value() ) ) {
			// log this error - once, as this is asked repeatedly.
			$errors = $this->get_crypt_obj()->get_errors();
			if ( null === $errors || ! in_array( 'wordpress_salts_placeholder', $errors->get_error_codes(), true ) ) {
				$this->get_crypt_obj()->add_error(
					'wordpress_salts_placeholder',
					'The WordPress salt to derive the key from is still the placeholder of wp-config-sample.php. A key derived from it would be known to anybody, so the salts are not used. Generate new salts for the wp-config.php.',
					array(
						'salt' => $this->configuration['salt'],
					)
				);
			}

			return false;
		}

		// usable.
		return true;
	}

	/**
	 * Return whether the given salt value is the placeholder of wp-config-sample.php.
	 *
	 * @param string $salt_value The value of the salt.
	 *
	 * @return bool
	 */
	public static function is_placeholder( #[\SensitiveParameter] string $salt_value ): bool {
		return self::PLACEHOLDER === trim( $salt_value );
	}

	/**
	 * Return the value of the configured salt constant, or an empty string.
	 *
	 * @return string
	 */
	private function get_salt_value(): string {
		// bail if the configured salt does not exist.
		if ( ! is_string( $this->configuration['salt'] ) || ! defined( $this->configuration['salt'] ) ) {
			return '';
		}

		// get its value.
		$salt_value = constant( $this->configuration['salt'] );

		return is_string( $salt_value ) ? $salt_value : '';
	}

	/**
	 * Return raw key material derived from the configured WordPress salt.
	 *
	 * @return string
	 */
	public function get_derived_key(): string {
		// get the raw value of the configured salt constant.
		$salt_value = $this->get_salt_value();

		// bail if the configured salt does not exist, is empty, or is the
		// placeholder of wp-config-sample.php.
		if ( '' === $salt_value || self::is_placeholder( $salt_value ) ) {
			return '';
		}

		// return raw key material - the method takes care of the encoding.
		return hash_hkdf( 'sha256', $salt_value, 32, $this->get_crypt_obj()->get_slug() . '-wordpress-salts' );
	}

	/**
	 * Return whether this place holds the given hash: it never does, as
	 * nothing can be saved in the salts by this package.
	 *
	 * So a key generated here is never taken into use - it would be gone
	 * with the next request.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( #[\SensitiveParameter] string $hash ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- nothing can be saved here.
		return false;
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
