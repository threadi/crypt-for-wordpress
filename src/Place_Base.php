<?php
/**
 * File to handle place methods as base-object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle place methods as base-object.
 */
class Place_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The constant to set.
	 *
	 * @var string
	 */
	private string $constant = '';

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
	protected Crypt $crypt_obj;

	/**
	 * Return the internal name of this place.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Return whether this place could be used.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return false;
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {}

	/**
	 * Return the configured crypt object.
	 *
	 * @return Crypt
	 */
	protected function get_crypt_obj(): Crypt {
		return $this->crypt_obj;
	}

	/**
	 * Return the constant to use.
	 *
	 * @return string
	 */
	protected function get_constant(): string {
		return $this->constant;
	}

	/**
	 * Set the constant.
	 *
	 * @param string $constant The name of the constant.
	 * @return void
	 */
	public function set_constant( string $constant ): void {
		$this->constant = $constant;
	}

	/**
	 * Uninstall this place.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {}

	/**
	 * Return raw key material this place can derive on its own.
	 *
	 * Places, which only store a key that this package generated return an
	 * empty string here - they hand their key over as a constant via load()
	 * instead. Only places, which compute a key themselves (e.g., from the
	 * WordPress salts) implement this, and they return raw bytes: the
	 * encoding is the business of the method that will use it.
	 *
	 * @return string
	 */
	public function get_derived_key(): string {
		return '';
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
}
