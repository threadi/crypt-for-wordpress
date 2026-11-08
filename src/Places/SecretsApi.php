<?php
/**
 * File to use the Secrets API of WordPress as the place to save the key.
 *
 * Available since WordPress 7.2. The key is saved as a secret: WordPress
 * encrypts it with its own root key, which is protected by the constant
 * WP_SECRETS_KEY in the wp-config.php - or, without it, by the WordPress
 * salts. A drop-in of the hosting (wp-content/secrets.php) can keep it in
 * a key management service or a vault instead.
 *
 * Configuration:
 * - 'force_place' => 'secrets_api',
 * - 'block_secrets_api' => true, // to forcibly block the usage of the Secrets API for the key.
 *
 * Hints:
 * - The place is only usable if WordPress provides the Secrets API.
 * - It is used before the Must-Use plugin only if the Secrets API does not
 *   depend on the WordPress salts: if WP_SECRETS_KEY is defined, or a
 *   drop-in is used. Otherwise, a change of the salts would make the key
 *   unreadable.
 * - It fails closed: if the Secrets API holds the key but cannot hand it
 *   over, no new key is generated.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Method_Base;
use CryptForWordPress\Place_Base;
use WP_Error;
use WP_Secret;

/**
 * Object to use the Secrets API of WordPress as the place to save the key.
 */
