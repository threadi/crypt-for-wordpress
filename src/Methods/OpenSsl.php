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
	 * @return void
	 * @throws RuntimeException If an error occurred.
	 */
	public function init(): void {
		$this->set_hash( $this->get_hash_value_from_constant() );

		// bail if hash is set.
		if ( ! empty( $this->get_hash() ) ) {
			return;
		}

		// get hash from the database.
		$this->set_hash( get_option( $this->get_crypt_obj()->get_slug() . '_hash', '' ) );

		// let the place derive the key, if it can.
		if ( empty( $this->get_hash() ) ) {
			$raw_key = $this->get_derived_key();

			if ( '' !== $raw_key ) {
				// encode it the way this method reads its key back.
				$this->set_hash( $this->encode_key( $raw_key ) );

				// a derived key is recreated on every request: it must not be
				// written into a place, and there is no old option to clean up.
				return;
			}
		}

		// if no hash is set, create one.
		if ( empty( $this->get_hash() ) ) {
			// bail if the configured hash algorithm does not exist.
			if ( ! in_array( $this->configuration['hash_algorithm'], hash_algos(), true ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_hash_algo_unknown',
					'Unknown hash algorithm.',
					array(
						'hash_algorithm' => $this->configuration['hash_algorithm'],
					)
				);

				// do nothing more.
				return;
			}

			// bail if the hash type is unknown.
			if ( ! in_array( $this->configuration['hash_type'], array( 'hash', 'hash_pbkdf2' ), true ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_hash_algo_unknown',
					'Unknown hash type.',
					array(
						'hash_type' => $this->configuration['hash_type'],
					)
				);

				// do nothing more.
				return;
			}

			// prepare the hash.
			$hash = '';

			// use hash() to generate the hash.
			if ( 'hash' === $this->configuration['hash_type'] ) {
				try {
					$hash = hash( $this->configuration['hash_algorithm'], random_bytes( 32 ) );
				} catch ( Exception $e ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'openssl_hash_algo_error',
						'Error during generating the hash algorithm:' . wp_kses_post( $e->getMessage() ),
						array(
							'hash_algorithm' => $this->configuration['hash_algorithm'],
						)
					);

					// do nothing more.
					return;
				}
			}

			// use hash_pbkdf2() to generate the hash.
			if ( 'hash_pbkdf2' === $this->configuration['hash_type'] ) {
				try {
					$hash = base64_encode(
						hash_pbkdf2(
							$this->configuration['hash_algorithm'],
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
							'hash_type' => $this->configuration['hash_type'],
						)
					);

					// do nothing more.
					return;
				}
			}

			// set the resulting hash.
			$this->set_hash( $hash );
		}

		// save the hash in its place.
		$this->get_crypt_obj()->save_in_place( $this->get_constant(), $this->get_hash() );

		// delete the old option field.
		delete_option( $this->get_crypt_obj()->get_slug() . '_hash' );

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
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text Text to encrypt.
	 *
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt( string $plain_text ): string {
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
				'IV length could not be generated.'
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
				'IV could not be generated.'
			);

			// do nothing more.
			return '';
		}

		// get the hash depending on the used hash type.
		$hash = $this->get_decoded_master_key();

		// handle GCM-based ciphers.
		if ( $this->should_use_aead_tag( $cipher ) ) {
			// set the tag.
			$tag = '';

			// encrypt the string.
			$ciphertext = openssl_encrypt(
				$plain_text,
				$cipher,
				$hash,
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			// bail if text could not be encrypted.
			if ( ! is_string( $ciphertext ) ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_encrypt_error',
					'Given string could not be encrypted.'
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

		// get two keys from the main key.
		$enc_key  = $this->derive_key( 'encryption', 32, $hash );
		$hmac_key = $this->derive_key( 'authentication', 32, $hash );

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
				'Given string could not be encrypted.'
			);

			// do nothing more.
			return '';
		}

		// get the HMAC.
		$hmac = hash_hmac( $this->configuration['hash_algorithm'], $ciphertext_raw, $hmac_key, true );

		// return the resulting encrypted string.
		return base64_encode( base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );
	}

	/**
	 * Decrypted a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 * @throws RuntimeException If the cipher is unknown, or the stored key is invalid.
	 */
	public function decrypt( string $encrypted_text ): string {
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
				'IV length could not be generated.'
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
				'Encrypted string is not valid base64.'
			);
			// do nothing more.
			return '';
		}

		// get the hash depending on the used hash type.
		$hash = $this->get_decoded_master_key();

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
						'parts' => count( $c_exploded ),
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
					'Parts of the encrypted string could not be decoded.'
				);

				// do nothing more.
				return '';
			}

			// bail if IV or tag do not have a length this cipher could ever
			// have produced - openssl_decrypt() would raise a PHP warning.
			if ( strlen( $iv ) !== $iv_length || strlen( $tag ) < 4 || strlen( $tag ) > 16 ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'openssl_decrypt_payload_invalid',
					'IV or AEAD tag of the encrypted string have an unexpected length.',
					array(
						'iv_length'  => strlen( $iv ),
						'tag_length' => strlen( $tag ),
					)
				);

				// do nothing more.
				return '';
			}

			// the current (raw-byte) key first, then the original legacy key (raw, undecoded hash string).
			foreach ( array( $hash, $this->get_hash() ) as $key ) {
				$attempt_error      = '';
				$original_plaintext = $this->try_decrypt_aead( $cipher, $ciphertext, $iv, $tag, $key, $attempt_error );

				// stop at the first attempt that worked.
				if ( '' !== $original_plaintext ) {
					break;
				}

				// remember why the first, non-legacy attempt failed.
				if ( '' === $first_error ) {
					$first_error = $attempt_error;
				}
			}
		} else {
			// for all other ciphers.

			// get two keys from the main key.
			$enc_key  = $this->derive_key( 'encryption', 32, $hash );
			$hmac_key = $this->derive_key( 'authentication', 32, $hash );

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
						'IV could not be read.'
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
						'HMAC could not be read.'
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

			foreach ( $key_pairs as $key_pair ) {
				$attempt_error      = '';
				$original_plaintext = $this->try_decrypt_non_aead( $cipher, $ciphertext_raw, $iv, $hmac, $key_pair[0], $key_pair[1], $attempt_error );

				// stop at the first attempt that worked.
				if ( '' !== $original_plaintext ) {
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
			$this->add_decrypt_error( $first_error );

			// do nothing more.
			return '';
		}

		// return the resulting decrypted string.
		return $original_plaintext;
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// bail if hash is not saved.
		if ( ! $this->is_hash_saved() ) {
			// run parent uninstalling tasks.
			parent::uninstall();

			// do nothing more.
			return;
		}

		// initiate the method to get the actual hash.
		$this->init();

		// save the hash in the database.
		update_option( $this->get_crypt_obj()->get_slug() . '_hash', $this->get_hash() );

		// run parent uninstalling tasks.
		parent::uninstall();
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
	private function derive_key( string $purpose, int $length, string $hash ): string {
		// bail if hash is empty.
		if ( empty( $hash ) ) {
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
	 * Return the decoded master key as raw bytes, depending on the configured hash type.
	 *
	 * @return string
	 *
	 * @throws RuntimeException If the stored key cannot be decoded.
	 */
	private function get_decoded_master_key(): string {
		// get hash depending on the used type.
		if ( 'hash_pbkdf2' === $this->configuration['hash_type'] ) {
			$decoded = base64_decode( $this->get_hash(), true );
		} else {
			$decoded = hex2bin( $this->get_hash() );
		}

		// bail on any error.
		if ( ! is_string( $decoded ) || '' === $decoded ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'openssl_decode_master_key_error',
				'Stored encryption key is invalid or corrupted.'
			);

			// do nothing more.
			return '';
		}

		// return the decoded hash.
		return $decoded;
	}

	/**
	 * Try to decrypt an AEAD ciphertext with a given key.
	 *
	 * @param string $cipher     The cipher algorithm.
	 * @param string $ciphertext The raw ciphertext.
	 * @param string $iv         The IV.
	 * @param string $tag        The AEAD tag.
	 * @param string $key        Key to try.
	 * @param string $error_code Set to the reason this attempt failed, empty on success.
	 *
	 * @return string The decrypted plaintext, or '' on failure.
	 */
	private function try_decrypt_aead( string $cipher, string $ciphertext, string $iv, string $tag, string $key, string &$error_code ): string {
		// reset the reason of this attempt.
		$error_code = '';

		// decrypt the string.
		$plaintext = openssl_decrypt( $ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag );

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
	private function try_decrypt_non_aead( string $cipher, string $ciphertext_raw, string $iv, string $hmac, string $enc_key, string $hmac_key, string &$error_code ): string {
		// reset the reason of this attempt.
		$error_code = '';

		// get the plain text.
		$plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $enc_key, OPENSSL_RAW_DATA, $iv );

		// bail if no text could be read.
		if ( ! is_string( $plaintext ) ) {
			// mark as error.
			$error_code = 'openssl_decrypt_error';

			// do nothing more.
			return '';
		}

		// bail if hmac is empty.
		if ( empty( $hmac ) ) {
			return '';
		}

		// get the calculated HMAC.
		$calc_mac = hash_hmac( $this->configuration['hash_algorithm'], $ciphertext_raw, $hmac_key, true );

		// bail if hmac und calculated mac does not match.
		if ( ! hash_equals( $hmac, $calc_mac ) ) {
			// log this error.
			$error_code = 'openssl_decrypt_hmac_error';

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
	private function encode_key( string $raw_key ): string {
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
	 *
	 * @return void
	 */
	private function add_decrypt_error( string $error_code ): void {
		// fall back to the generic reason if no attempt reported one.
		if ( '' === $error_code ) {
			$error_code = 'openssl_decrypt_error';
		}

		// use the message matching the reported reason.
		$message = 'openssl_decrypt_hmac_error' === $error_code
			? 'Check for hmac failed.'
			: 'Given string could not be decrypted.';

		// log this error.
		$this->get_crypt_obj()->add_error( $error_code, $message );
	}
}
