<?php
/**
 * File to handle crypt methods as base-object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle crypt methods as base-object.
 */
class Method_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The hash for encryption.
	 *
	 * @var string
	 */
	protected string $hash = '';

	/**
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array();

	/**
	 * The crypt object.
	 *
	 * @var Crypt
	 */
	private Crypt $crypt_obj;

	/**
	 * Constructor for this object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	protected function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	protected function __clone() {}

	/**
	 * Initialize this crypt method.
	 *
	 * @return void
	 */
	public function init(): void {}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return false;
	}

	/**
	 * Return name of the method.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Encrypt a given string.
	 *
	 * @access private
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		if ( empty( $plain_text ) ) {
			return $plain_text;
		}
		return '';
	}

	/**
	 * Decrypt a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		if ( empty( $encrypted_text ) ) {
			return $encrypted_text;
		}
		return '';
	}

	/**
	 * Return hash for encryption.
	 *
	 * @return string
	 */
	public function get_hash(): string {
		return $this->hash;
	}

	/**
	 * Return the secured hash value.
	 *
	 * @return string
	 */
	protected function get_hash_value(): string {
		return $this->hash;
	}

	/**
	 * Return the hash value from our constant.
	 *
	 * @return string
	 */
	protected function get_hash_value_from_constant(): string {
		$constants = get_defined_constants();
		// bail if our constant is not set.
		if ( ! isset( $constants[ $this->get_constant() ] ) ) {
			return '';
		}

		// return the value of our constant.
		return get_defined_constants()[ $this->get_constant() ];
	}

	/**
	 * Set hash for encryption.
	 *
	 * @param string $hash The hash.
	 *
	 * @return void
	 */
	protected function set_hash( string $hash ): void {
		$this->hash = $hash;
	}

	/**
	 * Return whether the hash is saved in wp-config.php.
	 *
	 * @return bool
	 */
	public function is_hash_saved(): bool {
		return defined( $this->get_constant() );
	}

	/**
	 * Run the constant.
	 *
	 * @return void
	 */
	protected function run_constant(): void {
		if ( $this->is_hash_saved() ) {
			return;
		}
		define( $this->get_constant(), $this->get_hash() );
	}

	/**
	 * Return the name of used constant for our hash.
	 *
	 * @return string
	 */
	protected function get_constant(): string {
		$constant = strtoupper( $this->get_crypt_obj()->get_slug() ) . '-HASH';

        /**
         * Filter the name of the constant.
         *
         * @since 1.1.2 Available since 1.1.2.
         * @param string $constant The constants name.
         */
        return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_constant', $constant );
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		foreach ( $this->get_crypt_obj()->get_places_as_object() as $obj ) {
			$obj->uninstall( $this->get_constant() );
		}
	}

	/**
	 * Set the configuration.
	 *
	 * @param array<string,mixed> $configuration The configuration to use.
	 * @return void
	 */
	public function set_config( array $configuration ): void {
		$this->configuration = array_merge( $this->configuration, $configuration );
	}

	/**
	 * Return the configured crypt object.
	 *
	 * @return Crypt
	 */
	protected function get_crypt_obj(): Crypt {
		return $this->crypt_obj;
	}
}
