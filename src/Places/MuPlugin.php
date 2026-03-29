<?php
/**
 * File to handle a mu-plugin as place to save the key.
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
 * Object to handle a mu-plugin as place to save the key.
 */
class MuPlugin extends Place_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'muplugin';

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
		return defined( 'WPMU_PLUGIN_DIR' ) && Helper::is_writable( WPMU_PLUGIN_DIR );
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( string $hash ): void {
		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// create a custom must-use-plugin instead.
		$file_content = '<?php ' . $this->get_php_header() . "\ndefine( '" . $this->get_constant() . "', '" . $hash . "' ); // Added by " . $this->get_crypt_obj()->get_plugin_name() . ".\r\n";

		// create mu-plugin directory if it is missing.
		if ( ! $wp_filesystem->exists( WPMU_PLUGIN_DIR ) ) {
			$wp_filesystem->mkdir( WPMU_PLUGIN_DIR );
		}

		// define the path.
		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->get_mu_plugin_filename();

		// save the file.
		$wp_filesystem->put_contents( $file_path, $file_content );
	}

	/**
	 * Return the mu plugin filename.
	 *
	 * @return string
	 */
	private function get_mu_plugin_filename(): string {
		return $this->get_crypt_obj()->get_slug() . '-hash.php';
	}

	/**
	 * Return the header for the MU-plugin.
	 *
	 * @return string
	 */
	private function get_php_header(): string {
		return '
/**
 * Plugin Name:       Encryption for ' . $this->get_crypt_obj()->get_plugin_name() . '
 * Description:       Holds the hash value to use encryption within ' . $this->get_crypt_obj()->get_plugin_name() . '.
 * Requires at least: 4.9.24
 * Requires PHP:      8.1
 * Version:           1.0.0
 * Author:            ' . $this->get_crypt_obj()->get_plugin_author() . '
 * Author URI:        ' . $this->get_crypt_obj()->get_plugin_author_url() . '
 * Text Domain:       ' . $this->get_crypt_obj()->get_slug() . '-hash
 *
 * @package ' . $this->get_crypt_obj()->get_slug() . '-hash
 */';
	}

	/**
	 * Uninstall this method.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {
		// bail if WPMU_PLUGIN_DIR is not set.
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if mu directory does not exist.
		if ( ! $wp_filesystem->exists( WPMU_PLUGIN_DIR ) ) {
			return;
		}

		// define the path.
		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->get_mu_plugin_filename();

		// bail if file does not exist.
		if ( ! $wp_filesystem->exists( $file_path ) ) {
			return;
		}

		// delete the file.
		$wp_filesystem->delete( $file_path );
	}
}
