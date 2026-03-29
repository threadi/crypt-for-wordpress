<?php
/**
 * File to handle the main crypt tasks.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle crypt tasks.
 */
class Crypt {
	/**
	 * Define the method for crypt-tasks.
	 *
	 * @var false|Method_Base
	 */
	private false|Method_Base $method = false;

	/**
	 * The plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * The slug to use.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The method configurations.
	 *
	 * @var array<string,array<string,mixed>|string>
	 */
	private array $configuration = array();

	/**
	 * Constructor for this object.
	 *
	 * @param string $plugin_path The path to the WordPress plugin using this object. E.g., __FILE__.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_file = $plugin_path;
		$this->slug        = dirname( plugin_basename( $plugin_path ) );
	}

	/**
	 * Return the method object to use for encryption.
	 *
	 * @return false|Method_Base
	 */
	public function get_method(): false|Method_Base {
		if ( $this->method instanceof Method_Base ) {
			return $this->method;
		}

		// loop through the objects to check, which one we could use.
		foreach ( $this->get_methods_as_objects() as $obj ) {
			// bail if the method is unusable.
			if ( ! $obj->is_usable() ) {
				continue;
			}

			// initiate the method.
			$obj->init();

			// set method as our method to use.
			$this->method = $obj;

			return $this->method;
		}

		// return false if no usable method has been found.
		return false;
	}

	/**
	 * Return an encrypted string.
	 *
	 * @access public
	 *
	 * @param string $plain_text String to encrypt.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		// get the active method.
		$method_obj = $this->get_method();

		// bail if the method could not be found.
		if ( false === $method_obj ) {
			return '';
		}

		// encrypt the string with the detected method.
		return $method_obj->encrypt( $plain_text );
	}

	/**
	 * Return the decrypted string.
	 *
	 * @param string $encrypted_text Text to decrypt.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		// get the active method.
		$method_obj = $this->get_method();

		// bail if the method could not be found.
		if ( false === $method_obj ) {
			return '';
		}

		// decrypt the string with the detected method.
		return $method_obj->decrypt( $encrypted_text );
	}

	/**
	 * Return the list of supported methods.
	 *
	 * @return array<int,string>
	 */
	private function get_available_methods(): array {
		$methods = array(
			'CryptForWordPress\Methods\OpenSsl',
			'CryptForWordPress\Methods\Sodium',
		);

		/**
		 * Filter the available crypt-methods.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $methods List of methods.
		 */
		return apply_filters( $this->get_slug() . '_crypt_methods', $methods );
	}

	/**
	 * Return the list of available methods as objects.
	 *
	 * @return array<int,Method_Base>
	 */
	private function get_methods_as_objects(): array {
		// bail if this is not a WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			return array();
		}

		// define the list for objects.
		$list = array();

		// get all available methods.
		foreach ( $this->get_available_methods() as $method_class_name ) {
			// create the classname.
			$class_name = $method_class_name . '::get_instance';

			// bail if it is not callable.
			if ( ! is_callable( $class_name ) ) {
				continue;
			}

			// get the object.
			$obj = $class_name( $this );

			// bail if the object could not be loaded.
			if ( ! $obj instanceof Method_Base ) {
				continue;
			}

			// bail if a method is forced and this is not the forced method.
			if ( ! empty( $this->configuration['force_method'] ) && $obj->get_name() !== $this->configuration['force_method'] ) { // @phpstan-ignore notIdentical.alwaysTrue
				continue;
			}

			// add settings.
			$obj->set_config( $this->get_method_config( $obj->get_name() ) );

			// add the object to the list.
			$list[] = $obj;
		}

