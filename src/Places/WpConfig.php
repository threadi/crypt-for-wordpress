<?php
/**
 * File to handle the wp-config.php as place to save the key.
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
class WpConfig extends Place_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'wpconfig';

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
		return Helper::is_writable( $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() ) );
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {
		// get the wp-config.php path.
		$wp_config_php_path = $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() );

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// get the contents of the wp-config.php.
		$wp_config_php_content = $wp_filesystem->get_contents( $wp_config_php_path );

		// bail if the file has no contents.
		if ( ! $wp_config_php_content ) {
			return;
		}

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
		$wp_filesystem->put_contents( $wp_config_php_path, $wp_config_php_content );
	}

	/**
	 * Return the wp-config.php path.
	 *
	 * @param string $slug The plugin slug for the hook names.
	 * @return string
	 */
	private function get_wp_config_path( string $slug ): string {
		$wp_config_php = 'wp-config';
		/**
		 * Filter to change the filename of the used wp-config.php without its extension .php.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $wp_config_php The filename.
		 */
		$wp_config_php = apply_filters( $slug . '_wp_config_name', $wp_config_php );

		// get the path for wp-config.php.
		$wp_config_php_path = ABSPATH . $wp_config_php . '.php';

		/**
		 * Filter the path for the wp-config.php before we return it.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $wp_config_php_path The path.
		 */
		return apply_filters( $slug . '_wp_config_path', $wp_config_php_path );
	}

	/**
	 * Uninstall this method.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {
		// get the wp-config.php path.
		$wp_config_php_path = $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() );

		// bail if wp-config.php is not writable.
		if ( ! Helper::is_writable( $wp_config_php_path ) ) {
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// get the contents of the wp-config.php.
		$wp_config_php_content = $wp_filesystem->get_contents( $wp_config_php_path );

		// bail if file has no contents.
		if ( ! $wp_config_php_content ) {
			return;
		}

		// remove the value.
		$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . $constant . '["\'].*$@miU', '', $wp_config_php_content );

		if ( ! is_string( $wp_config_php_content ) ) {
			return;
		}

		// save the changed wp-config.php.
		$wp_filesystem->put_contents( $wp_config_php_path, $wp_config_php_content );
	}
}
