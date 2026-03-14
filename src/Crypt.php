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
	private string $plugin_file = '';

	/**
	 * The slug to use.
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * Constructor for this object.
	 */
	public function __construct() {}

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
	 * @param string $encrypted_text Text to decrypt.
	 *
	 * @return string
	 */
	public function encrypt( string $encrypted_text ): string {
		// get the active method.
		$method_obj = $this->get_method();

		// bail if the method could not be found.
		if ( false === $method_obj ) {
			return '';
		}

		// encrypt the string with the detected method.
		return $method_obj->encrypt( $encrypted_text );
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
			$obj = $class_name();

			// bail if the object could not be loaded.
			if ( ! $obj instanceof Method_Base ) {
				continue;
			}

			// add settings.
			$obj->set_slug( $this->get_slug() );
			$obj->set_plugin_name( $this->get_plugin_name() );
			$obj->set_author_name( $this->get_plugin_author() );
			$obj->set_author_url( $this->get_plugin_author_url() );

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
	private function get_slug(): string {
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
	private function get_plugin_name(): string {
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
	private function get_plugin_author(): string {
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
	private function get_plugin_author_url(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['AuthorURI'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['AuthorURI'];
	}
}
