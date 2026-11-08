<?php
/**
 * Stubs for the Secrets API of WordPress 7.2 - for PHPStan only.
 *
 * The php-stubs/wordpress-stubs package does not contain the Secrets API
 * yet. Remove this file from phpstan.neon once it does.
 *
 * Signatures as of WordPress 7.2-beta4 (wp-includes/secrets.php,
 * wp-includes/secrets/class-wp-secret.php,
 * wp-includes/secrets/class-wp-secret-version.php).
 *
 * @package crypt-for-wordpress
 */

/**
 * The version slots of a secret.
 */
final class WP_Secret_Version {
	const CURRENT  = 'current';
	const PREVIOUS = 'previous';
}

/**
 * A decrypted secret value, returned by wp_get_secret().
 */
final class WP_Secret implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param string $name        The secret's namespaced name.
	 * @param string $value       The decrypted plaintext.
	 * @param string $fingerprint Keyed fingerprint of $value.
	 */
	public function __construct( string $name, $value, string $fingerprint ) {}

	/**
	 * Returns the decrypted plaintext.
	 *
	 * @return string|WP_Error The plaintext, or WP_Error if the provider does not release values to PHP.
	 */
	public function reveal() {}

	/**
	 * Builds a secret whose value exists but is not available to PHP.
	 *
	 * @param string   $name        The secret's name.
	 * @param string   $fingerprint A stable per-site digest.
	 * @param WP_Error $reason      Why the value cannot be produced.
	 * @return WP_Secret
	 */
	public static function withheld( string $name, string $fingerprint, WP_Error $reason ): WP_Secret {}

	/**
	 * Returns the keyed fingerprint of the plaintext.
	 *
	 * @return string
	 */
	public function fingerprint(): string {}

	/**
	 * Returns the secret's namespaced name.
	 *
	 * @return string
	 */
	public function get_name(): string {}

	/**
	 * Masks the instance for string conversion.
	 *
	 * @return string
	 */
	public function __toString(): string {}

	/**
	 * Masks the instance for json_encode().
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {}
}

/**
 * Validates a secret's name.
 *
 * @param string $name Candidate secret name.
 * @return true|WP_Error
 */
function wp_secrets_validate_name( string $name ) {}

/**
 * Encrypts and stores a secret.
 *
 * @param string $name  The secret's namespaced name ('plugin-slug/secret-name').
 * @param string $value The plaintext value to store.
 * @return true|WP_Error
 */
function wp_set_secret( string $name, $value ) {}

/**
 * Retrieves a secret.
 *
 * @param string $name    The secret's namespaced name.
 * @param string $version A WP_Secret_Version constant.
 * @return WP_Secret|null|WP_Error
 */
function wp_get_secret( string $name, string $version = WP_Secret_Version::CURRENT ) {}

/**
 * Deletes a secret.
 *
 * @param string $name The secret's namespaced name.
 * @return true|WP_Error
 */
function wp_delete_secret( string $name ) {}

/**
 * Lists secrets by name and metadata. Never a value.
 *
 * @param string $namespace Only secrets whose name starts with "{$namespace}/". Default '' returns every secret.
 * @return list<array{name: string, fingerprint: string, created: int, has_previous: bool, needs_rotation: bool}>|WP_Error
 */
function wp_list_secrets( string $namespace = '' ) {} // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.namespaceFound

/**
 * Whether the active provider accepts writes.
 *
 * @return bool
 */
function wp_secrets_provider_is_writable(): bool {}

/**
 * Whether a secrets.php drop-in is present and was loaded.
 *
 * @return bool
 */
function wp_using_secrets_dropin(): bool {}
