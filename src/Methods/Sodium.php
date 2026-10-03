<?php
/**
 * File to handle sodium-tasks.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Methods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Method_Base;
use Exception;
use RuntimeException;
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
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array(
		'hash_type' => 'sodium_crypto_aead_xchacha20poly1305_ietf_keygen',
	);

	/**
	 * Algorithm-tier identifiers used as a single-byte prefix in the
	 * encrypted payload, so decrypt() always knows which algorithm and
	 * nonce length were used - independent of what the *current* server
	 * happens to support. This is what makes values portable across
	 * server migrations / libsodium upgrades.
	 */
	private const ALGO_AEGIS256          = 1;
	private const ALGO_AES256GCM         = 2;
	private const ALGO_XCHACHA20POLY1305 = 3;
	private const ALGO_CHACHA20POLY1305  = 4;

	/**
	 * Nonce lengths per algorithm tier, in bytes.
	 *
	 * @var array<int,int>
	 */
	private const NONCE_LENGTHS = array(
		self::ALGO_AEGIS256          => 32, // SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES.
		self::ALGO_AES256GCM         => 12, // SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES.
		self::ALGO_XCHACHA20POLY1305 => 24, // SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES.
		self::ALGO_CHACHA20POLY1305  => 8,  // SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES.
	);

	/**
	 * Initialize the object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Initiate this method.
	 *
	 * Looks for the key in this order: the constant (set by the place or by
	 * WordPress itself), the option an uninstallation left behind, a key the
	 * place derives itself, a place which is not the active one anymore.
	 * Only if none of them has a key, a new one is generated - and this is
	 * reported, if this installation had a key before.
	 *
	 * @return void
	 * @throws SodiumException On Exception through Sodium.
	 * @throws Exception Could throw exception.
	 */
	public function init(): void {
		try {
			// get the hash.
			if ( $this->is_hash_saved() ) {
				$this->set_hash( sodium_base642bin( $this->get_hash_value_from_constant(), $this->get_coding_id() ) ); // @phpstan-ignore constant.notFound
			}

			// bail if hash is set: use it.
			if ( ! empty( $this->get_hash() ) ) {
				$this->confirm_key();
				return;
			}

			// let the place derive the key, if it can.
			$raw_key = $this->get_derived_key();
			if ( '' !== $raw_key ) {
				// this method works on raw key bytes, so no encoding is needed.
				$this->set_hash( $raw_key );

				// a derived key is recreated on every request: it must not be
				// written into a place. It is the key of this place, so it also
				// wins over a key an uninstallation may have left in the database
				// - which this place could never take over.
				$this->confirm_key();
				return;
			}

			// get hash from the old db entry.
			$option_name = $this->get_crypt_obj()->get_slug() . '_sodium_hash';
			$option_hash = get_option( $option_name, '' );
			$this->set_hash( sodium_base642bin( is_string( $option_hash ) ? $option_hash : '', $this->get_coding_id() ) );

			// move it into its place.
			if ( ! empty( $this->get_hash() ) ) {
				$this->confirm_key();

				// delete the old option field only if the place really holds the
				// key now - otherwise the option stays the only copy of it.
				if ( $this->persist_key() ) {
					delete_option( $option_name );
				}

				// run the constant for this process.
				$this->run_constant();

				// do nothing more.
				return;
			}

			// the key may still be in a place, which has been the active one
			// before (e.g., wp-config.php became writable after the key had
			// been saved in the database).
			$this->set_hash( sodium_base642bin( $this->get_stored_key_from_inactive_places(), $this->get_coding_id() ) );
			if ( '' !== $this->get_hash() ) {
				// use it from there.
				$this->confirm_key();
				$this->run_constant();

				// do nothing more.
				return;
			}

			// if this installation had a key before, it is gone: never replace it silently.
			$this->report_missing_key();

			// no key has ever been used, create one depending on the setting.
			switch ( $this->configuration['hash_type'] ) {
				case 'sodium_crypto_secretbox_keygen':
					$hash = sodium_crypto_secretbox_keygen();
					break;
				case 'sodium_crypto_auth_keygen':
					$hash = sodium_crypto_auth_keygen();
					break;
				case 'sodium_crypto_generichash_keygen':
					$hash = sodium_crypto_generichash_keygen();
					break;
				case 'sodium_crypto_kdf_keygen':
					$hash = sodium_crypto_kdf_keygen();
					break;
				case 'random_bytes':
					$hash = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
					break;
				default:
					$hash = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
					break;
			}

			// set the hash.
			$this->set_hash( $hash );

			// bail if the new key could not be saved in its place.
			if ( ! $this->store_new_key() ) {
				return;
			}

			// run the constant for this process.
			$this->run_constant();

		} catch ( Exception $e ) {
			// never work with a key of an initialization that failed halfway.
			$this->set_hash( '' );

			// log this error.
			$this->get_crypt_obj()->add_error(
				'sodium_decrypt_error',
				'Error during decrypting via sodium: ' . wp_kses_post( $e->getMessage() )
			);
		}
	}

	/**
	 * Return the name of used constant for our hash.
	 *
	 * @return string
	 */
	public function get_constant(): string {
		$constant = strtoupper( $this->get_crypt_obj()->get_slug() ) . '-SODIUM-HASH';

		/**
		 * Filter the name of the constant.
		 *
		 * @since 1.1.2 Available since 1.1.2.
		 * @param string $constant The constant name.
		 */
		return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_constant', $constant );
	}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return extension_loaded( 'sodium' );
	}

	/**
	 * Determine the best available AEAD algorithm tier on this server.
	 *
	 * Order of preference: AEGIS-256 (fastest, libsodium >= 1.0.19) >
	 * AES-256-GCM (fast, but only if hardware-accelerated) > XChaCha20-
	 * Poly1305-IETF (safe default, no hardware dependency) >
	 * ChaCha20-Poly1305-IETF (legacy fallback).
	 *
	 * @return int One of the self::ALGO_* constants.
	 */
	private function detect_algorithm(): int {
		// use aegis256.
		if ( function_exists( 'sodium_crypto_aead_aegis256_encrypt' ) ) {
			return self::ALGO_AEGIS256;
		}

		// use aes256gcm.
		if ( function_exists( 'sodium_crypto_aead_aes256gcm_encrypt' ) && function_exists( 'sodium_crypto_aead_aes256gcm_is_available' ) && sodium_crypto_aead_aes256gcm_is_available() ) {
			return self::ALGO_AES256GCM;
		}

		// use xchacha20poly1305.
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return self::ALGO_XCHACHA20POLY1305;
		}

		// return the default value.
		return self::ALGO_CHACHA20POLY1305;
	}

	/**
	 * Encrypt raw bytes with a given algorithm tier.
	 *
	 * @param int    $algorithm One of the self::ALGO_* constants.
	 * @param string $plain_text The plain string.
	 * @param string $nonce The nonce to use (already correctly sized).
	 * @param string $context The context, authenticated as additional data.
	 *
	 * @return string|false The ciphertext, or false if the algorithm is unavailable.
	 * @throws SodiumException Could throw a sodium exception.
	 */
	private function encrypt_with( int $algorithm, #[\SensitiveParameter] string $plain_text, string $nonce, string $context ): false|string {
		switch ( $algorithm ) {
			case self::ALGO_AEGIS256:
				return function_exists( 'sodium_crypto_aead_aegis256_encrypt' )
					? sodium_crypto_aead_aegis256_encrypt( $plain_text, $context, $nonce, $this->get_hash() )
					: false;
			case self::ALGO_AES256GCM:
				return function_exists( 'sodium_crypto_aead_aes256gcm_encrypt' )
					? sodium_crypto_aead_aes256gcm_encrypt( $plain_text, $context, $nonce, $this->get_hash() )
					: false;
			case self::ALGO_XCHACHA20POLY1305:
				return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
					? sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain_text, $context, $nonce, $this->get_hash() )
					: false;
			case self::ALGO_CHACHA20POLY1305:
				return function_exists( 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt' )
					? sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $plain_text, $context, $nonce, $this->get_hash() )
					: false;
			default:
				return false;
		}
	}

	/**
	 * Decrypt raw bytes with a given algorithm tier.
	 *
	 * @param int    $algorithm One of the self::ALGO_* constants.
	 * @param string $ciphertext The ciphertext.
	 * @param string $nonce The nonce (already correctly sized for this algorithm).
	 * @param string $context The context, authenticated as additional data.
	 *
	 * @return string|false The plaintext, or false if it could not be decrypted.
	 * @throws SodiumException|RuntimeException Could throw exception.
	 */
	private function decrypt_with( int $algorithm, string $ciphertext, string $nonce, string $context ): false|string {
		switch ( $algorithm ) {
			case self::ALGO_AEGIS256:
				if ( ! function_exists( 'sodium_crypto_aead_aegis256_decrypt' ) ) {
					throw new RuntimeException( 'AEGIS-256 is not supported by this server (requires a libsodium upgrade), but it cannot decrypt this value.' );
				}
				return sodium_crypto_aead_aegis256_decrypt( $ciphertext, $context, $nonce, $this->get_hash() );
			case self::ALGO_AES256GCM:
				if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_decrypt' ) ) {
					throw new RuntimeException( 'AES-256-GCM is not supported by this server, but it cannot decrypt this value.' );
				}
				return sodium_crypto_aead_aes256gcm_decrypt( $ciphertext, $context, $nonce, $this->get_hash() );
			case self::ALGO_XCHACHA20POLY1305:
				if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
					throw new RuntimeException( 'XChaCha20-Poly1305 is not supported by this server, but it cannot decrypt this value.' );
				}
				return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $context, $nonce, $this->get_hash() );
			case self::ALGO_CHACHA20POLY1305:
				if ( ! function_exists( 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt' ) ) {
					throw new RuntimeException( 'ChaCha20-Poly1305 is not supported by this server, but it cannot decrypt this value.' );
				}
				return sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $ciphertext, $context, $nonce, $this->get_hash() );
			default:
				throw new RuntimeException( 'Unknown algorithm type in the encrypted value.' );
		}
	}

	/**
	 * Encrypt a given string.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt( #[\SensitiveParameter] string $plain_text ): string {
		return $this->encrypt_with_context( $plain_text, '' );
	}

	/**
	 * Encrypt a given string.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text The plain string.
	 * @param string $context    What the value belongs to, or an empty string. It is authenticated as additional data: the value can only be decrypted with the same context.
	 *
	 * @return string
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt_with_context( #[\SensitiveParameter] string $plain_text, string $context ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'sodium_slug_missing',
				'Plugin slug not set',
			);

			// do nothing more.
			return '';
		}

		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no key is available.
		if ( ! $this->has_key() ) {
			return '';
		}

		try {
			// pick the best algorithm tier this server actually supports.
			$algorithm = $this->detect_algorithm();

			// nonce length depends on the algorithm, never hardcoded.
			$nonce = random_bytes( self::NONCE_LENGTHS[ $algorithm ] );

			// get the algorithm to use.
			$encrypted_text = $this->encrypt_with( $algorithm, $plain_text, $nonce, $context );

			if ( false === $encrypted_text ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_no_algorithm',
					'No supported Sodium AEAD algorithm found on this hosting.',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// payload layout: [1 byte algo-id][nonce][ciphertext] - no separator
			// character is used, so binary ':' bytes in the nonce/ciphertext can
			// never corrupt the structure (unlike the previous explode(':', ...) approach).
			$payload = chr( $algorithm ) . $nonce . $encrypted_text;

			// return encrypted text as base64.
			return sodium_bin2base64( $payload, $this->get_coding_id() );
		} catch ( Exception $e ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'sodium_encrypt_error',
				'Error during encrypting via sodium: ' . wp_kses_post( $e->getMessage() ),
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}
	}

	/**
	 * Decrypt a string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 * @throws RuntimeException If an error occurred.
	 */
	public function decrypt( string $encrypted_text ): string {
		return $this->decrypt_with_context( $encrypted_text, '' );
	}

	/**
	 * Decrypt a string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 * @param string $context        The context the value has been encrypted with, or an empty string.
	 *
	 * @return string
	 * @throws RuntimeException If an error occurred.
	 */
	public function decrypt_with_context( string $encrypted_text, string $context ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'sodium_slug_missing',
				'Plugin slug not set',
			);

			// do nothing more.
			return '';
		}

		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no key is available.
		if ( ! $this->has_key() ) {
			return '';
		}

		try {
			// get the payload.
			$payload = sodium_base642bin( $encrypted_text, $this->get_coding_id() );

			// need at least the algo byte + the smallest possible nonce.
			if ( strlen( $payload ) < 2 ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_payload_not_set',
					'Sodium payload is not set in encrypted string.',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			$algorithm = ord( $payload[0] );

			if ( ! isset( self::NONCE_LENGTHS[ $algorithm ] ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_algorithm_unknown',
					'Given algorithm is unknown. Could not decrypt string.',
					array(
						'algorithm' => $algorithm,
						'context'   => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// get the length.
			$nonce_length = self::NONCE_LENGTHS[ $algorithm ];

			if ( strlen( $payload ) < 1 + $nonce_length ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_payload_mismatch',
					'Payload nonce for encrypted string does not match.',
					array(
						'algorithm' => $algorithm,
						'context'   => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// get the nonce.
			$nonce      = substr( $payload, 1, $nonce_length );
			$ciphertext = substr( $payload, 1 + $nonce_length );

			$decrypted = $this->decrypt_with( $algorithm, $ciphertext, $nonce, $context );

			// bail if the decrypted text is not a string.
			if ( ! is_string( $decrypted ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_decrypt_error',
					'Decrypted string is not a string',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// return the resulting decrypted string.
			return $decrypted;
		} catch ( Exception $e ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'sodium_decrypt_error',
				'Error during decrypting via sodium: ' . wp_kses_post( $e->getMessage() ),
				array(
					'context' => $context,
				)
			);

			// do nothing more.
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
	 * Return whether this method can do anything if no place is usable:
	 * only with a key.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return bool
	 */
	public function is_usable_without_place(): bool {
		return '' !== $this->get_hash();
	}

	/**
	 * Return the name of the option the key of this method is kept in
	 * during an uninstallation. A later installation reads it from there.
	 *
	 * @return string
	 */
	protected function get_uninstall_option_name(): string {
		return $this->get_crypt_obj()->get_slug() . '_sodium_hash';
	}

	/**
	 * Return whether this method can work with the given key from a
	 * constant: only with a key in its own format.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return bool
	 */
	protected function is_usable_stored_key( #[\SensitiveParameter] string $stored_key ): bool {
		return $this->is_valid_stored_key( $stored_key );
	}

	/**
	 * Return whether the given value is a key this method could have stored:
	 * 32 bytes, base64 encoded.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return bool
	 */
	protected function is_valid_stored_key( #[\SensitiveParameter] string $stored_key ): bool {
		try {
			return SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES === strlen( sodium_base642bin( $stored_key, $this->get_coding_id() ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Return the secured hash value.
	 *
	 * @return string
	 * @throws SodiumException On Exception through Sodium.
	 */
	public function get_hash_value(): string {
		return sodium_bin2base64( $this->get_hash(), $this->get_coding_id() );
	}
}
