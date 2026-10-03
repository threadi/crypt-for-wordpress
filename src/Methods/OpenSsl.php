<?php
/**
 * File to handle OpenSSL tasks.
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
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array(
		'hash_type'        => 'hash',
		'hash_algorithm'   => 'sha256',
		'cipher_algorithm' => 'AES-256-GCM',
	);

	/**
	 * Marker of the current payload format for ciphers without AEAD.
	 *
	 * Payloads without this marker have been written by older versions,
	 * whose HMAC did not cover the IV. They stay readable.
	 */
	private const NON_AEAD_FORMAT = 'v2';

	/**
	 * Placeholder in a list of keys to try for "no key at all".
	 *
	 * It is no key itself and never handed to the cipher. It stands for the
	 * empty key older versions used whenever they had no usable one.
	 */
	private const NO_KEY = "\0no-key\0";

	/**
	 * Length of the authentication tag of AEAD ciphers, in bytes.
	 *
	 * This is the full tag of AES-GCM and ChaCha20-Poly1305.
	 */
	private const AEAD_TAG_LENGTH = 16;

	/**
	 * Initialize the object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Constructor for this object.
	 *
	 * Looks for the key in this order: the constant (set by the place or by
	 * WordPress itself), the option an uninstallation left behind, a key the
	 * place derives itself, a place which is not the active one anymore.
	 * Only if none of them has a key, a new one is generated - and this is
	 * reported, if this installation had a key before.
	 *
	 * @return void
	 * @throws RuntimeException If an error occurred.
	 */
	public function init(): void {
		$this->set_hash( $this->get_hash_value_from_constant() );

		// bail if hash is set: use it.
		if ( ! empty( $this->get_hash() ) ) {
			$this->confirm_key();
			return;
		}

		// let the place derive the key, if it can.
		$raw_key = $this->get_derived_key();
		if ( '' !== $raw_key ) {
			// encode it the way this method reads its key back.
			$this->set_hash( $this->encode_key( $raw_key ) );

			// a derived key is recreated on every request: it must not be
			// written into a place. It is the key of this place, so it also
			// wins over a key an uninstallation may have left in the database
			// - which this place could never take over.
			$this->confirm_key();
			return;
		}

		// get hash from the database.
		$option_name = $this->get_crypt_obj()->get_slug() . '_hash';
		$option_hash = get_option( $option_name, '' );
		$this->set_hash( is_string( $option_hash ) ? $option_hash : '' );

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
		// before (e.g., wp-config.php became writable after the key had been
		// saved in the database).
		$this->set_hash( $this->get_stored_key_from_inactive_places() );
		if ( '' !== $this->get_hash() ) {
			// use it from there.
			$this->confirm_key();
			$this->run_constant();

			// do nothing more.
			return;
		}

		// if this installation had a key before, it is gone: never replace it silently.
		$this->report_missing_key();

		// no key has ever been used, create one.
		$this->set_hash( $this->generate_hash() );

		// bail if no key could be generated.
		if ( '' === $this->get_hash() ) {
			return;
		}

		// bail if the new key could not be saved in its place.
		if ( ! $this->store_new_key() ) {
			return;
		}

		// run the constant for this process.
		$this->run_constant();
	}

	/**
	 * Generate a new hash with the configured settings.
	 *
	 * @return string The hash, or an empty string on any error.
	 */
	private function generate_hash(): string {
		// get the settings to generate the hash with. PHP does not care how
		// the name of a hash algorithm is written, so it is compared in lower case.
		$hash_type      = $this->configuration['hash_type'];
		$hash_algorithm = is_string( $this->configuration['hash_algorithm'] ) ? strtolower( $this->configuration['hash_algorithm'] ) : '';

		// use the default if the configured hash algorithm does not exist, or
		// cannot be used for the configured hash type.
		if ( ! in_array( $hash_algorithm, 'hash_pbkdf2' === $hash_type ? hash_hmac_algos() : hash_algos(), true ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_hash_algo_unknown',
				'Unknown hash algorithm.',
				array(
					'hash_algorithm' => $this->configuration['hash_algorithm'],
				)
			);

			// a key is generated all the same: without one, older versions
			// "encrypted" with an empty key.
			$hash_algorithm = 'sha256';
		}

		// use the default if the hash type is unknown.
		if ( ! $this->is_hash_type_known() ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_hash_algo_unknown',
				'Unknown hash type.',
				array(
					'hash_type' => $hash_type,
				)
			);

			// a key is generated all the same, in the format decode_key() expects.
			$hash_type = 'hash';
		}

		// use hash_pbkdf2() to generate the hash.
		if ( 'hash_pbkdf2' === $hash_type ) {
			try {
				return base64_encode(
					hash_pbkdf2(
						$hash_algorithm,
						random_bytes( 32 ),
						wp_salt(),
						150000,
						32,
						true
					)
				);
			} catch ( Exception $e ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_hash_type_error',
					'Secure random number source not available – Installation cannot be initialized securely:' . wp_kses_post( $e->getMessage() ),
					array(
						'hash_type' => $hash_type,
					)
				);

				// do nothing more.
				return '';
			}
		}

		// use hash() to generate the hash.
		try {
			return hash( $hash_algorithm, random_bytes( 32 ) );
		} catch ( Exception $e ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_hash_algo_error',
				'Error during generating the hash algorithm:' . wp_kses_post( $e->getMessage() ),
				array(
					'hash_algorithm' => $hash_algorithm,
				)
			);

			// do nothing more.
			return '';
		}
	}

	/**
	 * Return whether PHP can derive keys and calculate an HMAC with the
	 * configured hash algorithm.
	 *
	 * PHP does not care how its name is written: "SHA256" works like
	 * "sha256". But not every hash algorithm can be used for this.
	 *
	 * @return bool
	 */
	private function is_hash_algorithm_usable(): bool {
		return is_string( $this->configuration['hash_algorithm'] ) && in_array( strtolower( $this->configuration['hash_algorithm'] ), hash_hmac_algos(), true );
	}

	/**
	 * Return whether the configured hash type is one this method knows.
	 *
	 * @return bool
	 */
	private function is_hash_type_known(): bool {
		return in_array( $this->configuration['hash_type'], array( 'hash', 'hash_pbkdf2' ), true );
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
	 * @param string $plain_text Text to encrypt.
	 * @param string $context    What the value belongs to, or an empty string. It is authenticated together with the value: the value can only be decrypted with the same context.
	 *
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt_with_context( #[\SensitiveParameter] string $plain_text, string $context ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_slug_missing',
				'Plugin slug not set',
			);

			// do nothing more.
			return '';
		}

		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no text is given.
		if ( '' === $plain_text ) {
			return '';
		}

		// bail if no key is available.
		if ( ! $this->has_key() ) {
			return '';
		}

		// get the cipher algorithm.
		$cipher = $this->configuration['cipher_algorithm'];

		// bail if the configured cipher is not available.
		if ( ! in_array( strtolower( $cipher ), openssl_get_cipher_methods(), true ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_cipher_algo_unknown',
				'Unknown cipher algorithm.',
				array(
					'cipher_algorithm' => $cipher,
					'context'          => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// gets the cipher iv length.
		$iv_length = openssl_cipher_iv_length( $cipher );

		// bail if iv length could not be loaded.
		if ( ! is_int( $iv_length ) ) { // @phpstan-ignore function.alreadyNarrowedType
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_iv_length_error',
				'IV length could not be generated.',
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// get the iv.
		$iv = openssl_random_pseudo_bytes( $iv_length, $crypto_strong );

		// bail if iv could not be created.
		if ( ! $iv || ! $crypto_strong ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_iv_error',
				'IV could not be generated.',
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// get the key to encrypt with.
		$hash = $this->get_master_key();

		// bail if there is none: an empty key must never be handed to the cipher.
		if ( '' === $hash ) {
			return '';
		}

		// handle GCM-based ciphers.
		if ( $this->should_use_aead_tag( $cipher ) ) {
			// set the tag.
			$tag = '';

			// encrypt the string, with the context as additional authenticated data.
			$ciphertext = openssl_encrypt(
				$plain_text,
				$cipher,
				$hash,
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				$context,
				self::AEAD_TAG_LENGTH
			);

			// bail if text could not be encrypted.
			if ( ! is_string( $ciphertext ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_encrypt_error',
					'Given string could not be encrypted.',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// return the resulting encrypted string.
			return base64_encode(
				base64_encode( $iv ) . ':' .
				base64_encode( $tag ) . ':' . // @phpstan-ignore argument.type
				base64_encode( $ciphertext )
			);
		}

		// bail if the configured hash algorithm does not exist: neither the
		// keys nor the HMAC can be calculated with it.
		if ( ! $this->is_hash_algorithm_usable() ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_hash_algo_unknown',
				'Unknown hash algorithm.',
				array(
					'hash_algorithm' => $this->configuration['hash_algorithm'],
					'context'        => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// get two keys from the main key. They belong to this payload format
		// only: sharing them with the formats of older versions would allow
		// passing a value of this format off as one of those.
		$enc_key  = $this->derive_key( 'encryption-' . self::NON_AEAD_FORMAT, 32, $hash );
		$hmac_key = $this->derive_key( 'authentication-' . self::NON_AEAD_FORMAT, 32, $hash );

		// encrypt the string.
		$ciphertext_raw = openssl_encrypt(
			$plain_text,
			$cipher,
			$enc_key,
			OPENSSL_RAW_DATA,
			$iv
		);

		// bail if anything failed.
		if ( ! $ciphertext_raw ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_encrypt_error',
				'Given string could not be encrypted.',
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// get the HMAC - over the IV and the context as well, not only over the ciphertext.
		$hmac = hash_hmac( $this->configuration['hash_algorithm'], $this->get_mac_input( $iv, $ciphertext_raw, $context ), $hmac_key, true );

		// return the resulting encrypted string, marked with its format version.
		return base64_encode( self::NON_AEAD_FORMAT . ':' . base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );
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
	 * Decrypted a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 * @param string $context        The context the value has been encrypted with, or an empty string.
	 *
	 * @return string
	 * @throws RuntimeException If the cipher is unknown, or the stored key is invalid.
	 */
	public function decrypt_with_context( string $encrypted_text, string $context ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_slug_missing',
				'Plugin slug not set',
			);

			// do nothing more.
			return '';
		}

		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no text is given.
		if ( empty( $encrypted_text ) ) {
			return '';
		}

		// report it if no key is available. Do not bail yet: older versions
		// "encrypted" in this state as well, and what they wrote has to stay
		// readable (see accepts_unprotected_values()).
		if ( ! $this->has_key() && ! $this->accepts_unprotected_values() ) {
			return '';
		}

		// get the cipher algorithm.
		$cipher = $this->configuration['cipher_algorithm'];

		// bail if the configured cipher is not available.
		if ( ! in_array( strtolower( $cipher ), openssl_get_cipher_methods(), true ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_cipher_algo_unknown',
				'Unknown cipher algorithm.',
				array(
					'cipher_algorithm' => $cipher,
					'context'          => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// gets the cipher iv length.
		$iv_length = openssl_cipher_iv_length( $cipher );

		// bail if iv length could not be loaded.
		if ( ! is_int( $iv_length ) ) { // @phpstan-ignore function.alreadyNarrowedType
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_iv_length_error',
				'IV length could not be generated.',
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// decode the encrypted text.
		$c = base64_decode( $encrypted_text, true );

		// bail if the given text is not valid base64.
		if ( ! is_string( $c ) || '' === $c ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_decrypt_payload_invalid',
				'Encrypted string is not valid base64.',
				array(
					'context' => $context,
				)
			);
			// do nothing more.
			return '';
		}

		// get the key to decrypt with.
		$hash = $this->get_master_key();

		// the reason the first, non-legacy attempt failed - reported only if
		// every attempt in the fallback chain below fails.
		$first_error = '';

		// prepare the original plaintext.
		$original_plaintext = '';

		// handle GCM-based ciphers.
		if ( $this->should_use_aead_tag( $cipher ) ) {
			// get the parts.
			$c_exploded = explode( ':', $c );

			// bail if parts are not given.
			if ( 3 !== count( $c_exploded ) ) {
				$this->get_crypt_obj()->add_error(
					'openssl_decrypt_missing_parts',
					'Encrypted string does not match the expected AEAD format.',
					array(
						'parts'   => count( $c_exploded ),
						'context' => $context,
					)
				);
				return '';
			}

			// get the part contents.
			$iv         = base64_decode( $c_exploded[0], true );
			$tag        = base64_decode( $c_exploded[1], true );
			$ciphertext = base64_decode( $c_exploded[2], true );

			// bail if any part could not be decoded.
			if ( ! is_string( $iv ) || ! is_string( $tag ) || ! is_string( $ciphertext ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_decrypt_encrypted_parts_missing',
					'Parts of the encrypted string could not be decoded.',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// bail if IV or tag do not have the length this method writes.
			// encrypt() always creates the full tag. OpenSSL would accept a
			// shorter one as well - and with every byte cut off, forging a
			// value becomes 256 times easier.
			if ( strlen( $iv ) !== $iv_length || self::AEAD_TAG_LENGTH !== strlen( $tag ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_decrypt_iv_invalid',
					'IV or AEAD tag of the encrypted string have an unexpected length.',
					array(
						'iv_length'  => strlen( $iv ),
						'tag_length' => strlen( $tag ),
						'context'    => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// the current (raw-byte) key first, then the original legacy key (raw, undecoded hash string).
			$keys = array( $hash, $this->get_hash() );

			// at last no key at all, for values older versions wrote without one.
			if ( $this->accepts_unprotected_values() ) {
				$keys[] = self::NO_KEY;
			}

			foreach ( $keys as $key ) {
				// bail if this key is not available.
				if ( '' === $key ) {
					continue;
				}

				$attempt_error      = '';
				$original_plaintext = $this->try_decrypt_aead( $cipher, $ciphertext, $iv, $tag, self::NO_KEY === $key ? '' : $key, $context, $attempt_error );

				// stop at the first attempt that worked.
				if ( '' !== $original_plaintext ) {
					// report a value which has never been protected.
					if ( self::NO_KEY === $key ) {
						$this->report_unprotected_value( $context );
					}

					break;
				}

				// remember why the first, non-legacy attempt failed.
				if ( '' === $first_error ) {
					$first_error = $attempt_error;
				}
			}
		} else {
			// for all other ciphers.

			// bail if the configured hash algorithm does not exist: nothing
			// can be verified with it.
			if ( ! $this->is_hash_algorithm_usable() ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_hash_algo_unknown',
					'Unknown hash algorithm.',
					array(
						'hash_algorithm' => $this->configuration['hash_algorithm'],
						'context'        => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// the current format authenticates the IV and is marked as such. It
			// has keys of its own and has never been written with one of the
			// legacy keys, so there is no fallback chain for it.
			$c_exploded = explode( ':', $c );
			if ( 3 === count( $c_exploded ) && self::NON_AEAD_FORMAT === $c_exploded[0] ) {
				return $this->decrypt_non_aead(
					$cipher,
					$c_exploded[1],
					$c_exploded[2],
					$iv_length,
					$this->derive_key( 'encryption-' . self::NON_AEAD_FORMAT, 32, $hash ),
					$this->derive_key( 'authentication-' . self::NON_AEAD_FORMAT, 32, $hash ),
					$context
				);
			}

			// get the two keys the previous format derived from the main key.
			$enc_key  = $this->derive_key( 'encryption', 32, $hash );
			$hmac_key = $this->derive_key( 'authentication', 32, $hash );

			// bail if a context is given: the formats of older versions cannot
			// be bound to one, so this value can never have been encrypted with it.
			if ( '' !== $context ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_decrypt_context_unsupported',
					'The encrypted string has been written by an older version in a format, which cannot be bound to a context. Decrypt it without context and encrypt it again.',
					array(
						'context' => $context,
					)
				);

				// do nothing more.
				return '';
			}

			// backwards-compatibility for strings that does not contain ":".
			if ( str_contains( $c, ':' ) ) {
				// get the parts.
				$c_exploded = explode( ':', $c );

				// get IV.
				$iv = base64_decode( $c_exploded[0] );

				// bail if IV could not be loaded.
				if ( ! $iv ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'openssl_iv_decrypt_error',
						'IV could not be read.',
						array(
							'context' => $context,
						)
					);

					// do nothing more.
					return '';
				}

				// get IV part.
				$iv = substr( $iv, 0, $iv_length );

				// get HMAC part.
				$c = base64_decode( $c_exploded[1] );

				// bail if HMAC part could not be loaded.
				if ( ! $c ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'openssl_hmac_decrypt_error',
						'HMAC could not be read.',
						array(
							'context' => $context,
						)
					);

					// do nothing more.
					return '';
				}

				// get the sha2 length.
				$sha2len = strlen( hash( $this->configuration['hash_algorithm'], '', true ) );

				// get HMAC.
				$hmac = substr( $c, 0, $sha2len );

				// get the raw cipher text.
				$ciphertext_raw = substr( $c, $sha2len, strlen( $c ) );
			} else {
				$iv             = substr( $c, 0, $iv_length );
				$hmac           = substr( $c, $iv_length, $sha2len = 32 );
				$ciphertext_raw = substr( $c, $iv_length + $sha2len );

				// bail if the decoded payload was too short to even contain a
				// full IV - openssl_decrypt() would raise a PHP warning otherwise.
				if ( strlen( $iv ) !== $iv_length ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'openssl_decrypt_iv_nonaead_invalid',
						'Encrypted string is too short to contain a valid IV.',
						array(
							'iv_length' => strlen( $iv ),
							'context'   => $context,
						)
					);

					// do nothing more.
					return '';
				}
			}

			// the current key-separation scheme first, then the decoded
			// single-key scheme (state B), then the very original scheme with
			// the undecoded hash string used directly (state A).
			$legacy_key = $this->get_hash();
			$key_pairs  = array(
				array( $enc_key, $hmac_key ),
				array( $hash, $hash ),
				array( $legacy_key, $legacy_key ),
			);

			// at last no key at all, for values older versions wrote without one.
			if ( $this->accepts_unprotected_values() ) {
				$key_pairs[] = array( self::NO_KEY, self::NO_KEY );
			}

			foreach ( $key_pairs as $key_pair ) {
				// bail if this key is not available.
				if ( '' === $key_pair[0] || '' === $key_pair[1] ) {
					continue;
				}

				$is_unprotected = self::NO_KEY === $key_pair[0];

				$attempt_error      = '';
				$original_plaintext = $this->try_decrypt_non_aead( $cipher, $ciphertext_raw, $iv, $hmac, $is_unprotected ? '' : $key_pair[0], $is_unprotected ? '' : $key_pair[1], $attempt_error );

				// stop at the first attempt that worked.
				if ( '' !== $original_plaintext ) {
					// report a value which has never been protected.
					if ( $is_unprotected ) {
						$this->report_unprotected_value( $context );
					}

					break;
				}

				// remember why the first, non-legacy attempt failed.
				if ( '' === $first_error ) {
					$first_error = $attempt_error;
				}
			}
		}

		// bail if every attempt failed.
		if ( '' === $original_plaintext ) {
			// report the failure exactly once, now that no fallback is left.
			$this->add_decrypt_error( $first_error, $context );

			// do nothing more.
			return '';
		}

		// return the resulting decrypted string.
		return $original_plaintext;
	}

	/**
	 * Return whether this method can do anything if no place is usable:
	 * only with a key, or to read values older versions wrote without one.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return bool
	 */
	public function is_usable_without_place(): bool {
		return '' !== $this->get_hash() || $this->accepts_unprotected_values();
	}

	/**
	 * Return the name of the option the key of this method is kept in
	 * during an uninstallation. A later installation reads it from there.
	 *
	 * @return string
	 */
	protected function get_uninstall_option_name(): string {
		return $this->get_crypt_obj()->get_slug() . '_hash';
	}

	/**
	 * Return whether the given value is a key this method could have stored
	 * with its current configuration.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return bool
	 */
	protected function is_valid_stored_key( #[\SensitiveParameter] string $stored_key ): bool {
		return '' !== $this->decode_key( $stored_key );
	}

	/**
	 * Decode a stored key into raw bytes, depending on the configured hash type.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return string The raw key, or an empty string if it cannot be decoded.
	 */
	private function decode_key( #[\SensitiveParameter] string $stored_key ): string {
		// the pbkdf2 hash type is stored base64 encoded.
		if ( 'hash_pbkdf2' === $this->configuration['hash_type'] ) {
			$decoded = base64_decode( $stored_key, true );

			return is_string( $decoded ) ? $decoded : '';
		}

		// everything else is stored hex encoded. Check it first, as
		// hex2bin() raises a PHP warning for anything else.
		if ( '' === $stored_key || 0 !== strlen( $stored_key ) % 2 || ! ctype_xdigit( $stored_key ) ) {
			return '';
		}

		return (string) hex2bin( $stored_key );
	}

	/**
	 * Return whether the given cipher should use AEAD with a tag.
	 *
	 * @param string $cipher The cipher name.
	 * @return bool
	 */
	private function should_use_aead_tag( string $cipher ): bool {
		return str_contains( strtolower( $cipher ), 'gcm' ) || str_contains( strtolower( $cipher ), 'poly1305' );
	}

	/**
	 * Derive a purpose-specific subkey from the master secret via HKDF.
	 *
	 * @param string $purpose The context label (e.g. 'encryption', 'authentication').
	 * @param int    $length Desired key length in bytes.
	 * @param string $hash The hash to use.
	 *
	 * @phpstan-param int<0, max> $length
	 *
	 * @return string
	 */
	private function derive_key( string $purpose, int $length, #[\SensitiveParameter] string $hash ): string {
		// bail if hash is empty.
		if ( '' === $hash ) {
			return '';
		}

		// return the hash for the given purpose.
		return hash_hkdf(
			$this->configuration['hash_algorithm'],
			$hash,
			$length,
			$purpose
		);
	}

	/**
	 * Return the master key as raw bytes, or an empty string if there is none.
	 *
	 * A key this package generated is stored encoded and is decoded here. A
	 * key that cannot be decoded is a key someone chose himself - this is
	 * what the places for an environment or server variable ask for. It is
	 * hashed into a key of the right length.
	 *
	 * Older versions did not do that: they continued with the empty result
	 * of the failed decoding and "encrypted" with no key at all.
	 *
	 * @return string
	 */
	private function get_master_key(): string {
		// bail if there is no key.
		if ( '' === $this->get_hash() ) {
			return '';
		}

		// get the key depending on the used hash type.
		$decoded = $this->decode_key( $this->get_hash() );

		// use it as it is, or use a self-chosen key as passphrase.
		return '' !== $decoded ? $decoded : hash( 'sha256', $this->get_hash(), true );
	}

	/**
	 * Return whether values, which have been "encrypted" with no key at
	 * all, are accepted when decrypting.
	 *
	 * Older versions wrote such values whenever they had no usable key:
	 * - if the stored key could not be decoded (a self-chosen key from a
	 *   variable, or a key stored with another 'hash_type'),
	 * - if it decoded to "0", which they took for no key,
	 * - or if none could be generated with the given configuration (an
	 *   unknown 'hash_algorithm' or 'hash_type').
	 * These values are not protected, but they must stay readable -
	 * otherwise they would be lost.
	 *
	 * In any other case they are refused: with a usable key no version has
	 * ever written one, so it could only be a forged value.
	 *
	 * @return bool
	 */
	private function accepts_unprotected_values(): bool {
		// older versions could not generate a key with this configuration.
		// They compared the name of the hash algorithm exactly as it is
		// written, so "SHA256" was unknown to them as well.
		if ( ! $this->is_hash_type_known() || ! in_array( $this->configuration['hash_algorithm'], hash_algos(), true ) ) {
			return true;
		}

		// there is no key at all, for another reason.
		if ( '' === $this->get_hash() ) {
			return false;
		}

		// the key cannot be decoded, or has been taken for no key.
		return in_array( $this->decode_key( $this->get_hash() ), array( '', '0' ), true );
	}

	/**
	 * Report that a value has been decrypted which has never been protected.
	 *
	 * @param string $context The context the value has been requested with. Values of older versions have none.
	 *
	 * @return void
	 */
	private function report_unprotected_value( string $context ): void {
		$this->get_crypt_obj()->add_error(
			'openssl_unprotected_value',
			'This value has been written by an older version without a usable key, so it is not protected. Encrypt it again to protect it.',
			array(
				'context' => $context,
			)
		);
	}

	/**
	 * Try to decrypt an AEAD ciphertext with a given key.
	 *
	 * @param string $cipher     The cipher algorithm.
	 * @param string $ciphertext The raw ciphertext.
	 * @param string $iv         The IV.
	 * @param string $tag        The AEAD tag.
	 * @param string $key        Key to try.
	 * @param string $context    The context, authenticated as additional data.
	 * @param string $error_code Set to the reason this attempt failed, empty on success.
	 *
	 * @return string The decrypted plaintext, or '' on failure.
	 */
	private function try_decrypt_aead( string $cipher, string $ciphertext, string $iv, string $tag, #[\SensitiveParameter] string $key, string $context, string &$error_code ): string {
		// reset the reason of this attempt.
		$error_code = '';

		// decrypt the string.
		$plaintext = openssl_decrypt( $ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, $context );

		// bail if decryption failed.
		if ( ! is_string( $plaintext ) ) {
			// mark as error.
			$error_code = 'openssl_decrypt_aead_error';

			// do nothing more.
			return '';
		}

		return $plaintext;
	}

	/**
	 * Return everything the HMAC of a non-AEAD payload has to cover.
	 *
	 * With CBC the IV decides what the first block decrypts to. If it is not
	 * authenticated, the first block of the plaintext can be changed at will
	 * without the HMAC noticing.
	 *
	 * The context is covered as well, with its length in front of it: this
	 * way no part of the context can ever be mistaken for a part of the IV.
	 *
	 * @param string $iv             The IV.
	 * @param string $ciphertext_raw The raw ciphertext.
	 * @param string $context        The context the value belongs to.
	 *
	 * @return string
	 */
	private function get_mac_input( string $iv, string $ciphertext_raw, string $context ): string {
		return self::NON_AEAD_FORMAT . pack( 'J', strlen( $context ) ) . $context . $iv . $ciphertext_raw;
	}

	/**
	 * Decrypt a non-AEAD payload in the current format: verify first, then decrypt.
	 *
	 * @param string $cipher          The cipher algorithm.
	 * @param string $iv_encoded      The base64-encoded IV.
	 * @param string $payload_encoded The base64-encoded HMAC and ciphertext.
	 * @param int    $iv_length       The IV length the cipher expects.
	 * @param string $enc_key         Key used for decryption.
	 * @param string $hmac_key        Key used for HMAC verification.
	 * @param string $context         The context the value has been encrypted with.
	 *
	 * @return string The decrypted plaintext, or '' on failure.
	 */
	private function decrypt_non_aead( string $cipher, string $iv_encoded, string $payload_encoded, int $iv_length, #[\SensitiveParameter] string $enc_key, #[\SensitiveParameter] string $hmac_key, string $context ): string {
		// bail if the keys are not available. The reason has been logged already.
		if ( '' === $enc_key || '' === $hmac_key ) {
			return '';
		}

		// get the part contents.
		$iv      = base64_decode( $iv_encoded, true );
		$payload = base64_decode( $payload_encoded, true );

		// get the length of the HMAC.
		$hmac_length = strlen( hash( $this->configuration['hash_algorithm'], '', true ) );

		// bail if any part is missing or too short.
		if ( ! is_string( $iv ) || ! is_string( $payload ) || strlen( $iv ) !== $iv_length || strlen( $payload ) <= $hmac_length ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_decrypt_encrypted_parts_missing',
				'Parts of the encrypted string could not be decoded.',
				array(
					'context' => $context,
				)
			);

			// do nothing more.
			return '';
		}

		// get HMAC and the raw cipher text.
		$hmac           = substr( $payload, 0, $hmac_length );
		$ciphertext_raw = substr( $payload, $hmac_length );

		// bail if the HMAC does not match - before anything is decrypted.
		if ( ! hash_equals( hash_hmac( $this->configuration['hash_algorithm'], $this->get_mac_input( $iv, $ciphertext_raw, $context ), $hmac_key, true ), $hmac ) ) {
			$this->add_decrypt_error( 'openssl_decrypt_hmac_error', $context );
			return '';
		}

		// get the plain text.
		$plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $enc_key, OPENSSL_RAW_DATA, $iv );

		// bail if no text could be read.
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			$this->add_decrypt_error( 'openssl_decrypt_error', $context );
			return '';
		}

		// return the plain text.
		return $plaintext;
	}

	/**
	 * Try to decrypt and verify a non-AEAD ciphertext with a given key pair.
	 *
	 * @param string $cipher          The cipher algorithm.
	 * @param string $ciphertext_raw  The raw ciphertext.
	 * @param string $iv              The IV.
	 * @param string $hmac            The stored HMAC to verify against.
	 * @param string $enc_key         Key used for decryption.
	 * @param string $hmac_key        Key used for HMAC verification.
	 * @param string $error_code      Set to the reason this attempt failed, empty on success.
	 *
	 * @return string The decrypted plaintext, or '' on failure.
	 */
	private function try_decrypt_non_aead( string $cipher, string $ciphertext_raw, string $iv, string $hmac, #[\SensitiveParameter] string $enc_key, #[\SensitiveParameter] string $hmac_key, string &$error_code ): string {
		// reset the reason of this attempt.
		$error_code = '';

		// bail if hmac is empty.
		if ( empty( $hmac ) ) {
			return '';
		}

		// get the calculated HMAC.
		$calc_mac = hash_hmac( $this->configuration['hash_algorithm'], $ciphertext_raw, $hmac_key, true );

		// bail if hmac und calculated mac does not match - before anything is
		// decrypted. Decrypting first would tell valid from invalid padding
		// through the reported error, which is enough to read a value bit by bit.
		if ( ! hash_equals( $hmac, $calc_mac ) ) {
			// log this error.
			$error_code = 'openssl_decrypt_hmac_error';

			// do nothing more.
			return '';
		}

		// get the plain text.
		$plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $enc_key, OPENSSL_RAW_DATA, $iv );

		// bail if no text could be read.
		if ( ! is_string( $plaintext ) ) {
			// mark as error.
			$error_code = 'openssl_decrypt_error';

			// do nothing more.
			return '';
		}

		// return the plain text.
		return $plaintext;
	}

	/**
	 * Encode raw key material in the format this method expects to read it
	 * back in.
	 *
	 * Places, which derive a key themselves - instead of storing one this
	 * package generated - have to hand it over in the encoding of the method
	 * that will use it.
	 *
	 * @param string $raw_key The raw key material.
	 *
	 * @return string
	 */
	private function encode_key( #[\SensitiveParameter] string $raw_key ): string {
		// the pbkdf2 hash type is stored base64 encoded.
		if ( 'hash_pbkdf2' === $this->configuration['hash_type'] ) {
			return base64_encode( $raw_key );
		}

		// everything else is stored hex encoded.
		return bin2hex( $raw_key );
	}

	/**
	 * Report a failed decryption once, after every fallback has been tried.
	 *
	 * @param string $error_code The reason of the first attempt, may be empty.
	 * @param string $context The given context.
	 * @return void
	 */
	private function add_decrypt_error( string $error_code, string $context ): void {
		// fall back to the generic reason if no attempt reported one.
		if ( '' === $error_code ) {
			$error_code = 'openssl_decrypt_error';
		}

		// use the message matching the reported reason.
		$message = 'openssl_decrypt_hmac_error' === $error_code
			? 'Check for hmac failed.'
			: 'Given string could not be decrypted.';

		// log this error.
		$this->get_crypt_obj()->add_error( $error_code, $message, array( 'context' => $context ) );
	}
}
