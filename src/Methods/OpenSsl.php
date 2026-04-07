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

		// if no hash is set, create one.
		if ( empty( $this->get_hash() ) ) {
			// bail if the configured hash algorithm does not exist.
			if ( ! in_array( $this->configuration['hash_algorithm'], hash_algos(), true ) ) {
				throw new RuntimeException( 'Unknown hash algorithm: ' . wp_kses_post( $this->configuration['hash_algorithm'] ) );
			}

			// bail if the hash type is unknown.
			if ( ! in_array( $this->configuration['hash_type'], array( 'hash', 'hash_pbkdf2' ), true ) ) {
				throw new RuntimeException( 'Unknown hash type: ' . wp_kses_post( $this->configuration['hash_type'] ) );
			}

			// prepare the hash.
			$hash = '';

			// use hash() to generate the hash.
			if ( 'hash' === $this->configuration['hash_type'] ) {
				$hash = hash( $this->configuration['hash_algorithm'], (string) wp_rand() );
			}

			// use hash_pbkdf2() to generate the hash.
			if ( 'hash_pbkdf2' === $this->configuration['hash_type'] ) {
				$hash = base64_encode(
					hash_pbkdf2(
						$this->configuration['hash_algorithm'],
						(string) wp_rand(),
						wp_salt(),
						150000,
						32,
						true
					)
				);
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
	 * @access private
	 *
	 * @param string $plain_text Text to encrypt.
	 *
	 * @throws RuntimeException If an error occurred.
	 */
	public function encrypt( string $plain_text ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
			return '';
		}

		// bail if it is unusable.
		if ( ! $this->is_usable() ) {
			return '';
		}

		// bail if no text is given.
		if ( empty( $plain_text ) ) {
			return '';
		}

		// get the cipher algorithm.
		$cipher = $this->configuration['cipher_algorithm'];

		// bail if the configured cipher is not available.
		if ( ! in_array( strtolower( $cipher ), openssl_get_cipher_methods(), true ) ) {
			throw new RuntimeException( 'Unknown cipher algorithm: ' . wp_kses_post( $this->configuration['cipher_algorithm'] ) );
		}

		// gets the cipher iv length.
		$iv_length = openssl_cipher_iv_length( $cipher );

		// bail if iv length could not be loaded.
		if ( ! is_int( $iv_length ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return '';
		}

		// get the iv.
		$iv = openssl_random_pseudo_bytes( $iv_length );

		// bail if iv could not be created.
		if ( ! $iv ) {
			return '';
		}

		// handle GCM-based ciphers.
		if ( $this->should_use_aead_tag( $cipher ) ) {
			// set the tag.
			$tag = '';

			// encrypt the string.
			$ciphertext = openssl_encrypt(
				$plain_text,
				$cipher,
				$this->get_hash(),
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			// bail if text could not be encrypted.
			if ( ! is_string( $ciphertext ) ) {
				return '';
			}

			// return the resulting encrypted string.
			return base64_encode(
				base64_encode( $iv ) . ':' .
				base64_encode( $tag ) . ':' . // @phpstan-ignore argument.type
				base64_encode( $ciphertext )
			);
		} else {
			// for all others ciphers.

			// encrypt the string.
			$ciphertext_raw = openssl_encrypt(
				$plain_text,
				$cipher,
				$this->get_hash(),
				OPENSSL_RAW_DATA,
				$iv
			);

			// bail if anything failed.
			if ( ! $ciphertext_raw ) {
				return '';
			}

			// get the HMAC.
			$hmac = hash_hmac( $this->configuration['hash_algorithm'], $ciphertext_raw, $this->get_hash(), true );

			// return the resulting encrypted string.
			return base64_encode( base64_encode( $iv ) . ':' . base64_encode( $hmac . $ciphertext_raw ) );
		}
	}

	/**
	 * Decrypted a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 * @throws RuntimeException If cipher is unknown.
	 */
	public function decrypt( string $encrypted_text ): string {
		// bail if slug is not set.
		if ( empty( $this->get_crypt_obj()->get_slug() ) ) {
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
			throw new RuntimeException( 'Unknown cipher algorithm: ' . wp_kses_post( $this->configuration['cipher_algorithm'] ) );
		}

		// gets the cipher iv length.
		$iv_length = openssl_cipher_iv_length( $cipher );

		// bail if iv length could not be loaded.
		if ( ! $iv_length ) {
			return '';
		}

		// decode the encrypted text.
		$c = base64_decode( $encrypted_text );

		// handle GCM-based ciphers.
		if ( $this->should_use_aead_tag( $cipher ) ) {
			// get the parts.
			$c_exploded = explode( ':', $c );

			// get the part contents.
			$iv         = base64_decode( $c_exploded[0] );
			$tag        = base64_decode( $c_exploded[1] );
			$ciphertext = base64_decode( $c_exploded[2] );

			// decrypt the text.
			$original_plaintext = openssl_decrypt(
				$ciphertext,
				$cipher,
				$this->get_hash(),
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			// bail if decryption was not successful.
			if ( ! is_string( $original_plaintext ) ) {
				return '';
			}
		} else {
			// for all other ciphers.

			// backwards-compatibility for strings that does not contain ":".
			if ( str_contains( $c, ':' ) ) {
				// get the parts.
				$c_exploded = explode( ':', $c );

				// get IV.
				$iv = base64_decode( $c_exploded[0] );

				// bail if IV could not be loaded.
				if ( ! $iv ) {
					return '';
				}

				// get IV part.
				$iv = substr( $iv, 0, $iv_length );

				// get HMAC part.
				$c = base64_decode( $c_exploded[1] );

				// bail if HMAC part could not be loaded.
				if ( ! $c ) {
					return '';
				}

				// get HMAC.
				$hmac = substr( $c, 0, $sha2len = 32 );

				// get the raw cipher text.
				$ciphertext_raw = substr( $c, $sha2len, strlen( $c ) );
			} else {
				$iv             = substr( $c, 0, $iv_length );
				$hmac           = substr( $c, $iv_length, $sha2len = 32 );
				$ciphertext_raw = substr( $c, $iv_length + $sha2len );
			}

			// decrypt the string.
			$original_plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $this->get_hash(), OPENSSL_RAW_DATA, $iv );

			// bail if decryption was not successful.
			if ( ! is_string( $original_plaintext ) ) {
				return '';
			}

			// get the hashed HMAC.
			$calc_mac = hash_hmac( $this->configuration['hash_algorithm'], $ciphertext_raw, $this->get_hash(), true );

			// bail if HMAC is not set.
			if ( empty( $hmac ) ) {
				return '';
			}

			// bail if HMAC does not match.
			if ( ! hash_equals( $hmac, $calc_mac ) ) {
				return '';
			}
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
}
