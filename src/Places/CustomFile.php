<?php
/**
 * File to handle a custom file as place to save the key.
 *
 * Configuration:
 * - 'force_place' => 'customfile',
 * - 'custom_file_path' => '/your/absolute/path/to/creds.php',
 *
 * Hint:
 * - File must be a PHP file.
 * - This file must be included in WordPress to get loaded.
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
 * Object to handle the wp-config.php as place to save the key.
 */
class CustomFile extends Place_Base {

	/**
	 * Name of the method.
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

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// prepare the content.
		$wp_config_php_content = '<?php';

		// remove previous value.
		$placeholder           = '## ' . strtoupper( $this->get_crypt_obj()->get_plugin_name() ) . ' placeholder ##';
		$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . $this->get_constant() . '["\'].*$@miU', $placeholder, $wp_config_php_content );
		$wp_config_php_content = preg_replace( "@\n$placeholder@", '', (string) $wp_config_php_content );

		// add the constant.
		$define                = "define( '" . $this->get_constant() . "', '" . $hash . "' ); // Added by " . $this->get_crypt_obj()->get_plugin_name() . ".\r\n";
		$wp_config_php_content = preg_replace( '@<\?php\s*@i', "<?php\n$define", (string) $wp_config_php_content, 1 );

		// bail if resulting value is not a string.
		if ( ! is_string( $wp_config_php_content ) ) {
			return;
		}

		// save the changed wp-config.php.
		$wp_filesystem->put_contents( $config['custom_file_path'], $wp_config_php_content ); // @phpstan-ignore argument.type
	}
}