		// return the resulting list of objects.
		return $list;
	}

	/**
	 * Run uninstall tasks for crypt.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		foreach ( $this->get_methods_as_objects() as $obj ) {
			$obj->uninstall();
		}
	}

	/**
	 * Return the slug to use.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Set the slug to use.
	 *
	 * @param string $slug The slug to use.
	 * @return void
	 */
	public function set_slug( string $slug ): void {
		$this->slug = $slug;
	}

	/**
	 * Return the prefix to use.
	 *
	 * @return string
	 */
	private function get_plugin_file(): string {
		return $this->plugin_file;
	}

	/**
	 * Set the plugin file.
	 *
	 * @param string $plugin_file The absolute path to the plugin file.
	 * @return void
	 */
	public function set_plugin_file( string $plugin_file ): void {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Return the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['Name'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['Name'];
	}

	/**
	 * Return the plugin author name.
	 *
	 * @return string
	 */
	public function get_plugin_author(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['AuthorName'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['AuthorName'];
	}

	/**
	 * Return the plugin author URL.
	 *
	 * @return string
	 */
	public function get_plugin_author_url(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['AuthorURI'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['AuthorURI'];
	}

	/**
	 * Return the configuration for a specific method by its name.
	 *
	 * @param string $method_name The method name.
	 * @return array<string,mixed>
	 */
	public function get_method_config( string $method_name ): array {
		// bail if no configuration for this name is set.
		if ( ! isset( $this->configuration[ $method_name ] ) ) {
			return array();
		}

		// bail if configuration is not an array.
		if ( ! is_array( $this->configuration[ $method_name ] ) ) {
			return array();
		}

		// return the configuration.
		return $this->configuration[ $method_name ];
	}

	/**
	 * Return the configuration.
	 *
	 * @return array<string,array<string,mixed>|string>
	 */
	public function get_config(): array {
		return $this->configuration;
	}

	/**
	 * Set custom configuration for this object.
	 *
	 * @param array<string,array<string,mixed>|string> $configurations List of configurations.
	 * @return void
	 */
	public function set_config( array $configurations ): void {
		$this->configuration = $configurations;
	}

	/**
	 * Return the list of possible places where the hash could be saved.
	 *
	 * @return array<int,string>
	 */
	private function get_places(): array {
		$places = array(
			'CryptForWordPress\Places\WpConfig',
			'CryptForWordPress\Places\MuPlugin',
			'CryptForWordPress\Places\CustomFile',
		);

		/**
		 * Filter the available places.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $places List of methods.
		 */
		return apply_filters( $this->get_slug() . '_places', $places );
	}

	/**
	 * Return the list of available places as objects.
	 *
	 * @return array<int,Place_Base>
	 */
	public function get_places_as_object(): array {
		// bail if this is not a WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			return array();
		}

		// define the list for objects.
		$list = array();

		// get all available methods.
		foreach ( $this->get_places() as $method_class_name ) {
			// bail if it is not callable.
			if ( ! class_exists( $method_class_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $method_class_name( $this );

			// bail if the object could not be loaded.
			if ( ! $obj instanceof Place_Base ) {
				continue;
			}

			// bail if a method is forced and this is not the forced method.
			if ( ! empty( $this->configuration['force_place'] ) && $obj->get_name() !== $this->configuration['force_place'] ) { // @phpstan-ignore notIdentical.alwaysTrue
				continue;
			}

			// add the object to the list.
			$list[] = $obj;
		}

		// return the resulting list of objects.
		return $list;
	}

	/**
	 * Return the place where the token should be saved.
	 *
	 * @return false|Place_Base
	 */
	private function get_place(): false|Place_Base {
		// loop through the objects to check, which one we could use.
		foreach ( $this->get_places_as_object() as $obj ) {
			// bail if the method is unusable.
			if ( ! $obj->is_usable() ) {
				continue;
			}

			// return this place object.
			return $obj;
		}

		// return false if no usable method has been found.
		return false;
	}

	/**
	 * Save the given hash in the constant on the configured place.
	 *
	 * @param string $constant The constant to use.
	 * @param string $hash The hash to use.
	 * @return void
	 */
	public function save_in_place( string $constant, string $hash ): void {
		// get the place to use.
		$place_obj = $this->get_place();

		// bail if no place could be loaded.
		if ( ! $place_obj instanceof Place_Base ) {
			return;
		}

		// Set configuration.
		$place_obj->set_constant( $constant );

		// save the hash in the place.
		$place_obj->save( $hash );
	}
}
