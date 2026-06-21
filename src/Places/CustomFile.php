<?php
/**
 * File to handle a custom file as place to save the key.
 *
 * Configuration:
 * - 'force_place' => 'customfile',
 * - 'custom_file_path' => '/your/absolute/path/to/creds.php',
 *
 * Hint:
 * - This file will be embedded by this package on load.
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
 * Object to handle a custom file as place to save the key.
 */
class CustomFile extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'customfile';

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

		// bail if no file path is given.
		if ( empty( $config['custom_file_path'] ) ) {
			return false;
		}

		// bail if given value is not a string.
		if ( ! is_string( $config['custom_file_path'] ) ) {
			return false;
		}

		// check if it is writable.
		return Helper::is_writable( dirname( $config['custom_file_path'] ) ); // @phpstan-ignore argument.type
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {
		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// get the path.
		$path = $config['custom_file_path'];

		// bail if path is not a string.
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

		// prepare the content.
		$custom_file_php_content = "<?php\n";
		// add the constant.
		$define                   = "define( '" . $this->get_constant() . "', '" . addslashes( $hash ) . "' ); // Added by " . $this->get_crypt_obj()->get_plugin_name() . ".\r\n";
		$custom_file_php_content .= $define;

		// save the changed wp-config.php.
		if( ! $wp_filesystem->put_contents( $secured_path, $custom_file_php_content ) ) {
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
		if ( ! empty( $config['file_permissions'] ) ) {
			$wp_filesystem->chmod( $secured_path, (int) $config['file_permissions'] );
		}
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// bail if no file path is given.
		if ( empty( $config['custom_file_path'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_not_given',
				'No path for the custom file provided.'
			);

			// do nothing more.
			return;
		}

		// bail if given value is not a string.
		if ( ! is_string( $config['custom_file_path'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_is_not_a_string',
				'Given path for the custom file is not a string.',
				array(
					'path' => $config['custom_file_path'],
				)
			);

			// do nothing more.
			return;
		}

        // secure the given path.
        $secured_path = wp_normalize_path( $config['custom_file_path'] );
        if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $secured_path ) ) {
            // log this error.
            $this->get_crypt_obj()->add_error(
                'custom_file_wrong_path',
                'Wrong path for the custom file provided.',
                array(
                    'path'         => $config['custom_file_path'],
                    'secured_path' => $secured_path,
                )
            );

            // do nothing more.
            return;
        }

		// get the WP_Filesystem object.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if the path for the file does not exist.
		if ( ! $wp_filesystem->exists( $config['custom_file_path'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'custom_file_path_not_exists',
				'Given path for the custom file does not exist.',
				array(
					'path' => $config['custom_file_path'],
				)
			);

			// do nothing more.
			return;
		}

		// embed the given file.
		require_once $config['custom_file_path'];
	}
}
