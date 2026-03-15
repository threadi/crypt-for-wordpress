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
	 * The slug of the used plugin.
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * The name of the used plugin.
	 *
	 * @var string
	 */
	private string $plugin_name = '';

	/**
	 * The name of the plugin author.
	 *
	 * @var string
	 */
	private string $plugin_author = '';

	/**
	 * The URL of the plugin author.
	 *
	 * @var string
	 */
	private string $plugin_author_url = '';

	/**
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array();

	/**
	 * Constructor for this object.
	 */
	protected function __construct() {}

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
		return strtoupper( $this->get_slug() ) . '-HASH';
	}

	/**
	 * Return the header for the MU-plugin.
	 *
	 * @return string
	 */
	private function get_php_header(): string {
		return '
/**
 * Plugin Name:       Encryption for ' . $this->get_plugin_name() . '
 * Description:       Holds the hash value to use encryption within ' . $this->get_plugin_name() . '.
 * Requires at least: 4.9.24
 * Requires PHP:      8.1
 * Version:           1.0.0
 * Author:            ' . $this->get_author_name() . '
 * Author URI:        ' . $this->get_author_url() . '
 * Text Domain:       ' . $this->get_slug() . '-hash
 *
 * @package ' . $this->get_slug() . '-hash
 */';
	}

	/**
	 * Return the mu plugin filename.
	 *
	 * @return string
	 */
	private function get_mu_plugin_filename(): string {
		return $this->get_slug() . '-hash.php';
	}

	/**
	 * Create the MU-plugin, that is used as fallback if the "wp-config.php" could not be written.
	 *
	 * @return void
	 */
	protected function create_mu_plugin(): void {
		// bail if WPMU_PLUGIN_DIR is not set.
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// create a custom must-use-plugin instead.
		$file_content = '<?php ' . $this->get_php_header() . "\ndefine( '" . $this->get_constant() . "', '" . $this->get_hash_value() . "' ); // Added by " . $this->get_plugin_name() . ".\r\n";

		// create mu-plugin directory if it is missing.
		if ( ! $wp_filesystem->exists( WPMU_PLUGIN_DIR ) ) {
			$wp_filesystem->mkdir( WPMU_PLUGIN_DIR );
		}

		// define the path.
		$file_path = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->get_mu_plugin_filename();

		// save the file.
		if ( ! $wp_filesystem->put_contents( $file_path, $file_content ) ) {
			return;
		}

		// run the constant for this process.
		$this->run_constant();
	}

	/**
	 * Delete our own mu-plugin.
	 *
	 * @return void
	 */
	protected function delete_mu_plugin(): void {
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

		// delete the file.
		$wp_filesystem->delete( $file_path );
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// get the wp-config.php path.
		$wp_config_php_path = Helper::get_wp_config_path( $this->get_slug() );

		// bail if wp-config.php is not writable.
		if ( ! Helper::is_writable( $wp_config_php_path ) ) {
			// remove mu-plugin.
			$this->delete_mu_plugin();
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
		$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . $this->get_constant() . '["\'].*$@miU', '', $wp_config_php_content );

		if ( ! is_string( $wp_config_php_content ) ) {
			return;
		}

		// save the changed wp-config.php.
		$wp_filesystem->put_contents( $wp_config_php_path, $wp_config_php_content );
	}

	/**
	 * Return the plugin slug.
	 *
	 * @return string
	 */
	protected function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Set the plugin slug.
	 *
	 * @param string $slug The plugin slug.
	 * @return void
	 */
	public function set_slug( string $slug ): void {
		$this->slug = $slug;
	}

	/**
	 * Return the plugin name.
	 *
	 * @return string
	 */
	protected function get_plugin_name(): string {
		return $this->plugin_name;
	}

	/**
	 * Set the plugin name.
	 *
	 * @param string $plugin_name The plugin name.
	 * @return void
	 */
	public function set_plugin_name( string $plugin_name ): void {
		$this->plugin_name = $plugin_name;
	}

	/**
	 * Return the plugin author.
	 *
	 * @return string
	 */
	private function get_author_name(): string {
		return $this->plugin_author;
	}

	/**
	 * Set the plugin author.
	 *
	 * @param string $plugin_author The plugin author.
	 * @return void
	 */
	public function set_author_name( string $plugin_author ): void {
		$this->plugin_author = $plugin_author;
	}

	/**
	 * Return the plugin author URL.
	 *
	 * @return string
	 */
	private function get_author_url(): string {
		return $this->plugin_author_url;
	}

	/**
	 * Set the plugin author URL.
	 *
	 * @param string $plugin_author_url The plugin author URL.
	 * @return void
	 */
	public function set_author_url( string $plugin_author_url ): void {
		$this->plugin_author_url = $plugin_author_url;
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