class SecretsApi extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'secrets_api';

	/**
	 * The longest a secret name may be (WP_SECRETS_MAX_NAME_LENGTH).
	 *
	 * @var int
	 */
	private const MAX_NAME_LENGTH = 172;

	/**
	 * The longest the namespace of the secret name may be. The rest of
	 * MAX_NAME_LENGTH, minus the "/", is left for the key.
	 *
	 * @var int
	 */
	private const MAX_NAMESPACE_LENGTH = 85;

	/**
	 * Constructor for this object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Return whether WordPress provides the Secrets API.
	 *
	 * @return bool
	 */
	public static function is_api_available(): bool {
		return function_exists( 'wp_get_secret' )
			&& function_exists( 'wp_set_secret' )
			&& function_exists( 'wp_delete_secret' )
			&& function_exists( 'wp_list_secrets' )
			&& function_exists( 'wp_secrets_provider_is_writable' )
			&& class_exists( 'WP_Secret' );
	}

	/**
	 * Return whether the Secrets API protects its secrets with a key of its
	 * own - the constant WP_SECRETS_KEY or a drop-in of the hosting - and not
	 * with the WordPress salts.
	 *
	 * Only then this place is preferred over the Must-Use plugin: a key
	 * protected by the salts is lost as soon as the salts are changed, which
	 * some security plugins do on their own.
	 *
	 * @return bool
	 */
	public static function has_dedicated_key_source(): bool {
		// bail if the Secrets API is not available.
		if ( ! self::is_api_available() ) {
			return false;
		}

		// a drop-in of the hosting decides how the secrets are protected.
		if ( function_exists( 'wp_using_secrets_dropin' ) && wp_using_secrets_dropin() ) {
			return true;
		}

		// return whether the dedicated constant is set.
		return defined( 'WP_SECRETS_KEY' ) && is_string( constant( 'WP_SECRETS_KEY' ) ) && '' !== constant( 'WP_SECRETS_KEY' );
	}

	/**
	 * Return whether the usage of this place is blocked by the configuration.
	 *
	 * @return bool
	 */
	private function is_blocked(): bool {
		return isset( $this->configuration['block_secrets_api'] ) && $this->configuration['block_secrets_api'];
	}

	/**
	 * Return whether this place could be used.
	 *
	 * The Secrets API must be available and accept new secrets. If the
	 * hosting manages the secrets itself (a read-only provider), this place
	 * is only usable if it already holds a secret of this plugin.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		// bail if the usage of the Secrets API is blocked.
		if ( $this->is_blocked() ) {
			return false;
		}

		// bail if the Secrets API is not available.
		if ( ! self::is_api_available() ) {
			return false;
		}

		// usable if a new key could be saved.
		if ( wp_secrets_provider_is_writable() ) {
			return true;
		}

		// a read-only provider is usable only if it holds a secret of this plugin already.
		$secrets = wp_list_secrets( $this->get_secret_namespace() );

		return is_array( $secrets ) && ! empty( $secrets );
	}

	/**
	 * Return the name of the secret holding the key.
	 *
	 * The name has the format WordPress expects: "namespace/key". The
	 * namespace is the slug, the key the constant the method uses - both
	 * reduced to lowercase letters, numbers, hyphens and underscores.
	 *
	 * @return string
	 */
	public function get_secret_name(): string {
		$secret_name = $this->get_secret_namespace() . '/' . self::sanitize_segment( $this->get_constant(), self::MAX_NAME_LENGTH - self::MAX_NAMESPACE_LENGTH - 1 );

		/**
		 * Filter the name of the secret holding the key.
		 *
		 * Changing it later is like a lost key: the secret saved under the
		 * former name is not found anymore.
		 *
		 * @since 4.1.0 Available since 4.1.0.
		 * @param string $secret_name The name of the secret ("namespace/key").
		 * @param string $constant    The constant the key is used as.
		 */
		return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_secrets_api_name', $secret_name, $this->get_constant() );
	}

	/**
	 * Return the namespace of the secret: the slug, sanitized.
	 *
	 * @return string
	 */
	private function get_secret_namespace(): string {
		return self::sanitize_segment( $this->get_crypt_obj()->get_slug(), self::MAX_NAMESPACE_LENGTH );
	}

	/**
	 * Return a segment of a secret name for the given value.
	 *
	 * WordPress only accepts lowercase letters, numbers, hyphens and
	 * underscores in it, starting and ending with a letter or a number. If
	 * the value has to be changed for this - beyond its case - or shortened,
	 * a short hash of the original value is added. So two values, which only
	 * differ in characters that are replaced, still get different names.
	 *
	 * The result is always the same for the same value.
	 *
	 * @param string $value      The value.
	 * @param int    $max_length The longest the segment may be.
	 *
	 * @return string
	 */
	public static function sanitize_segment( string $value, int $max_length ): string {
		$lower = strtolower( $value );

		// replace everything WordPress does not accept.
		$segment = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', $lower ), '-_' );

		// return it, if nothing but the case had to be changed.
		if ( '' !== $segment && $segment === $lower && strlen( $segment ) <= $max_length ) {
			return $segment;
		}

		// add a hash of the original value to keep it distinct.
		$hash    = substr( hash( 'sha256', $value ), 0, 8 );
		$segment = trim( substr( $segment, 0, $max_length - strlen( $hash ) - 1 ), '-_' );

		return '' === $segment ? $hash : $segment . '-' . $hash;
	}

	/**
	 * Return the secret holding the key, null if there is none, or the error
	 * which prevented reading it.
	 *
	 * @return WP_Secret|WP_Error|null
	 */
	private function get_secret(): WP_Secret|WP_Error|null {
		// bail if no constant is set: the name of the secret depends on it.
		if ( '' === $this->get_constant() ) {
			return null;
		}

		// get the secret.
		$secret = wp_get_secret( $this->get_secret_name() );

		// return it, if it is one of the three states WordPress defines.
		if ( $secret instanceof WP_Secret || $secret instanceof WP_Error ) {
			return $secret;
		}

		return null;
	}

	/**
	 * Return the value of the given secret, or an empty string if the
	 * provider does not hand it over.
	 *
	 * @param WP_Secret $secret The secret.
	 *
	 * @return string
	 */
	private function reveal( WP_Secret $secret ): string {
		$value = $secret->reveal();

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {
		// bail if this place cannot be used.
		if ( $this->is_blocked() || ! self::is_api_available() ) {
			return;
		}

		// get the constant this place has been told to fill.
		$constant = $this->get_constant();

		// bail if there is no constant or it is defined already.
		if ( '' === $constant || defined( $constant ) ) {
			return;
		}

		// get the secret.
		$secret = $this->get_secret();

		// bail if it could not be read: the key exists, but is unavailable.
		if ( $secret instanceof WP_Error ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'secrets_api_unavailable',
				'The key for encryption could not be read from the Secrets API of WordPress. No new key is generated. Check the Site Health of WordPress.',
				array(
					'secret' => $this->get_secret_name(),
					'reason' => $secret->get_error_code(),
				)
			);

			// do nothing more.
			return;
		}

		// bail if there is no secret yet.
		if ( null === $secret ) {
			return;
		}

		// get its value.
		$key = $this->reveal( $secret );

		// bail if the provider does not hand it over.
		if ( '' === $key ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'secrets_api_withheld',
				'The Secrets API of WordPress holds the key for encryption, but does not hand it over.',
				array(
					'secret' => $this->get_secret_name(),
				)
			);

			// do nothing more.
			return;
		}

		// set the constant.
		define( $constant, $key );

		// this key belongs to the current site of a multisite network only.
		Method_Base::register_per_site_constant( $constant );
	}

	/**
	 * Save the hash in this place.
	 *
	 * Fails closed: if a secret exists, but cannot be read, nothing is
	 * written - it could be the key all values have been encrypted with.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( #[\SensitiveParameter] string $hash ): void {
		// bail if this place cannot be used.
		if ( $this->is_blocked() || ! self::is_api_available() || '' === $this->get_constant() ) {
			return;
		}

		// bail if the hosting manages the secrets itself.
		if ( ! wp_secrets_provider_is_writable() ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'secrets_api_read_only',
				'The Secrets API of WordPress does not accept new secrets on this hosting. Save the key for encryption with the tools of your hosting.',
				array(
					'secret' => $this->get_secret_name(),
				)
			);

			// do nothing more.
			return;
		}

		// bail if an existing secret cannot be read.
		$existing = $this->get_secret();
		if ( $existing instanceof WP_Error ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'secrets_api_unavailable',
				'The key for encryption could not be read from the Secrets API of WordPress. It is not replaced by a new one.',
				array(
					'secret' => $this->get_secret_name(),
					'reason' => $existing->get_error_code(),
				)
			);

			// do nothing more.
			return;
		}

		// save the hash as secret.
		$result = wp_set_secret( $this->get_secret_name(), $hash );

		// bail if it could not be saved.
		if ( $result instanceof WP_Error ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'secrets_api_not_saved',
				'The key for encryption could not be saved in the Secrets API of WordPress.',
				array(
					'secret' => $this->get_secret_name(),
					'reason' => $result->get_error_code(),
				)
			);
		}
	}

	/**
	 * Return whether the Secrets API holds the given hash.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( #[\SensitiveParameter] string $hash ): bool {
		// bail if nothing could have been saved.
		if ( '' === $hash || $this->is_blocked() || ! self::is_api_available() ) {
			return false;
		}

		// get the secret.
		$secret = $this->get_secret();

		// return whether it holds the hash.
		return $secret instanceof WP_Secret && hash_equals( $this->reveal( $secret ), $hash );
	}

	/**
	 * Return the key the Secrets API holds, or an empty string.
	 *
	 * @return string
	 */
	public function get_stored_key(): string {
		// bail if this place cannot be used.
		if ( $this->is_blocked() || ! self::is_api_available() ) {
			return '';
		}

		// get the secret.
		$secret = $this->get_secret();

		// return its value, if there is one.
		return $secret instanceof WP_Secret ? $this->reveal( $secret ) : '';
	}

	/**
	 * Return whether the Secrets API holds a key, which it cannot hand over
	 * right now: the provider is unreachable, or the secret cannot be
	 * decrypted (e.g., after WP_SECRETS_KEY or the salts have been changed).
	 *
	 * @return bool
	 */
	public function is_key_unavailable(): bool {
		// bail if this place cannot be used.
		if ( $this->is_blocked() || ! self::is_api_available() ) {
			return false;
		}

		// get the secret.
		$secret = $this->get_secret();

		// unavailable, if it cannot be read, or its value is withheld.
		return $secret instanceof WP_Error || ( $secret instanceof WP_Secret && '' === $this->reveal( $secret ) );
	}

	/**
	 * Return whether the key of this place is the same for every site of a
	 * multisite network: it is not, the Secrets API keeps secrets per site.
	 *
	 * @return bool
	 */
	public function is_network_wide(): bool {
		return false;
	}

	/**
	 * Uninstall this place.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {
		// bail if the Secrets API is not available, or the hosting manages it.
		if ( ! self::is_api_available() || ! wp_secrets_provider_is_writable() ) {
			return;
		}

		// remove the secret.
		$this->set_constant( $constant );
		wp_delete_secret( $this->get_secret_name() );
	}
}
