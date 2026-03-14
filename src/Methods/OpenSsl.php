<?php
/**
 * File to handle OpenSSL tasks.
 *
 * @package crypt-for-wordpress
 */

namespace Crypt\Methods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Crypt\Helper;
use Crypt\Method_Base;

/**
 * Object to handle crypt tasks with OpenSSL.
 */
class OpenSsl extends Method_Base {

	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'openssl';

	/**
	 * Instance of this object.
	 *
	 * @var ?OpenSsl
	 */
	private static ?OpenSsl $instance = null;

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): OpenSsl {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor for this object.
	 */
	public function init(): void {
		$this->set_hash( $this->get_hash_value_from_constant() );

		// bail if hash is set.
		if ( ! empty( $this->get_hash() ) ) {
			return;
		}

		// get hash from the database.
		$this->set_hash( get_option( $this->get_slug() . '_hash', '' ) );

		// if no hash is set, create one.
		if ( empty( $this->get_hash() ) ) {
			$hash = hash( 'sha256', (string) wp_rand() );
			$this->set_hash( $hash );
		}

		// get the wp-config.php path.
		$wp_config_php_path = Helper::get_wp_config_path( $this->get_slug() );

		// bail if the path could not be loaded.
		if ( ! $wp_config_php_path ) {
			return;
		}

		// bail if wp-config.php is not writable.
		if ( ! Helper::is_writable( $wp_config_php_path ) ) {
			$this->create_mu_plugin();
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// get the contents of the wp-config.php.
		$wp_config_php_content = $wp_filesystem->get_contents( $wp_config_php_path );

		// bail if the file has no contents.
		if ( ! $wp_config_php_content ) {
			return;
		}

		// remove previous value.
		$placeholder           = '## ' . strtoupper( $this->get_plugin_name() ) . ' placeholder ##';
		$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . $this->get_constant() . '["\'].*$@miU', $placeholder, $wp_config_php_content );
		$wp_config_php_content = preg_replace( "@\n$placeholder@", '', (string) $wp_config_php_content );

		// add the constant.
		$define                = "define( '" . $this->get_constant() . "', '" . $this->get_hash() . "' ); // Added by " . $this->get_plugin_name() . ".\r\n";
		$wp_config_php_content = preg_replace( '@<\?php\s*@i', "<?php\n$define", (string) $wp_config_php_content, 1 );

		if ( ! is_string( $wp_config_php_content ) ) {
			return;
		}

		// save the changed wp-config.php.
		$wp_filesystem->put_contents( $wp_config_php_path, $wp_config_php_content );

		// delete the old option field.
		delete_option( $this->get_slug() . '_hash' );

		// run the constant for this process.
		$this->run_constant();
	}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return function_exists( 'openssl_encrypt' );
	}

	/**
	 * Encrypt a given string.
	 *
	 * @param string $plain_text Text to encrypt.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no text is given.
		if ( empty( $plain_text ) ) {
			return '';
		}

		$cipher    = 'AES-128-CBC';
		$iv_length = openssl_cipher_iv_length( $cipher );

		if ( ! is_int( $iv_length ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return '';
		}

		$iv = openssl_random_pseudo_bytes( $iv_length );

		// bail if iv could not be created.
		if ( ! $iv ) {
			return '';
		}

		$ciphertext_raw = openssl_encrypt( $plain_text, $cipher, $this->get_hash(), OPENSSL_RAW_DATA, $iv );

		if ( ! $ciphertext_raw ) {
			return '';
		}

		$hmac = hash_hmac( 'sha256', $ciphertext_raw, $this->get_hash(), true );
		return base64_encode( base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );
	}

	/**
	 * Decrypted a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no text is given.
		if ( empty( $encrypted_text ) ) {
			return '';
		}

		$cipher    = 'AES-128-CBC';
		$iv_length = openssl_cipher_iv_length( $cipher );
		if ( ! $iv_length ) {
			return '';
		}
		$c = base64_decode( $encrypted_text );
		if ( str_contains( $c, ':' ) ) {
			$c_exploded = explode( ':', $c );
			$iv         = base64_decode( $c_exploded[0] );
			if ( ! $iv ) {
				return '';
			}
			$iv = substr( $iv, 0, $iv_length );
			$c  = base64_decode( $c_exploded[1] );
			if ( ! $c ) {
				return '';
			}
			$hmac           = substr( $c, 0, $sha2len = 32 );
			$ciphertext_raw = substr( $c, $sha2len, strlen( $c ) );
		} else {
			$iv             = substr( $c, 0, $iv_length );
			$hmac           = substr( $c, $iv_length, $sha2len = 32 );
			$ciphertext_raw = substr( $c, $iv_length + $sha2len );
		}
		$original_plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $this->get_hash(), OPENSSL_RAW_DATA, $iv );
		$calc_mac           = hash_hmac( 'sha256', $ciphertext_raw, $this->get_hash(), true );
		if ( $original_plaintext && $hmac && hash_equals( $hmac, $calc_mac ) ) {
			return $original_plaintext;
		}
		return '';
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// initiate the method to get the actual hash.
		$this->init();

		// save the hash in the database.
		update_option( $this->get_slug() . '_hash', $this->get_hash() );

		parent::uninstall();
	}
}
