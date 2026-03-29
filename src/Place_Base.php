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
	 * Uninstall this method.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {}
}
