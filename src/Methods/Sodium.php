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
	 * encrypted payload, so decrypt() always knows, which algorithm and
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
	 * @return void
	 * @throws SodiumException On Exception through Sodium.
	 * @throws Exception Could throw exception.
	 */
	public function init(): void {
		// get the hash.
		if ( $this->is_hash_saved() ) {
			$this->set_hash( sodium_base642bin( $this->get_hash_value_from_constant(), $this->get_coding_id() ) ); // @phpstan-ignore constant.notFound
		}

		// bail if hash is set.
		if ( ! empty( $this->get_hash() ) ) {
			return;
		}

		// get hash from the old db entry.
		$this->set_hash( sodium_base642bin( get_option( $this->get_crypt_obj()->get_slug() . '_sodium_hash', '' ), $this->get_coding_id() ) );

		// if no hash is set, create one.
		if ( empty( $this->get_hash() ) ) {
			// get the hash depending on the setting.
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
		}

		// save the hash in its place.
		$this->get_crypt_obj()->save_in_place( $this->get_constant(), $this->get_hash_value() );

		// delete the old option field.
		delete_option( $this->get_crypt_obj()->get_slug() . '_sodium_hash' );

		// run the constant for this process.
		$this->run_constant();
	}

	/**
	 * Return the name of used constant for our hash.
	 *
	 * @return string
	 */
	protected function get_constant(): string {
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
	 *
	 * @return string|false The ciphertext, or false if the algorithm is unavailable.
	 * @throws SodiumException Could throw sodium exception.
	 */
	private function encrypt_with( int $algorithm, string $plain_text, string $nonce ): false|string {
		switch ( $algorithm ) {
			case self::ALGO_AEGIS256:
				return function_exists( 'sodium_crypto_aead_aegis256_encrypt' )
					? sodium_crypto_aead_aegis256_encrypt( $plain_text, '', $nonce, $this->get_hash() )
					: false;
			case self::ALGO_AES256GCM:
				return function_exists( 'sodium_crypto_aead_aes256gcm_encrypt' )
					? sodium_crypto_aead_aes256gcm_encrypt( $plain_text, '', $nonce, $this->get_hash() )
					: false;
			case self::ALGO_XCHACHA20POLY1305:
				return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
					? sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain_text, '', $nonce, $this->get_hash() )
					: false;
			case self::ALGO_CHACHA20POLY1305:
				return function_exists( 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt' )
					? sodium_crypto_aead_chacha20poly1305_ietf_encrypt( $plain_text, '', $nonce, $this->get_hash() )
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
	 *
	 * @return string|false The plaintext, or false if it could not be decrypted.
	 * @throws SodiumException|RuntimeException Could throw exception.
	 */
	private function decrypt_with( int $algorithm, string $ciphertext, string $nonce ): false|string {
		switch ( $algorithm ) {
			case self::ALGO_AEGIS256:
				if ( ! function_exists( 'sodium_crypto_aead_aegis256_decrypt' ) ) {
					throw new RuntimeException( 'AEGIS-256 wird von diesem Server nicht unterstützt (libsodium-Upgrade nötig), kann diesen Wert aber nicht entschlüsseln.' );
				}
				return sodium_crypto_aead_aegis256_decrypt( $ciphertext, '', $nonce, $this->get_hash() );
			case self::ALGO_AES256GCM:
				if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_decrypt' ) ) {
					throw new RuntimeException( 'AES-256-GCM wird von diesem Server nicht unterstützt, kann diesen Wert aber nicht entschlüsseln.' );
				}
				return sodium_crypto_aead_aes256gcm_decrypt( $ciphertext, '', $nonce, $this->get_hash() );
			case self::ALGO_XCHACHA20POLY1305:
				if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
					throw new RuntimeException( 'XChaCha20-Poly1305 wird von diesem Server nicht unterstützt, kann diesen Wert aber nicht entschlüsseln.' );
				}
				return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, '', $nonce, $this->get_hash() );
			case self::ALGO_CHACHA20POLY1305:
				if ( ! function_exists( 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt' ) ) {
					throw new RuntimeException( 'ChaCha20-Poly1305 wird von diesem Server nicht unterstützt, kann diesen Wert aber nicht entschlüsseln.' );
				}
				return sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $ciphertext, '', $nonce, $this->get_hash() );
			default:
				throw new RuntimeException( 'Unbekanntes Algorithmus-Tier im verschlüsselten Wert.' );
		}
	}

	/**
	 * Encrypt a given string.
	 *
	 * @access private
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt( string $plain_text ): string {
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

		try {
			// pick the best algorithm tier this server actually supports.
			$algorithm = $this->detect_algorithm();

			// nonce length depends on the algorithm, never hardcoded.
			$nonce = random_bytes( self::NONCE_LENGTHS[ $algorithm ] );

			// get the algorithm to use.
			$encrypted_text = $this->encrypt_with( $algorithm, $plain_text, $nonce );

			if ( false === $encrypted_text ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_slug_missing',
					'No supported Sodium AEAD algorithm found on this hosting.',
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
				'sodium_slug_missing',
				'Error during encrypting via sodium: ' . wp_kses_post( $e->getMessage() ),
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

		try {
			// get the payload.
			$payload = sodium_base642bin( $encrypted_text, $this->get_coding_id() );

			// need at least the algo byte + the smallest possible nonce.
			if ( strlen( $payload ) < 2 ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_payload_not_set',
					'Sodium payload is not set in encrypted string.',
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
					)
				);

				// do nothing more.
				return '';
			}

			// get the nonce.
			$nonce      = substr( $payload, 1, $nonce_length );
			$ciphertext = substr( $payload, 1 + $nonce_length );

			$decrypted = $this->decrypt_with( $algorithm, $ciphertext, $nonce );

			// bail if the decrypted text is not a string.
			if ( ! is_string( $decrypted ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'sodium_decrypt_error',
					'Decrypted string is not a string'
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
				'Error during decrypting via sodium: ' . wp_kses_post( $e->getMessage() )
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
	 * Uninstall this method.
	 *
	 * @return void
	 * @throws SodiumException On Exception through Sodium.
	 */
	public function uninstall(): void {
		// initiate the method to get the actual hash.
		$this->init();

		// save the hash in the database.
		update_option( $this->get_crypt_obj()->get_slug() . '_sodium_hash', $this->get_hash_value() );

		// run the parent uninstall tasks.
		parent::uninstall();
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
