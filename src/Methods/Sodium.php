<?php
/**
 * File to handle sodium-tasks.
 *
 * @package crypt-for-wordpress
 */

namespace Crypt\Methods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Crypt\Helper;
use Crypt\Method_Base;
use Exception;
use SodiumException;

/**
 * Object to handle crypt tasks with Sodium.
 */
class Sodium extends Method_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = 'sodium';

	/**
	 * Coding-ID to use.
	 *
	 * @var int
	 */
	private int $coding_id = SODIUM_BASE64_VARIANT_ORIGINAL;

	/**
	 * Instance of this object.
	 *
	 * @var ?Sodium
	 */
	private static ?Sodium $instance = null;

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Sodium {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initiate this method.
	 *
	 * @return void
	 * @throws SodiumException On Exception through Sodium.
	 * @throws Exception Could throw exception.
	 */
	public function init(): void {
		if ( $this->is_hash_saved() ) {
			$this->set_hash( sodium_base642bin( $this->get_hash_value_from_constant(), $this->get_coding_id() ) ); // @phpstan-ignore constant.notFound
		}

		// bail if hash is set.
		if ( ! empty( $this->get_hash() ) ) {
			return;
		}

		// get hash from the old db entry.
		$this->set_hash( sodium_base642bin( get_option( $this->get_slug() . '_sodium_hash', '' ), $this->get_coding_id() ) );

		// if no hash is set, create one.
		if ( empty( $this->get_hash() ) ) {
			$hash = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
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
		$define                = "define( '" . $this->get_constant() . "', '" . $this->get_hash_value() . "' ); // Added by " . $this->get_plugin_name() . ".\r\n";
		$wp_config_php_content = preg_replace( '@<\?php\s*@i', "<?php\n$define", (string) $wp_config_php_content, 1 );

		if ( ! is_string( $wp_config_php_content ) ) {
			return;
		}

		// save the changed wp-config.php.
		$wp_filesystem->put_contents( $wp_config_php_path, $wp_config_php_content );

		// delete the old option field.
		delete_option( $this->get_slug() . '_sodium_hash' );

		// run the constant for this process.
		$this->run_constant();
	}

	/**
	 * Return the name of used constant for our hash.
	 *
	 * @return string
	 */
	protected function get_constant(): string {
		return strtoupper( $this->get_slug() ) . '-SODIUM-HASH';
	}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return function_exists( 'sodium_crypto_aead_aes256gcm_is_available' ) && sodium_crypto_aead_aes256gcm_is_available();
	}

	/**
	 * Encrypt a given string.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text ): string {
		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		try {
			// generate a nonce.
			$nonce = random_bytes( SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES );

			// return encrypted text as base64.
			return sodium_bin2base64( $nonce . ':' . sodium_crypto_aead_aes256gcm_encrypt( $plain_text, '', $nonce, $this->get_hash() ), $this->get_coding_id() );
		} catch ( Exception $e ) {
			// return nothing.
			return '';
		}
	}

	/**
	 * Decrypt a string.
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

		try {
			// split into the parts after converting from base64- to binary-string.
			$parts = explode( ':', sodium_base642bin( $encrypted_text, $this->get_coding_id() ) );

			// bail if an array is empty or does not have 2 entries.
			if ( count( $parts ) !== 2 ) {
				return '';
			}

			// return decrypted text.
			$decrypted = sodium_crypto_aead_aes256gcm_decrypt( $parts[1], '', $parts[0], $this->get_hash() );
			if ( ! is_string( $decrypted ) ) {
				return '';
			}
			return $decrypted;
		} catch ( Exception $e ) {
			// return nothing.
			return '';
		}
	}

	/**
	 * Return the used coding ID.
	 *
	 * @return int
	 */
	private function get_coding_id(): int {
		return $this->coding_id;
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 * @throws SodiumException On Exception through Sodium.
	 */
	public function uninstall(): void {
		// initiate the method to get the actual hash.
		$this->init();

		// save the hash in the database.
		update_option( $this->get_slug() . '_sodium_hash', $this->get_hash_value() );

		parent::uninstall();
	}

	/**
	 * Return the secured hash value.
	 *
	 * @return string
	 * @throws SodiumException On Exception through Sodium.
	 */
	protected function get_hash_value(): string {
		return sodium_bin2base64( $this->get_hash(), $this->get_coding_id() );
	}
}
