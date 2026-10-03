<?php
/**
 * File to handle a custom file as the place to save the key.
 *
 * Configuration:
 * - 'force_place' => 'customfile', // optional.
 * - 'custom_file_path' => '/your/absolute/path/to/creds.php', // required if forced.
 * - 'file_permissions' => '0640', // optional.
 *
 * Hint:
 * - This file will be embedded by this package onload.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Helper;
use CryptForWordPress\Place_Base;

/**
 * Object to handle a custom file as the place to save the key.
 */
class CustomFile extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'customfile';

	/**
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array(
		'file_permissions' => '0640',
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
		// bail if no file path is given.
		if ( empty( $this->configuration['custom_file_path'] ) ) {
			return false;
		}

		// bail if given value is not a string.
		if ( ! is_string( $this->configuration['custom_file_path'] ) ) {
			return false;
		}

		// check if it is writable.
		return Helper::is_writable( dirname( $this->configuration['custom_file_path'] ) ); // @phpstan-ignore argument.type
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( #[\SensitiveParameter] string $hash ): void {
		// get the path.
		$path = $this->configuration['custom_file_path'];

		// bail if the path is not a string.
		if ( ! is_string( $path ) ) {
			return;
		}

		// secure the given path.
		$secured_path = wp_normalize_path( $path );
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $secured_path ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_wrong_path',
				'Wrong path for the custom file provided.',
				array(
					'path'         => $path,
					'secured_path' => $secured_path,
				)
			);

			// do nothing more.
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// prepare the content - keeping the keys of other methods the file already holds.
		$custom_file_php_content = "<?php\n" . $this->get_other_define_lines( $secured_path );
		// add the constant.
		$define                   = $this->get_define_statement( $hash ) . ' // Added by ' . Helper::sanitize_for_php_comment( $this->get_crypt_obj()->get_plugin_name() ) . ".\r\n";
		$custom_file_php_content .= $define;

		// save the changed wp-config.php.
		if ( ! $wp_filesystem->put_contents( $secured_path, $custom_file_php_content ) ) {
			$this->get_crypt_obj()->add_error(
				'custom_file_write_failed',
				'Could not write the custom file.',
				array(
					'path' => $secured_path,
				)
			);

			// do nothing more.
			return;
		}

		// set the file permissions, if set.
		if ( ! $wp_filesystem->chmod( $secured_path, Helper::get_permission( $this->configuration['file_permissions'] ) ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_could_set_permissions',
				'Could not set file permissions. Possible write permission error.'
			);
		}
	}

	/**
	 * Return the configured path, or an empty string if it is missing or not
	 * a plain local path.
	 *
	 * @return string
	 */
	private function get_local_path(): string {
		// bail if no usable path is given.
		if ( empty( $this->configuration['custom_file_path'] ) || ! is_string( $this->configuration['custom_file_path'] ) ) {
			return '';
		}

		// secure the given path.
		$secured_path = wp_normalize_path( $this->configuration['custom_file_path'] );

		// bail if the path uses a stream wrapper.
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $secured_path ) ) {
			return '';
		}

		return $secured_path;
	}

	/**
	 * Return whether the custom file holds the given hash.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( #[\SensitiveParameter] string $hash ): bool {
		$path = $this->get_local_path();

		return '' !== $path && $this->file_holds_hash( $path, $hash );
	}

	/**
	 * Return the key the custom file holds, or an empty string.
	 *
	 * @return string
	 */
	public function get_stored_key(): string {
		$path = $this->get_local_path();

		return '' !== $path ? $this->read_hash_from_file( $path ) : '';
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// bail if no file path is given.
		if ( empty( $this->configuration['custom_file_path'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_not_given',
				'No path for the custom file provided.'
			);

			// do nothing more.
			return;
		}

		// bail if given value is not a string.
		if ( ! is_string( $this->configuration['custom_file_path'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_is_not_a_string',
				'Given path for the custom file is not a string.',
				array(
					'path' => $this->configuration['custom_file_path'],
				)
			);

			// do nothing more.
			return;
		}

		// secure the given path.
		$secured_path = wp_normalize_path( $this->configuration['custom_file_path'] );
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $secured_path ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_wrong_path',
				'Wrong path for the custom file provided.',
				array(
					'path'         => $this->configuration['custom_file_path'],
					'secured_path' => $secured_path,
				)
			);

			// do nothing more.
			return;
		}

		// get the WP_Filesystem object.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if the path for the file does not exist.
		if ( ! $wp_filesystem->exists( $secured_path ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_not_exists',
				'Given path for the custom file does not exist.',
				array(
					'path' => $secured_path,
				)
			);

			// do nothing more.
			return;
		}

		// embed the given file.
		require_once $secured_path;
	}
}
