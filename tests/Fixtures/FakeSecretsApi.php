<?php
/**
 * The place "secrets_api" for tests, with a Secrets API of its own.
 *
 * WordPress keeps the provider of its Secrets API for the whole request, so
 * a test cannot switch to a read-only provider or to one, which withholds
 * values. This class replaces the calls to the Secrets API with an
 * in-memory store, whose behavior a test chooses.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use CryptForWordPress\Places\SecretsApi;
use WP_Error;
use WP_Secret;

/**
 * Object of the place "secrets_api" with an in-memory Secrets API.
 */
class FakeSecretsApi extends SecretsApi {

	/**
	 * The stored secrets: name => value.
	 *
	 * @var array<string,string>
	 */
	public static array $secrets = array();

	/**
	 * Whether the Secrets API is available.
	 *
	 * @var bool
	 */
	public static bool $available = true;

	/**
	 * Whether the provider accepts new secrets.
	 *
	 * @var bool
	 */
	public static bool $writable = true;

	/**
	 * Whether the provider withholds the values of existing secrets.
	 *
	 * @var bool
	 */
	public static bool $withheld = false;

	/**
	 * Whether the provider is unreachable: every call returns an error.
	 *
	 * @var bool
	 */
	public static bool $unreachable = false;

	/**
	 * Whether saving a secret fails.
	 *
	 * @var bool
	 */
	public static bool $set_fails = false;

	/**
	 * Restore the default behavior.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$secrets     = array();
		self::$available   = true;
		self::$writable    = true;
		self::$withheld    = false;
		self::$unreachable = false;
		self::$set_fails   = false;
	}

	/**
	 * Return whether the Secrets API can be used by this object.
	 *
	 * @return bool
	 */
	protected function is_available(): bool {
		return self::$available;
	}

	/**
	 * Return the secret with the given name.
	 *
	 * @param string $name The name of the secret.
	 *
	 * @return WP_Secret|WP_Error|null
	 */
	protected function api_get( string $name ): WP_Secret|WP_Error|null {
		if ( self::$unreachable ) {
			return new WP_Error( 'secret_store_unavailable', 'The store is unreachable.' );
		}

		if ( ! isset( self::$secrets[ $name ] ) ) {
			return null;
		}

		$fingerprint = substr( hash( 'sha256', self::$secrets[ $name ] ), 0, 32 );

		if ( self::$withheld ) {
			return WP_Secret::withheld( $name, $fingerprint, new WP_Error( 'secret_withheld', 'The value is not released to PHP.' ) );
		}

		return new WP_Secret( $name, self::$secrets[ $name ], $fingerprint );
	}

	/**
	 * Save a secret.
	 *
	 * @param string $name  The name of the secret.
	 * @param string $value The value.
	 *
	 * @return true|WP_Error
	 */
	protected function api_set( string $name, #[\SensitiveParameter] string $value ): true|WP_Error {
		if ( self::$unreachable || self::$set_fails ) {
			return new WP_Error( 'secret_store_unavailable', 'The store is unreachable.' );
		}

		if ( ! self::$writable ) {
			return new WP_Error( 'secret_provider_read_only', 'The provider is read-only.' );
		}

		self::$secrets[ $name ] = $value;

		return true;
	}

	/**
	 * Delete a secret.
	 *
	 * @param string $name The name of the secret.
	 *
	 * @return void
	 */
	protected function api_delete( string $name ): void {
		unset( self::$secrets[ $name ] );
	}

	/**
	 * Return the secrets of the given namespace.
	 *
	 * @param string $secret_namespace The namespace.
	 *
	 * @return array<int,mixed>|WP_Error
	 */
	protected function api_list( string $secret_namespace ): array|WP_Error {
		if ( self::$unreachable ) {
			return new WP_Error( 'secret_store_unavailable', 'The store is unreachable.' );
		}

		$list = array();
		foreach ( array_keys( self::$secrets ) as $name ) {
			if ( str_starts_with( $name, $secret_namespace . '/' ) ) {
				$list[] = array( 'name' => $name );
			}
		}

		return $list;
	}

	/**
	 * Return whether the provider accepts new secrets.
	 *
	 * @return bool
	 */
	protected function api_is_writable(): bool {
		return self::$writable;
	}
}
