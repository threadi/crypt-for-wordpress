<?php
/**
 * File to handle crypt methods as base-object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Object to handle crypt methods as base-object.
 */
class Method_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The first bytes of every value in the versioned payload format.
	 *
	 * None of the formats of earlier versions can begin with them: the
	 * values of Sodium begin with the number of their algorithm (1 to 4),
	 * the ones of OpenSSL with a base64 character. Only the very first
	 * format of OpenSSL for ciphers without AEAD begins with random bytes,
	 * see OpenSsl::decrypt_with_context().
	 */
	private const PAYLOAD_MAGIC = "\xF3CW";

	/**
	 * The version of the payload format this package writes.
	 */
	private const PAYLOAD_VERSION = 1;

	/**
	 * Flag in the payload header: the value is bound to the site it has
	 * been encrypted on.
	 */
	private const PAYLOAD_FLAG_SITE = 1;

	/**
	 * Every flag this version of the payload format knows.
	 */
	private const PAYLOAD_FLAGS_KNOWN = self::PAYLOAD_FLAG_SITE;

	/**
	 * Length of the key ID in the payload header, in bytes.
	 */
	private const PAYLOAD_KEY_ID_LENGTH = 8;

	/**
	 * Length of the payload header, in bytes: magic, version, flags, key ID.
	 */
	private const PAYLOAD_HEADER_LENGTH = 13;

	/**
	 * The context the previous key is encrypted with, see
	 * get_previous_key_option_name().
	 */
	private const PREVIOUS_KEY_CONTEXT = 'crypt-for-wordpress-previous-key';

	/**
	 * The keys, which have been changed with rotate_key() or
	 * rollback_key_rotation() during this request - as they are stored, by
	 * the name of their constant.
	 *
	 * A constant cannot be defined anew: it keeps the former key until the
	 * end of the request. Every object of this package reads the new key
	 * from here instead, see get_hash_value_from_constant().
	 *
	 * @var array<string,string>
	 */
	private static array $changed_keys = array();

	/**
	 * The previous key, as it is stored - an empty string if there is none,
	 * or null if it has not been looked up yet.
	 *
	 * @var string|null
	 */
	private ?string $previous_key = null;

	/**
	 * Whether this object works with another key than its own right now,
	 * see run_with_key().
	 *
	 * @var bool
	 */
	private bool $is_using_other_key = false;

	/**
	 * Whether the last value decrypted with decrypt_with_any_key() has been
	 * decrypted with the previous key.
	 *
	 * @var bool
	 */
	private bool $used_previous_key = false;

	/**
	 * The hash for encryption.
	 *
	 * @var string
	 */
	protected string $hash = '';

	/**
	 * The method configurations.
	 *
	 * @var array<string,mixed>
	 */
	protected array $configuration = array();

	/**
	 * The crypt object.
	 *
	 * @var Crypt
	 */
	protected Crypt $crypt_obj;

	/**
	 * The place this method gets its key from.
	 *
	 * @var Place_Base|null
	 */
	private ?Place_Base $place_obj = null;

	/**
	 * Whether the key in use is the same for every site of a multisite
	 * network. Depends on the place the key lives in.
	 *
	 * @var bool
	 */
	private bool $key_is_network_wide = true;

	/**
	 * The constants, which have been defined during this request with a key
	 * that belongs to one site of a multisite network only - with the ID of
	 * that site.
	 *
	 * A constant does not tell where its value came from, and it stays
	 * defined while other sites are switched to. Another object of the same
	 * plugin, which finds it defined, needs to know both.
	 *
	 * @var array<string,int>
	 */
	private static array $per_site_constants = array();

	/**
	 * Whether the key has been put aside for an uninstallation already.
	 *
	 * @var bool
	 */
	private bool $key_kept_for_uninstall = false;

	/**
	 * The key material a place derived itself, if the key in use is one.
	 *
	 * @var string
	 */
	private string $derived_key = '';

	/**
	 * Where get_key_kept_for_uninstall() has found the key: "secret",
	 * "option" or an empty string.
	 *
	 * @var string
	 */
	private string $kept_key_source = '';

	/**
	 * Initialize this crypt method.
	 *
	 * @return void
	 */
	public function init(): void {}

	/**
	 * Return whether this method is usable in this hosting.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return false;
	}

	/**
	 * Return whether this method can do anything if no place is usable, so
	 * no key could be saved. Called after init().
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return bool
	 */
	public function is_usable_without_place(): bool {
		return true;
	}

	/**
	 * Return the name of the method.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Encrypt a given string.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text The plain string.
	 *
	 * @return string
	 */
	public function encrypt( #[\SensitiveParameter] string $plain_text ): string {
		if ( empty( $plain_text ) ) {
			return $plain_text;
		}
		return '';
	}

	/**
	 * Decrypt a given string.
	 *
	 * @param string $encrypted_text The encrypted string.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text ): string {
		if ( empty( $encrypted_text ) ) {
			return $encrypted_text;
		}
		return '';
	}

	/**
	 * Encrypt a given string, bound to a context.
	 *
	 * The signatures of encrypt() and decrypt() are not changed for this, so
	 * methods written before the context existed keep working. A method that
	 * supports a context overrides these two.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $plain_text The plain string.
	 * @param string $context    What the value belongs to. The value can only be decrypted with the same context.
	 *
	 * @return string
	 */
	public function encrypt_with_context( #[\SensitiveParameter] string $plain_text, string $context ): string {
		// without a context this is what it has always been.
		if ( '' === $context ) {
			return $this->encrypt( $plain_text );
		}

		// never ignore a context: the value would not be bound to it.
		$this->report_unsupported_context( $context );

		return '';
	}

	/**
	 * Decrypt a given string, which has been bound to a context.
	 *
	 * @param string $encrypted_text The encrypted string.
	 * @param string $context        The context the value has been encrypted with.
	 *
	 * @return string
	 */
	public function decrypt_with_context( string $encrypted_text, string $context ): string {
		// without a context this is what it has always been.
		if ( '' === $context ) {
			return $this->decrypt( $encrypted_text );
		}

		// never ignore a context: the value cannot have been bound to it.
		$this->report_unsupported_context( $context );

		return '';
	}

	/**
	 * Report that this method cannot bind a value to a context.
	 *
	 * @param string $context The context which has been given.
	 *
	 * @return void
	 */
	private function report_unsupported_context( string $context ): void {
		$this->get_crypt_obj()->add_error(
			'context_not_supported',
			'The method used for encryption does not support binding a value to a context.',
			array(
				'method'  => $this->get_name(),
				'context' => $context,
			)
		);
	}

	/**
	 * Return hash for encryption.
	 *
	 * @return string
	 */
	public function get_hash(): string {
		return $this->hash;
	}

	/**
	 * Return the secured hash value.
	 *
	 * @return string
	 */
	protected function get_hash_value(): string {
		return $this->hash;
	}

	/**
	 * Return the hash value from our constant.
	 *
	 * @return string
	 */
	protected function get_hash_value_from_constant(): string {
		// use the key, which has replaced the one of the constant during this request.
		if ( isset( self::$changed_keys[ $this->get_constant() ] ) ) {
			return self::$changed_keys[ $this->get_constant() ];
		}

		$constants = get_defined_constants();
		// bail if our constant is not set.
		if ( ! isset( $constants[ $this->get_constant() ] ) ) {
			return '';
		}

		// return the value of our constant.
		return $constants[ $this->get_constant() ];
	}

	/**
	 * Set hash for encryption.
	 *
	 * @param string $hash The hash.
	 *
	 * @return void
	 */
	protected function set_hash( #[\SensitiveParameter] string $hash ): void {
		$this->hash = $hash;
	}

	/**
	 * Return whether the hash is saved in wp-config.php.
	 *
	 * @return bool
	 */
	public function is_hash_saved(): bool {
		return defined( $this->get_constant() );
	}

	/**
	 * Run the constant.
	 *
	 * @return void
	 */
	protected function run_constant(): void {
		if ( $this->is_hash_saved() ) {
			return;
		}
		// note which site this key belongs to, if it is not the one of the network.
		if ( ! $this->key_is_network_wide ) {
			self::register_per_site_constant( $this->get_constant() );
		}

		// define it the way it is saved in a place, as this is what init() reads back.
		define( $this->get_constant(), $this->get_hash_value() );
	}

	/**
	 * Return the name of used constant for our hash.
	 *
	 * @return string
	 */
	public function get_constant(): string {
		$slug     = $this->get_crypt_obj()->get_slug();
		$constant = strtoupper( $slug ) . '-HASH';

		/**
		 * Filter the name of the constant.
		 *
		 * @since 1.1.2 Available since 1.1.2.
		 * @param string $constant The name of the constant.
		 */
		return apply_filters( $slug . '_crypt_constant', $constant );
	}

	/**
	 * Return the name of the option holding the fingerprint of the key this
	 * installation is known to use.
	 *
	 * The fingerprint is not a secret: it cannot be used to decrypt anything.
	 * It only allows telling "there never was a key" apart from "the key has
	 * gone missing" - the second case must never end in a new key, as that
	 * would make every value encrypted so far unreadable.
	 *
	 * @return string
	 */
	public function get_key_id_option_name(): string {
		return $this->get_crypt_obj()->get_slug() . '_crypt_key_id_' . $this->get_name();
	}

	/**
	 * Return the key material the fingerprint is calculated from.
	 *
	 * For a key saved in a place this is the key exactly as it is saved. For
	 * a key the place derives itself it is the derived key: nothing is saved
	 * then, and how a method encodes it for its own use is not part of it.
	 *
	 * @return string
	 */
	private function get_key_material(): string {
		return '' !== $this->derived_key ? $this->derived_key : $this->get_hash_value();
	}

	/**
	 * Return the fingerprint of the given key material.
	 *
	 * @param string $key_material The key material.
	 *
	 * @return string
	 */
	private function get_key_id( #[\SensitiveParameter] string $key_material ): string {
		return substr( hash_hmac( 'sha256', 'crypt-for-wordpress-key-id', $key_material ), 0, 16 );
	}

	/**
	 * Return whether the fingerprint belongs to the whole multisite network.
	 *
	 * It has to live where the key lives: a key in the wp-config.php is the
	 * key of every site, a key in the database belongs to one site only.
	 *
	 * @return bool
	 */
	private function is_key_id_network_wide(): bool {
		return is_multisite() && $this->key_is_network_wide && ! isset( self::$per_site_constants[ $this->get_constant() ] );
	}

	/**
	 * Note that the given constant has been defined with a key, which
	 * belongs to the current site only.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $constant The name of the constant.
	 *
	 * @return void
	 */
	public static function register_per_site_constant( string $constant ): void {
		// only the first one counts: a constant cannot be defined twice.
		if ( ! isset( self::$per_site_constants[ $constant ] ) ) {
			self::$per_site_constants[ $constant ] = get_current_blog_id();
		}
	}

	/**
	 * Return whether the constant holds the key of another site than the
	 * current one.
	 *
	 * This happens on a multisite network with keys per site, if sites are
	 * switched during a request: the constant keeps the key of the site it
	 * has been defined on.
	 *
	 * @return bool
	 */
	private function is_key_of_another_site(): bool {
		$constant = $this->get_constant();

		return is_multisite() && isset( self::$per_site_constants[ $constant ] ) && get_current_blog_id() !== self::$per_site_constants[ $constant ];
	}

	/**
	 * Return the fingerprint remembered for the current site, or an empty string.
	 *
	 * @return string
	 */
	private function get_site_key_id(): string {
		$key_id = get_option( $this->get_key_id_option_name(), '' );

		return is_string( $key_id ) ? $key_id : '';
	}

	/**
	 * Return the fingerprint remembered for the multisite network, or an
	 * empty string.
	 *
	 * @return string
	 */
	private function get_network_key_id(): string {
		// bail if this is not a multisite network.
		if ( ! is_multisite() ) {
			return '';
		}

		$key_id = get_site_option( $this->get_key_id_option_name(), '' );

		return is_string( $key_id ) ? $key_id : '';
	}

	/**
	 * Return whether this installation has used a key before - wherever its
	 * fingerprint has been remembered.
	 *
	 * Which place is the active one can change, and with it where a
	 * fingerprint would be remembered today. A key that has been used
	 * before must be missed all the same.
	 *
	 * @return bool
	 */
	private function is_any_key_known(): bool {
		return '' !== $this->get_site_key_id() || '' !== $this->get_network_key_id();
	}

	/**
	 * Return the fingerprint the key in use is expected to have, or an empty
	 * string if none has been remembered for it.
	 *
	 * A key that belongs to one site is compared with the fingerprint of
	 * this site. A key of the network is compared with the one of the
	 * network - unless this site has a fingerprint of its own: then it has
	 * values encrypted with that key, and a key of the network must not
	 * take its place unnoticed.
	 *
	 * @return string
	 */
	private function get_expected_key_id(): string {
		$site_key_id = $this->get_site_key_id();

		// bail if the key belongs to this site only, or this site has a fingerprint of its own.
		if ( ! $this->is_key_id_network_wide() || '' !== $site_key_id ) {
			return $site_key_id;
		}

		return $this->get_network_key_id();
	}

	/**
	 * Return whether the given key material belongs to a key this
	 * installation has used before, wherever it has been remembered.
	 *
	 * @param string $key_material The key material.
	 *
	 * @return bool
	 */
	private function is_known_key( #[\SensitiveParameter] string $key_material ): bool {
		$key_id = $this->get_key_id( $key_material );

		foreach ( array( $this->get_site_key_id(), $this->get_network_key_id() ) as $known_key_id ) {
			if ( '' !== $known_key_id && hash_equals( $known_key_id, $key_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return whether the key in use is the one expected.
	 *
	 * @return bool
	 */
	private function is_expected_key(): bool {
		$expected_key_id = $this->get_expected_key_id();

		return '' !== $expected_key_id && hash_equals( $expected_key_id, $this->get_key_id( $this->get_key_material() ) );
	}

	/**
	 * Remember the fingerprint of the current key, where the key lives.
	 *
	 * @param bool $replace True to replace a fingerprint that is already remembered there.
	 *
	 * @return void
	 */
	private function remember_key_id( bool $replace = false ): void {
		$option_name = $this->get_key_id_option_name();
		$key_id      = $this->get_key_id( $this->get_key_material() );

		// bail if the constant holds the key of another site: it must never
		// be remembered as the key of this one.
		if ( $this->is_key_of_another_site() ) {
			return;
		}

		// a key of the network is remembered for the network.
		if ( $this->is_key_id_network_wide() ) {
			// add it. This does nothing if another process has been faster.
			add_site_option( $option_name, $key_id );

			// replace what is there, if it is requested or not usable.
			if ( $replace || '' === $this->get_network_key_id() ) {
				update_site_option( $option_name, $key_id );
			}

			// do nothing more.
			return;
		}

		// add it. This does nothing if another process has been faster.
		add_option( $option_name, $key_id );

		// replace what is there, if it is requested or not usable.
		if ( $replace || '' === $this->get_site_key_id() ) {
			update_option( $option_name, $key_id );
		}
	}

	/**
	 * Forget the fingerprint of the key this installation is known to use.
	 *
	 * @return void
	 */
	private function forget_key_id(): void {
		delete_option( $this->get_key_id_option_name() );

		if ( is_multisite() ) {
			delete_site_option( $this->get_key_id_option_name() );
		}

		// the previous key is encrypted with the key, which is forgotten:
		// nothing can read it anymore.
		$this->discard_previous_key();
	}

	/**
	 * Check a key, which has been loaded from somewhere, against the key this
	 * installation is known to use.
	 *
	 * The first key ever seen is remembered. If another key shows up later,
	 * it is used - nobody is asked, the plugin keeps working. But it is
	 * reported once as "key_changed": the values encrypted with the former
	 * key cannot be decrypted anymore, and the plugin should be able to tell
	 * its user why.
	 *
	 * @return void
	 */
	protected function confirm_key(): void {
		// bail if the constant holds the key of another site: it is used as it
		// is, but it says nothing about the key of this site.
		if ( $this->is_key_of_another_site() ) {
			return;
		}

		// remember the first key ever seen.
		if ( '' === $this->get_expected_key_id() ) {
			$this->remember_key_id();
		}

		// bail if it is the expected key (read back: another process may have
		// been faster) - and as well if nothing could be remembered at all
		// (e.g., the database is not writable): then there is nothing this
		// key could contradict.
		if ( '' === $this->get_expected_key_id() || $this->is_expected_key() ) {
			return;
		}

		// this is the key of a rotation, which has ended before its
		// fingerprint could be remembered: the expected key is its previous
		// key. Nothing has been lost, so nothing is reported.
		if ( $this->is_key_of_unfinished_rotation() ) {
			$this->remember_changed_key_id();
			return;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_changed',
			'The key found for encryption is not the key this installation has used so far. It is used from now on. Values encrypted with the former key cannot be decrypted anymore, unless that key is restored.',
			array(
				'method' => $this->get_name(),
			)
		);

		// this is the key from now on.
		$this->remember_changed_key_id();
	}

	/**
	 * Remember the fingerprint of the current key as the one of this
	 * installation, replacing the one of the former key.
	 *
	 * @return void
	 */
	private function remember_changed_key_id(): void {
		// if it is a key of the network, a fingerprint of this site no longer stands against it.
		if ( $this->is_key_id_network_wide() ) {
			delete_option( $this->get_key_id_option_name() );
		}
		$this->remember_key_id( true );
	}

	/**
	 * Report that the key of this installation is gone, before a new one is
	 * generated.
	 *
	 * A new key is generated in any case - nobody is asked, the plugin keeps
	 * working. But if this installation had a key before, every value
	 * encrypted with it becomes unreadable. This must not happen silently:
	 * it is reported once as "key_missing", so the plugin can tell its user
	 * why the saved values have to be entered again.
	 *
	 * @return void
	 */
	protected function report_missing_key(): void {
		// bail if a place holds the key, but cannot hand it over right now:
		// it is not missing. No new key is saved then, see store_new_key().
		if ( $this->is_stored_key_unavailable() ) {
			return;
		}

		// bail if no key has ever been used: this is the first usage.
		if ( ! $this->is_any_key_known() ) {
			return;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_missing',
			'This installation has used a key for encryption before, but it could not be found. A new key is generated. Values encrypted with the former key cannot be decrypted anymore, unless that key is restored.',
			array(
				'method'   => $this->get_name(),
				'constant' => $this->get_constant(),
			)
		);

		// the new key takes the place of the former one.
		$this->forget_key_id();
	}

	/**
	 * Return whether the given value is a key this method could have stored.
	 *
	 * Used for keys found in a place, which is not the active one: such a
	 * place may hold the key of another method.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return bool
	 */
	protected function is_valid_stored_key( #[\SensitiveParameter] string $stored_key ): bool {
		return '' !== $stored_key;
	}

	/**
	 * Return a key of this method, which is still stored in a place that is
	 * not the active one anymore - or an empty string.
	 *
	 * @return string The key as it is stored.
	 */
	protected function get_stored_key_from_inactive_places(): string {
		$found = $this->find_stored_key( false, false );

		// bail if no place holds a key of this method.
		if ( null === $found ) {
			return '';
		}

		// the fingerprint has to live where this key lives - also for every
		// other object, which finds the constant defined later in this request.
		$this->key_is_network_wide = $found['place']->is_network_wide();
		if ( ! $this->key_is_network_wide ) {
			self::register_per_site_constant( $this->get_constant() );
		}

		// log this as warning.
		$this->get_crypt_obj()->add_error(
			'key_in_inactive_place',
			'The key for encryption has not been found in the active place, but in another one. It is used from there.',
			array(
				'method'   => $this->get_name(),
				'found_in' => $found['place']->get_name(),
			)
		);

		return $found['key'];
	}

	/**
	 * Return the key of this method to keep during an uninstallation, as it
	 * is stored - or an empty string.
	 *
	 * This is the key from the constant, if it is defined. Otherwise the
	 * places are asked: the uninstallation may run in a request that has
	 * not used the key, and the key may live in a place, which is not the
	 * active one. Without it the places would delete the only copy.
	 *
	 * A key found in a place is only kept if it is the known key, or none
	 * is known. Any other one is a leftover - e.g. next to a key the place
	 * derives itself. Kept, it would be read back first by a later
	 * installation and then be refused there.
	 *
	 * @return string
	 */
	private function get_stored_key_for_uninstall(): string {
		// use the key from the constant, if it is one this method can work with.
		$stored_key = $this->get_hash_value_from_constant();
		if ( ! empty( $stored_key ) && ( $this->is_usable_stored_key( $stored_key ) || $this->is_known_key( $stored_key ) ) ) {
			return $stored_key;
		}

		// bail if the place derives the key itself: this is the key in use,
		// and it needs no keeping. Whatever else the places hold is a leftover.
		if ( '' !== $this->get_derived_key() ) {
			return '';
		}

		// ask every place for the key of this method.
		$found = $this->find_stored_key( true, true );

		return null === $found ? '' : $found['key'];
	}

	/**
	 * Return whether this method can work with the given key from a constant.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return bool
	 */
	protected function is_usable_stored_key( #[\SensitiveParameter] string $stored_key ): bool {
		return '' !== $stored_key;
	}

	/**
	 * Return the key of this method a place holds.
	 *
	 * If several places hold one, the known key wins.
	 *
	 * @param bool $include_active_place False to skip the active place.
	 * @param bool $only_known_key       True to ignore every key that is not the known one, if one is known.
	 *
	 * @return array{place:Place_Base,key:string}|null
	 */
	private function find_stored_key( bool $include_active_place, bool $only_known_key ): ?array {
		$is_key_known = $this->is_any_key_known();
		$first_found  = null;

		foreach ( $this->get_stored_keys_of_places( $include_active_place ) as $found ) {
			// use it if it is the known key - whatever it looks like: the
			// configuration may have changed since it has been saved. A stored
			// key is its own key material, see get_key_material().
			if ( $is_key_known && $this->is_known_key( $found['key'] ) ) {
				return $found;
			}

			// bail if it is not a key of this method.
			if ( ! $this->is_valid_stored_key( $found['key'] ) ) {
				continue;
			}

			// use it if no key is known.
			if ( ! $is_key_known ) {
				return $found;
			}

			// remember the first key of this method.
			if ( null === $first_found ) {
				$first_found = $found;
			}
		}

		// no place holds the known key.
		return $only_known_key ? null : $first_found;
	}

	/**
	 * Return the keys the places hold, without loading them.
	 *
	 * @param bool $include_active_place False to skip the active place.
	 *
	 * @return array<int,array{place:Place_Base,key:string}>
	 */
	private function get_stored_keys_of_places( bool $include_active_place ): array {
		// ask the Crypt object, if it is able to.
		if ( ! $this->is_crypt_class_of_older_version() ) {
			return $this->get_crypt_obj()->get_stored_keys( $this->get_constant(), $include_active_place );
		}

		// the Crypt class of version 3.1.0 cannot look into the places, but
		// it hands them out - the ones a configured "force_place" does not hide.
		$active_place_obj = $this->get_crypt_obj()->get_place();
		$active_place     = $active_place_obj instanceof Place_Base ? $active_place_obj->get_name() : '';

		$keys = array();
		foreach ( $this->get_crypt_obj()->get_places_as_objects() as $place_obj ) {
			// bail if this is the active place, and it should be skipped.
			if ( ! $include_active_place && $place_obj->get_name() === $active_place ) {
				continue;
			}

			// ask the place for the key it holds.
			$place_obj->set_constant( $this->get_constant() );
			$stored_key = $place_obj->get_stored_key();

			if ( '' !== $stored_key ) {
				$keys[] = array(
					'place' => $place_obj,
					'key'   => $stored_key,
				);
			}
		}

		return $keys;
	}

	/**
	 * Return whether the configured place holds the current key.
	 *
	 * @return bool
	 */
	private function is_key_saved_in_place(): bool {
		// ask the Crypt object, if it is able to.
		if ( ! $this->is_crypt_class_of_older_version() ) {
			return $this->get_crypt_obj()->is_saved_in_place( $this->get_constant(), $this->get_hash_value() );
		}

		// the Crypt class of version 3.1.0 cannot check this, but it hands out the place.
		$place_obj = $this->get_crypt_obj()->get_place();

		// bail if there is no place the key could have been saved in.
		if ( ! $place_obj instanceof Place_Base ) {
			return false;
		}

		// ask the place.
		$place_obj->set_constant( $this->get_constant() );

		return $place_obj->is_saved( $this->get_hash_value() );
	}

	/**
	 * Return whether a place holds a key for the constant of this method,
	 * which it cannot hand over right now.
	 *
	 * @return bool
	 */
	private function is_stored_key_unavailable(): bool {
		// the key an uninstallation has kept as secret cannot be read: it is
		// the key all values have been encrypted with.
		if ( $this->is_kept_key_unavailable() ) {
			return true;
		}

		// ask the Crypt object, if it is able to.
		if ( method_exists( $this->get_crypt_obj(), 'is_stored_key_unavailable' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return $this->get_crypt_obj()->is_stored_key_unavailable( $this->get_constant() );
		}

		// an older Crypt class hands out the places - the ones a configured
		// "force_place" does not hide.
		foreach ( $this->get_crypt_obj()->get_places_as_objects() as $place_obj ) {
			$place_obj->set_constant( $this->get_constant() );

			// (a place of an older copy of this package cannot tell.)
			if ( method_exists( $place_obj, 'is_key_unavailable' ) && $place_obj->is_key_unavailable() ) { // @phpstan-ignore function.alreadyNarrowedType
				return true;
			}
		}

		return false;
	}

	/**
	 * Return whether the Secrets API holds a key of this method, which an
	 * uninstallation has kept, but cannot hand it over right now.
	 *
	 * @return bool
	 */
	private function is_kept_key_unavailable(): bool {
		// bail if this method keeps no key during an uninstallation.
		if ( '' === $this->get_uninstall_option_name() ) {
			return false;
		}

		// get the place of the Secrets API.
		$place_obj = $this->get_secrets_api_place();

		return $place_obj instanceof Places\SecretsApi && $place_obj->get_kept_key( $this->get_name() ) instanceof WP_Error;
	}

	/**
	 * Return whether the Crypt class in use is the one of version 3.1.0 of
	 * this package.
	 *
	 * Several plugins of a website may ship this package, in different
	 * versions. PHP loads each class once, from whichever copy its
	 * autoloader finds first - so the Crypt class of version 3.1.0, shipped
	 * by a plugin which has not been updated yet, may end up working with
	 * this class. The two things that Crypt class cannot do are done here
	 * then, with what it offers. Without this the plugin which ships the
	 * older version would stop with a fatal error.
	 *
	 * @return bool
	 */
	private function is_crypt_class_of_older_version(): bool {
		// (for PHPStan there is only one Crypt class, which has both.)
		if ( ! method_exists( $this->get_crypt_obj(), 'get_stored_keys' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return true;
		}

		return ! method_exists( $this->get_crypt_obj(), 'is_saved_in_place' ); // @phpstan-ignore function.alreadyNarrowedType
	}

	/**
	 * Save the current key in the configured place and check that it really
	 * arrived there.
	 *
	 * @return bool True if the place holds the key.
	 */
	protected function persist_key(): bool {
		// save the key.
		$this->get_crypt_obj()->save_in_place( $this->get_constant(), $this->get_hash_value() );

		// do not trust the write: check that the place really holds the key.
		if ( $this->is_key_saved_in_place() ) {
			// the key may have been saved in another place than the active one.
			$this->follow_saved_place();

			return true;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_not_saved',
			'The key for encryption could not be saved in its place.',
			array(
				'method'   => $this->get_name(),
				'constant' => $this->get_constant(),
			)
		);

		return false;
	}

	/**
	 * Move the fingerprint of the current key to where the key has been
	 * saved, if that is not where the active place keeps it.
	 *
	 * If saving in the active place fails, the key is saved in the next
	 * usable place. On a multisite network that place may keep its key for
	 * another scope - e.g. the database for one site only, while the
	 * "must-use"-plugin keeps it for the whole network. The fingerprint has
	 * already been remembered for the scope of the active place. Left there,
	 * every other site would find a fingerprint of the network, but no key -
	 * and report it as missing.
	 *
	 * @return void
	 */
	private function follow_saved_place(): void {
		// bail if the Crypt class cannot tell (an older copy of this package).
		if ( ! method_exists( $this->get_crypt_obj(), 'get_saved_place' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return;
		}

		// get the place the key has been saved in.
		$place_obj = $this->get_crypt_obj()->get_saved_place( $this->get_constant() );

		// bail if it is unknown, or it keeps its key for the same scope as the active place.
		if ( ! $place_obj instanceof Place_Base || $place_obj->is_network_wide() === $this->key_is_network_wide ) {
			return;
		}

		// bail on a single site: there is only one scope.
		if ( ! is_multisite() ) {
			$this->key_is_network_wide = $place_obj->is_network_wide();
			return;
		}

		// forget the fingerprint where it has been remembered - only if it is
		// the one of this key: anything else is not ours to remove.
		$key_id = $this->get_key_id( $this->get_key_material() );
		if ( $this->is_key_id_network_wide() ) {
			if ( hash_equals( $this->get_network_key_id(), $key_id ) ) {
				delete_site_option( $this->get_key_id_option_name() );
			}
		} elseif ( hash_equals( $this->get_site_key_id(), $key_id ) ) {
			delete_option( $this->get_key_id_option_name() );
		}

		// remember it where the key lives now.
		$this->key_is_network_wide = $place_obj->is_network_wide();
		if ( ! $this->key_is_network_wide ) {
			self::register_per_site_constant( $this->get_constant() );
		}
		$this->remember_key_id();
	}

	/**
	 * Take a freshly generated key into use: remember it as the key of this
	 * installation and save it in the configured place.
	 *
	 * A key that could not be saved is thrown away again. Otherwise it would
	 * only live for this request, and everything encrypted with it would be
	 * unreadable with the very next one.
	 *
	 * @return bool True if the new key may be used.
	 */
	protected function store_new_key(): bool {
		// bail if a place holds a key, which it cannot hand over right now:
		// a new key would replace it - and with it every value encrypted so far.
		if ( $this->is_stored_key_unavailable() ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'key_unavailable',
				'The key for encryption exists, but its place cannot hand it over right now. No new key is generated. Nothing is encrypted or decrypted until the key is available again.',
				array(
					'method'   => $this->get_name(),
					'constant' => $this->get_constant(),
				)
			);

			// never work with a key that only exists during this request.
			$this->set_hash( '' );

			return false;
		}

		// claim the key first - of two processes generating a key at the same
		// time only the one, which claimed it, is allowed to save its key.
		$this->remember_key_id();

		// bail if another process has been faster.
		if ( '' !== $this->get_expected_key_id() && ! $this->is_expected_key() ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'key_not_saved',
				'Another process generated the key for encryption at the same time. It is available with the next request.',
				array(
					'method' => $this->get_name(),
				)
			);

			// never work with a key that only exists during this request.
			$this->set_hash( '' );

			return false;
		}

		// save the key.
		$saved = false;
		try {
			$saved = $this->persist_key();
		} finally {
			if ( ! $saved ) {
				// release the claim: there is no key this installation could know.
				$this->forget_key_id();

				// never work with a key that only exists during this request.
				$this->set_hash( '' );
			}
		}

		return $saved;
	}

	/**
	 * Return whether a key is available, and report it if not.
	 *
	 * Without this check an empty key would be handed to the cipher, which
	 * would then "encrypt" with a key everybody knows.
	 *
	 * @return bool
	 */
	protected function has_key(): bool {
		// the key is there.
		if ( '' !== $this->get_hash() ) {
			return true;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'no_key_available',
			'No key for encryption is available.',
			array(
				'method' => $this->get_name(),
			)
		);

		return false;
	}

	/**
	 * Return a setting of the Crypt object, which is not one of a method.
	 *
	 * @param string $name The name of the setting.
	 *
	 * @return mixed The value, or null if it is not set.
	 */
	private function get_global_setting( string $name ): mixed {
		// bail if the Crypt class cannot hand out its settings (an older copy of this package).
		if ( ! method_exists( $this->get_crypt_obj(), 'get_config' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return null;
		}

		$configuration = $this->get_crypt_obj()->get_config();

		return $configuration[ $name ] ?? null;
	}

	/**
	 * Return whether new values are written in the versioned payload format.
	 *
	 * This is the default since version 4.3.0. Older copies of this package
	 * cannot read it. With 'legacy_format' => true, new values are written
	 * in the format of version 4.2.0 instead - e.g. as long as other plugins
	 * of the website ship an older copy of this package, which may be the
	 * one in use, see docs/MultiplePlugins.md. Values of both formats are
	 * read in any case.
	 *
	 * 'bind_site' needs the versioned format: with both settings nothing is
	 * encrypted, see is_payload_format_configured().
	 *
	 * @return bool
	 */
	protected function uses_versioned_payload(): bool {
		return ! (bool) $this->get_global_setting( 'legacy_format' );
	}

	/**
	 * Return whether the settings for the payload format can be met, and
	 * report it if not.
	 *
	 * A value is never encrypted without the binding to its site, if this
	 * has been requested: the format of version 4.2.0, requested with
	 * 'legacy_format', cannot hold it.
	 *
	 * @param string $context The context of the value.
	 *
	 * @return bool
	 */
	protected function is_payload_format_configured( string $context ): bool {
		// bail if the settings do not contradict each other.
		if ( $this->uses_versioned_payload() || ! $this->is_site_binding_requested() ) {
			return true;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'bind_site_not_possible',
			'A value cannot be bound to its site in the format of version 4.2.0. Remove one of the settings "bind_site" and "legacy_format".',
			array(
				'method'  => $this->get_name(),
				'context' => $context,
			)
		);

		return false;
	}

	/**
	 * Return whether new values should be bound to the site they are
	 * encrypted on.
	 *
	 * @return bool
	 */
	private function is_site_binding_requested(): bool {
		return (bool) $this->get_global_setting( 'bind_site' );
	}

	/**
	 * Return the ID of the key in use, as it is written into a payload.
	 *
	 * It is the fingerprint, which is remembered for this installation as
	 * well, see get_key_id_option_name(). It is no secret and cannot be used
	 * to decrypt anything.
	 *
	 * @return string The raw key ID.
	 */
	private function get_payload_key_id(): string {
		return (string) hex2bin( $this->get_key_id( $this->get_key_material() ) );
	}

	/**
	 * Return the header for a new value in the versioned payload format.
	 *
	 * It names the version of the format, the settings the value has been
	 * encrypted with and the ID of the key in use. A method writes it in
	 * front of the value and authenticates it with get_additional_data().
	 *
	 * @return array{version:int,flags:int,key_id:string,bytes:string}
	 */
	protected function create_payload_header(): array {
		$flags  = $this->is_site_binding_requested() ? self::PAYLOAD_FLAG_SITE : 0;
		$key_id = $this->get_payload_key_id();

		return array(
			'version' => self::PAYLOAD_VERSION,
			'flags'   => $flags,
			'key_id'  => $key_id,
			'bytes'   => self::PAYLOAD_MAGIC . chr( self::PAYLOAD_VERSION ) . chr( $flags ) . $key_id,
		);
	}

	/**
	 * Return the header of a value in the versioned payload format - or null
	 * if the value is not in this format.
	 *
	 * Nothing is reported here: the caller decides, whether the value may be
	 * one of an older format after all. A header of another version or with
	 * unknown flags is returned all the same, see is_payload_header_supported().
	 *
	 * @param string $payload The decoded value.
	 *
	 * @return array{version:int,flags:int,key_id:string,bytes:string}|null
	 */
	protected function read_payload_header( string $payload ): ?array {
		// bail if the value does not begin with the header.
		if ( strlen( $payload ) < self::PAYLOAD_HEADER_LENGTH || ! str_starts_with( $payload, self::PAYLOAD_MAGIC ) ) {
			return null;
		}

		$offset = strlen( self::PAYLOAD_MAGIC );

		return array(
			'version' => ord( $payload[ $offset ] ),
			'flags'   => ord( $payload[ $offset + 1 ] ),
			'key_id'  => substr( $payload, $offset + 2, self::PAYLOAD_KEY_ID_LENGTH ),
			'bytes'   => substr( $payload, 0, self::PAYLOAD_HEADER_LENGTH ),
		);
	}

	/**
	 * Return whether this version of the package can read a value with the
	 * given header.
	 *
	 * @param array{version:int,flags:int,key_id:string,bytes:string} $header The header of the value.
	 *
	 * @return bool
	 */
	protected function is_payload_header_supported( array $header ): bool {
		return self::PAYLOAD_VERSION === $header['version'] && 0 === ( $header['flags'] & ~self::PAYLOAD_FLAGS_KNOWN );
	}

	/**
	 * Report a value in a payload format, which this version of the package
	 * cannot read: it has been written by a newer version.
	 *
	 * @param array{version:int,flags:int,key_id:string,bytes:string} $header  The header of the value.
	 * @param string                                                  $context The context of the value.
	 *
	 * @return void
	 */
	protected function report_unsupported_payload( array $header, string $context ): void {
		$this->get_crypt_obj()->add_error(
			'payload_version_unknown',
			'The value has been written by a newer version of this package, in a format this version cannot read. Update the plugin, whose copy of this package is in use.',
			array(
				'method'  => $this->get_name(),
				'version' => $header['version'],
				'flags'   => $header['flags'],
				'context' => $context,
			)
		);
	}

	/**
	 * Return the additional data a value in the versioned payload format is
	 * authenticated with.
	 *
	 * This is the header - with it the version of the format, its settings
	 * and the key ID -, the data of the method in front of the encrypted
	 * value, the ID of the site, if the value is bound to it, and the
	 * context. None of them can be changed without the value failing to
	 * decrypt.
	 *
	 * All parts but the context have a fixed length for a header, so no
	 * part can ever be mistaken for another.
	 *
	 * @param array{version:int,flags:int,key_id:string,bytes:string} $header      The header of the value.
	 * @param string                                                  $method_data Data of the method with a fixed length, which is written after the header.
	 * @param string                                                  $context     The context of the value.
	 *
	 * @return string
	 */
	protected function get_additional_data( array $header, string $method_data, string $context ): string {
		$site = ( $header['flags'] & self::PAYLOAD_FLAG_SITE ) ? pack( 'J', get_current_blog_id() ) : '';

		return $header['bytes'] . $method_data . $site . $context;
	}

	/**
	 * Report a value in the versioned payload format, which could not be
	 * decrypted, because it belongs to another key than the one in use.
	 *
	 * The value names its key. If that is not the key in use, this is the
	 * reason - and not a changed value or a wrong context. Nothing is
	 * reported, if it is the key in use: the method reports its own reason
	 * then.
	 *
	 * @param array{version:int,flags:int,key_id:string,bytes:string} $header  The header of the value.
	 * @param string                                                  $context The context of the value.
	 *
	 * @return bool True if the value belongs to another key, and this has been reported.
	 */
	protected function report_other_key( array $header, string $context ): bool {
		$key_id = $this->get_payload_key_id();

		// bail if the value names the key in use.
		if ( hash_equals( $key_id, $header['key_id'] ) ) {
			return false;
		}

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_mismatch',
			'The value has been encrypted with another key than the one in use. It can only be decrypted with that key: restore it from a backup.',
			array(
				'method'         => $this->get_name(),
				'key_id'         => bin2hex( $header['key_id'] ),
				'current_key_id' => bin2hex( $key_id ),
				'context'        => $context,
			)
		);

		return true;
	}

	/**
	 * Return the name of the option, which holds the previous key of this
	 * method after a rotation.
	 *
	 * The previous key is not saved in a place: not every place can hold a
	 * second key. It is encrypted with the current key instead - so it is
	 * as safe as every other value in the database: nobody can read it
	 * without the key in its place. Each entry names the key it holds
	 * ("key_id") and the key it is encrypted with ("wrapped_by"), see
	 * get_previous_key_entry().
	 *
	 * @return string
	 */
	public function get_previous_key_option_name(): string {
		return $this->get_crypt_obj()->get_slug() . '_crypt_previous_key_' . $this->get_name();
	}

	/**
	 * Return the entries of the option holding the previous key - where the
	 * fingerprint of the key in use lives.
	 *
	 * @return array<int,array{key_id:string,wrapped_by:string,value:string}>
	 */
	private function get_previous_key_entries(): array {
		$option_name = $this->get_previous_key_option_name();
		$value       = $this->is_key_id_network_wide() ? get_site_option( $option_name, array() ) : get_option( $option_name, array() );

		// bail if the option holds nothing usable.
		if ( ! is_array( $value ) ) {
			return array();
		}

		// take over every entry, which has the expected form.
		$entries = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key_id'], $entry['wrapped_by'], $entry['value'] ) || ! is_string( $entry['key_id'] ) || ! is_string( $entry['wrapped_by'] ) || ! is_string( $entry['value'] ) ) {
				continue;
			}

			$entries[] = array(
				'key_id'     => $entry['key_id'],
				'wrapped_by' => $entry['wrapped_by'],
				'value'      => $entry['value'],
			);
		}

		return $entries;
	}

	/**
	 * Save the entries of the option holding the previous key, and check
	 * that they really arrived there.
	 *
	 * The option is never loaded with every request.
	 *
	 * @param array<int,array{key_id:string,wrapped_by:string,value:string}> $entries The entries.
	 *
	 * @return bool True if the option holds the entries.
	 */
	private function save_previous_key_entries( array $entries ): bool {
		$option_name = $this->get_previous_key_option_name();
		$entries     = array_values( $entries );

		// remove the option, if there is nothing to keep.
		if ( empty( $entries ) ) {
			if ( $this->is_key_id_network_wide() ) {
				delete_site_option( $option_name );
			} else {
				delete_option( $option_name );
			}

			return empty( $this->get_previous_key_entries() );
		}

		// save the entries - not loaded with every request.
		if ( $this->is_key_id_network_wide() ) {
			update_site_option( $option_name, $entries );
		} else {
			update_option( $option_name, $entries, false );

			// update_option() keeps the autoload, if the value has not changed.
			Helper::disable_option_autoload( $option_name );
		}

		// do not trust the write.
		return $this->get_previous_key_entries() === $entries;
	}

	/**
	 * Return whether this object still works with a key, which has been
	 * changed during this request by another object.
	 *
	 * @return bool
	 */
	private function is_outdated(): bool {
		$constant = $this->get_constant();

		return isset( self::$changed_keys[ $constant ] ) && self::$changed_keys[ $constant ] !== $this->get_hash_value();
	}

	/**
	 * Return the entry of the previous key, which is encrypted with the key
	 * in use - or null, if there is none.
	 *
	 * Entries, which hold the key in use, are removed on the way: they are
	 * left by a key change, which has ended before the new key could be
	 * saved in its place. Entries encrypted with another key are left alone.
	 *
	 * @return array{key_id:string,wrapped_by:string,value:string}|null
	 */
	private function get_previous_key_entry(): ?array {
		// bail if this object does not work with the key in use: what it would remove is not stale.
		if ( $this->is_outdated() ) {
			return null;
		}

		$current_key_id = $this->get_key_id( $this->get_key_material() );
		$entries        = $this->get_previous_key_entries();

		// remove the entries of a key change, which has not been finished.
		$remaining = array_values(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $current_key_id ): bool {
					return ! hash_equals( $current_key_id, $entry['key_id'] );
				}
			)
		);
		if ( count( $remaining ) !== count( $entries ) ) {
			$this->save_previous_key_entries( $remaining );
		}

		// return the entry, which is encrypted with the key in use.
		foreach ( $remaining as $entry ) {
			if ( hash_equals( $current_key_id, $entry['wrapped_by'] ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Return whether the key in use is the new key of a rotation, whose
	 * fingerprint has not been remembered: the request has ended between
	 * saving the key and remembering its fingerprint.
	 *
	 * It is, if the previous key is the key this installation is known to
	 * use, and it is encrypted with the key in use.
	 *
	 * @return bool
	 */
	private function is_key_of_unfinished_rotation(): bool {
		// bail if the key is derived by its place: it is never rotated.
		if ( '' !== $this->derived_key ) {
			return false;
		}

		$current_key_id  = $this->get_key_id( $this->get_key_material() );
		$expected_key_id = $this->get_expected_key_id();

		foreach ( $this->get_previous_key_entries() as $entry ) {
			if ( hash_equals( $current_key_id, $entry['wrapped_by'] ) && hash_equals( $expected_key_id, $entry['key_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the previous key, as it is stored - or an empty string if
	 * there is none.
	 *
	 * After a rotation the previous key decrypts every value, which has
	 * been encrypted before, until it is discarded.
	 *
	 * @return string
	 */
	protected function get_previous_key(): string {
		// bail if this object works with another key right now, or with the key of another site.
		if ( $this->is_using_other_key || $this->is_key_of_another_site() ) {
			return '';
		}

		// bail if it has been looked up already.
		if ( null !== $this->previous_key ) {
			return $this->previous_key;
		}
		$this->previous_key = '';

		// bail if there is no key, or the place derives it: it has never been rotated.
		if ( '' === $this->get_hash() || '' !== $this->derived_key ) {
			return '';
		}

		// bail if there is no previous key.
		$entry = $this->get_previous_key_entry();
		if ( null === $entry ) {
			return '';
		}

		// decrypt it with the key in use.
		$previous_key = $this->decrypt_with_context( $entry['value'], self::PREVIOUS_KEY_CONTEXT );

		// bail if it could not be decrypted, or is not the key the entry names.
		if ( '' === $previous_key || ! hash_equals( $entry['key_id'], $this->get_key_id( $previous_key ) ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'previous_key_unreadable',
				'The previous key could not be decrypted. Values encrypted with it cannot be decrypted.',
				array(
					'method' => $this->get_name(),
					'key_id' => $entry['key_id'],
				)
			);

			// do nothing more.
			return '';
		}

		$this->previous_key = $previous_key;

		return $previous_key;
	}

	/**
	 * Remove the previous key of this method - for the site and the network.
	 *
	 * Values, which are still encrypted with it, cannot be decrypted
	 * anymore. See Crypt::discard_previous_keys().
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return void
	 */
	public function discard_previous_key(): void {
		delete_option( $this->get_previous_key_option_name() );

		if ( is_multisite() ) {
			delete_site_option( $this->get_previous_key_option_name() );
		}

		$this->previous_key = null;
	}

	/**
	 * Run the given callback with another key than the one of this object.
	 *
	 * Everything, which uses the key - get_hash(), the fingerprint, the key
	 * ID of new values - works with the other key during the callback. The
	 * own key is restored afterward in any case.
	 *
	 * @template T
	 *
	 * @param string       $stored_key The other key, as it is stored.
	 * @param callable():T $callback   The callback.
	 *
	 * @return T
	 */
	private function run_with_key( #[\SensitiveParameter] string $stored_key, callable $callback ): mixed {
		$hash               = $this->hash;
		$derived_key        = $this->derived_key;
		$is_using_other_key = $this->is_using_other_key;

		$this->set_hash( $this->get_hash_from_stored_key( $stored_key ) );
		$this->derived_key        = '';
		$this->is_using_other_key = true;

		try {
			return $callback();
		} finally {
			$this->hash               = $hash;
			$this->derived_key        = $derived_key;
			$this->is_using_other_key = $is_using_other_key;
		}
	}

	/**
	 * Return the hash this method works with for a key, as it is stored.
	 *
	 * A method, which decodes its key in init(), overrides this.
	 *
	 * @param string $stored_key The key as it is stored.
	 *
	 * @return string
	 */
	protected function get_hash_from_stored_key( #[\SensitiveParameter] string $stored_key ): string {
		return $stored_key;
	}

	/**
	 * Return whether this method writes new values in the versioned payload
	 * format, see create_payload_header().
	 *
	 * Only for such a method, a value names its key - and only such a value
	 * can be told to be encrypted in a format of an older version.
	 *
	 * @return bool
	 */
	protected function writes_payload_header(): bool {
		return false;
	}

	/**
	 * Return the header of the given value, if it is one in a versioned
	 * payload format this version can read - or null.
	 *
	 * @param string $encrypted_text The encrypted value.
	 *
	 * @return array{version:int,flags:int,key_id:string,bytes:string}|null
	 */
	private function get_header_of_value( string $encrypted_text ): ?array {
		// bail if this method writes no header.
		if ( ! $this->writes_payload_header() ) {
			return null;
		}

		// get the payload. Every method of this package encodes it with base64.
		$payload = base64_decode( $encrypted_text, true );

		// bail if it is not base64 encoded.
		if ( ! is_string( $payload ) ) {
			return null;
		}

		// get the header.
		$header = $this->read_payload_header( $payload );

		return null !== $header && $this->is_payload_header_supported( $header ) ? $header : null;
	}

	/**
	 * Decrypt a given string with the key in use - or with the previous
	 * key, if the value has been encrypted with it.
	 *
	 * A value in the versioned payload format names its key: it is
	 * decrypted with the key it names. A value of an older format does not:
	 * it is decrypted with the key in use first, and only if that fails,
	 * with the previous key. The errors of the attempt, which failed, are
	 * not reported, unless both fail.
	 *
	 * @internal Used for internal tasks, see Crypt::decrypt().
	 *
	 * @param string $encrypted_text The encrypted string.
	 * @param string $context        The context the value has been encrypted with, or an empty string.
	 *
	 * @return string
	 */
	public function decrypt_with_any_key( string $encrypted_text, string $context ): string {
		$this->used_previous_key = false;

		// decrypt with the key of this object - or the one run_with_key() has set.
		$decrypt = function () use ( $encrypted_text, $context ): string {
			return '' === $context ? $this->decrypt( $encrypted_text ) : $this->decrypt_with_context( $encrypted_text, $context );
		};

		// bail if there is no previous key: decrypt as always.
		$previous_key = '' === $encrypted_text ? '' : $this->get_previous_key();
		if ( '' === $previous_key ) {
			return $decrypt();
		}

		// decrypt a value, which names its key, with that key.
		$header = $this->get_header_of_value( $encrypted_text );
		if ( null !== $header ) {
			// bail if it is not the previous key: the method reports, if it is another one.
			if ( ! hash_equals( (string) hex2bin( $this->get_key_id( $previous_key ) ), $header['key_id'] ) ) {
				return $decrypt();
			}

			$this->used_previous_key = true;

			return $this->run_with_key( $previous_key, $decrypt );
		}

		// decrypt a value of an older format with the key in use.
		$crypt_obj                   = $this->get_crypt_obj();
		list( $plain_text, $errors ) = $crypt_obj->collect_errors( $decrypt );

		// return it, if it has been decrypted - or if its content is empty.
		if ( '' !== $plain_text || empty( $errors ) ) {
			$crypt_obj->add_errors( $errors );
			return $plain_text;
		}

		// decrypt it with the previous key.
		list( $previous_plain_text, $previous_errors ) = $crypt_obj->collect_errors(
			function () use ( $previous_key, $decrypt ): string {
				return $this->run_with_key( $previous_key, $decrypt );
			}
		);

		// return it, if this worked.
		if ( '' !== $previous_plain_text ) {
			$this->used_previous_key = true;
			$crypt_obj->add_errors( $previous_errors );

			return $previous_plain_text;
		}

		// report why the key in use failed: with it, the value should have been decrypted.
		$crypt_obj->add_errors( $errors );

		return '';
	}

	/**
	 * Return whether the value, which decrypt_with_any_key() has decrypted
	 * last, should be encrypted again: it has been encrypted with the
	 * previous key, in the format of an older version, or with other
	 * settings than the current ones (e.g. 'bind_site').
	 *
	 * @internal Used for internal tasks, see Crypt::reencrypt().
	 *
	 * @param string $encrypted_text The encrypted value.
	 *
	 * @return bool
	 */
	public function needs_new_encryption( string $encrypted_text ): bool {
		// a value of the previous key needs the key in use.
		if ( $this->used_previous_key ) {
			return true;
		}

		// bail if the format of the value cannot be told, or the format of version 4.2.0 is requested.
		if ( ! $this->writes_payload_header() || ! $this->uses_versioned_payload() ) {
			return false;
		}

		// a value without a header has been written in the format of an older version.
		$header = $this->get_header_of_value( $encrypted_text );
		if ( null === $header ) {
			return true;
		}

		// a value with other settings than the current ones.
		return $this->create_payload_header()['flags'] !== $header['flags'];
	}

	/**
	 * Return a new key for this method, as it is stored - or an empty
	 * string, if this method cannot generate one.
	 *
	 * A method of your own has to override this to support rotate_key().
	 *
	 * @return string
	 */
	protected function generate_key(): string {
		return '';
	}

	/**
	 * Replace the key in use by a new one, and keep it as previous key.
	 *
	 * See Crypt::rotate_key().
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return bool True if the key has been replaced.
	 */
	public function rotate_key(): bool {
		// take over a key, which another object has changed during this request.
		$this->refresh_key();

		$reason = $this->get_reason_against_key_change();

		// a rotation would make the previous key unreadable: it is only
		// encrypted with the key, which is replaced.
		if ( '' === $reason && null !== $this->get_previous_key_entry() ) {
			$reason = 'previous_key_exists';
		}

		// get the new key.
		$new_key = '';
		if ( '' === $reason ) {
			try {
				$new_key = $this->generate_key();
			} catch ( \Exception $e ) {
				$new_key = '';
			}

			if ( '' === $new_key ) {
				$reason = 'key_not_generated';
			}
		}

		// bail if the key cannot be replaced.
		if ( '' !== $reason ) {
			$this->report_key_change_not_possible( $reason );
			return false;
		}

		return $this->change_key( $new_key );
	}

	/**
	 * Use the previous key again, and keep the key in use as previous key.
	 *
	 * See Crypt::rollback_key_rotation().
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return bool True if the key has been replaced.
	 */
	public function rollback_key_rotation(): bool {
		// take over a key, which another object has changed during this request.
		$this->refresh_key();

		$reason       = $this->get_reason_against_key_change();
		$previous_key = '' === $reason ? $this->get_previous_key() : '';

		// bail if there is no previous key to use.
		if ( '' === $reason && '' === $previous_key ) {
			$reason = 'no_previous_key';
		}

		// bail if the key cannot be replaced.
		if ( '' !== $reason ) {
			$this->report_key_change_not_possible( $reason );
			return false;
		}

		return $this->change_key( $previous_key );
	}

	/**
	 * Return why the key in use cannot be replaced - or an empty string, if
	 * it can.
	 *
	 * It can only be replaced, where it is saved: in the active place. A key
	 * of another place would keep being loaded instead of the new one, and a
	 * place, which derives its key or cannot save one, cannot take a new one.
	 *
	 * @return string
	 */
	private function get_reason_against_key_change(): string {
		// bail if there is no key.
		if ( '' === $this->get_hash() ) {
			return 'no_key';
		}

		// bail if the place derives the key itself.
		if ( '' !== $this->derived_key ) {
			return 'derived_key';
		}

		// bail if the key in use belongs to another site.
		if ( $this->is_key_of_another_site() ) {
			return 'key_of_another_site';
		}

		// bail if there is no place to save a new key in.
		if ( ! $this->place_obj instanceof Place_Base ) {
			return 'no_place';
		}

		// bail if the key in use is not saved in the active place.
		$this->place_obj->set_constant( $this->get_constant() );
		if ( ! $this->place_obj->is_saved( $this->get_hash_value() ) ) {
			return 'key_not_in_active_place';
		}

		return '';
	}

	/**
	 * Report that the key cannot be replaced.
	 *
	 * @param string $reason The reason, see get_reason_against_key_change().
	 *
	 * @return void
	 */
	private function report_key_change_not_possible( string $reason ): void {
		$messages = array(
			'no_key'                  => 'There is no key to replace.',
			'derived_key'             => 'The key is derived by its place, e.g. from the WordPress salts. Such a key cannot be replaced by the package.',
			'key_of_another_site'     => 'The key in use belongs to another site of the network. Rotate it during a request of that site.',
			'no_place'                => 'There is no place to save a new key in.',
			'key_not_in_active_place' => 'The key is not saved in the active place, e.g. because it is given by a variable of the environment or the server. Only a key saved in the active place can be replaced.',
			'previous_key_exists'     => 'The previous key of the last rotation still exists. Encrypt every value again with reencrypt() and discard it with discard_previous_keys() first.',
			'no_previous_key'         => 'There is no previous key to use again.',
			'key_not_generated'       => 'No new key could be generated.',
		);

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_rotation_not_possible',
			'The key cannot be replaced. ' . ( $messages[ $reason ] ?? '' ),
			array(
				'method' => $this->get_name(),
				'reason' => $reason,
			)
		);
	}

	/**
	 * Replace the key in use by the given one, and keep the key in use as
	 * previous key.
	 *
	 * The order makes every step safe, if the request ends in between:
	 *
	 * 1. The key in use is kept as previous key, encrypted with the new one.
	 *    If the request ends here, the entry holds the key in use, and it is
	 *    removed with the next request, see get_previous_key_entry().
	 * 2. The new key is saved in the active place - nowhere else: a key in
	 *    another place would not be loaded first. If this fails, the key in
	 *    use stays, and the entry is removed again.
	 * 3. Its fingerprint is remembered. If the request ends before, the next
	 *    request recognizes the rotation, see is_key_of_unfinished_rotation().
	 *
	 * @param string $new_stored_key The new key, as it is stored.
	 *
	 * @return bool True if the key has been replaced.
	 */
	private function change_key( #[\SensitiveParameter] string $new_stored_key ): bool {
		$constant       = $this->get_constant();
		$old_stored_key = $this->get_hash_value();
		$old_key_id     = $this->get_key_id( $old_stored_key );
		$new_key_id     = $this->get_key_id( $new_stored_key );

		// encrypt the key in use with the new key.
		$wrapped = $this->run_with_key(
			$new_stored_key,
			function () use ( $old_stored_key ): string {
				return $this->encrypt_with_context( $old_stored_key, self::PREVIOUS_KEY_CONTEXT );
			}
		);

		// keep it as previous key.
		$entries   = $this->get_previous_key_entries();
		$entries[] = array(
			'key_id'     => $old_key_id,
			'wrapped_by' => $new_key_id,
			'value'      => $wrapped,
		);

		// bail if it could not be kept: the key in use stays.
		if ( '' === $wrapped || ! $this->save_previous_key_entries( $entries ) ) {
			// remove what may have arrived.
			$this->save_previous_key_entries( array_slice( $entries, 0, -1 ) );

			// log this error.
			$this->get_crypt_obj()->add_error(
				'previous_key_not_saved',
				'The key in use could not be kept as previous key. It has not been replaced.',
				array(
					'method' => $this->get_name(),
				)
			);

			// do nothing more.
			return false;
		}

		// save the new key in the active place.
		$place_obj = $this->place_obj;
		if ( $place_obj instanceof Place_Base ) {
			$place_obj->set_constant( $constant );
			$place_obj->save( $new_stored_key );
		}

		// bail if the place does not hold it: the key in use stays.
		if ( ! $place_obj instanceof Place_Base || ! $place_obj->is_saved( $new_stored_key ) ) {
			// make sure the key in use is saved somewhere.
			if ( $place_obj instanceof Place_Base && ! $place_obj->is_saved( $old_stored_key ) ) {
				$place_obj->save( $old_stored_key );
				if ( ! $place_obj->is_saved( $old_stored_key ) ) {
					$this->get_crypt_obj()->save_in_place( $constant, $old_stored_key );
				}
			}

			// the entry is not needed: the key in use has no previous key now.
			$this->save_previous_key_entries( array_slice( $entries, 0, -1 ) );

			// log this error.
			$this->get_crypt_obj()->add_error(
				'key_not_saved',
				'The new key could not be saved in the active place. The key in use has not been replaced.',
				array(
					'method'   => $this->get_name(),
					'constant' => $constant,
				)
			);

			// do nothing more.
			return false;
		}

		// use the new key from now on - also every other object of this request.
		$this->set_hash( $this->get_hash_from_stored_key( $new_stored_key ) );
		self::$changed_keys[ $constant ] = $new_stored_key;

		// remember it as the key of this installation.
		$this->remember_changed_key_id();

		// remove the entries, which hold the new key: after a rollback this
		// is the entry it has been kept in so far.
		$this->save_previous_key_entries(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $new_key_id ): bool {
					return ! hash_equals( $new_key_id, $entry['key_id'] );
				}
			)
		);
		$this->previous_key = $old_stored_key;

		$slug   = $this->get_crypt_obj()->get_slug();
		$method = $this->get_name();
		/**
		 * Run tasks after the key has been replaced with rotate_key() or
		 * rollback_key_rotation().
		 *
		 * The fingerprints are no secrets: they cannot be used to decrypt
		 * anything. Use this e.g. to start encrypting your values again with
		 * reencrypt().
		 *
		 * @since 4.3.0 Available since 4.3.0.
		 *
		 * @param string $old_key_id The fingerprint of the former key.
		 * @param string $new_key_id The fingerprint of the new key.
		 * @param string $method     The name of the method.
		 */
		do_action( $slug . '_crypt_key_rotated', $old_key_id, $new_key_id, $method );

		return true;
	}

	/**
	 * Take over a key, which another object has changed during this request.
	 *
	 * @internal Used for internal tasks, see Crypt::get_method().
	 *
	 * @return void
	 */
	public function refresh_key(): void {
		// bail if this object works with another key right now, its place derives the key, or it is up to date.
		if ( $this->is_using_other_key || '' !== $this->derived_key || ! $this->is_outdated() ) {
			return;
		}

		$this->set_hash( $this->get_hash_from_stored_key( self::$changed_keys[ $this->get_constant() ] ) );
		$this->previous_key = null;
	}

	/**
	 * Put the key of this method aside, before the places are cleaned up
	 * during an uninstallation. Does nothing if it has been done already.
	 *
	 * The key is kept as secret with the Secrets API of WordPress, if it can
	 * take it: it is encrypted there. Otherwise, it is kept in an option in
	 * the database, which is not loaded with every request. A later
	 * installation reads it from there, see get_key_kept_for_uninstall().
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return void
	 */
	public function keep_key_for_uninstall(): void {
		// bail if this has been done already.
		if ( $this->key_kept_for_uninstall ) {
			return;
		}
		$this->key_kept_for_uninstall = true;

		// bail if this method has no option to keep a key in.
		$option_name = $this->get_uninstall_option_name();
		if ( '' === $option_name ) {
			return;
		}

		// get the key to keep.
		$stored_key = $this->get_stored_key_for_uninstall();

		// bail if there is no key.
		if ( '' === $stored_key ) {
			// no key is kept, so there is none left to protect.
			$this->forget_key_id();

			// do nothing more.
			return;
		}

		// keep the key as secret, exactly as it is - whether it is the known
		// key of this installation is decided when it is used again.
		$place_obj = $this->get_secrets_api_place();
		if ( $place_obj instanceof Places\SecretsApi && $place_obj->keep_key( $this->get_name(), $stored_key ) ) {
			// an older uninstallation may have left this key in plain text in the database.
			if ( get_option( $option_name, '' ) === $stored_key ) {
				delete_option( $option_name );
			}

			// do nothing more.
			return;
		}

		// save the key in the database instead - not loaded with every request.
		update_option( $option_name, $stored_key, false );

		// update_option() keeps the autoload, if the value has not changed.
		Helper::disable_option_autoload( $option_name );
	}

	/**
	 * Return the key an uninstallation has kept, as it is stored - or an
	 * empty string.
	 *
	 * The secret is asked first, then the option in the database: an
	 * uninstallation keeps the key in the option only, if the Secrets API
	 * could not take it - or if it has been done by a version before 4.3.0.
	 *
	 * If the secret exists, but cannot be read, it is reported, and nothing
	 * is returned. The key is unavailable then, so no new key is generated,
	 * see is_stored_key_unavailable().
	 *
	 * @return string
	 */
	protected function get_key_kept_for_uninstall(): string {
		$this->kept_key_source = '';

		// bail if this method keeps no key during an uninstallation.
		$option_name = $this->get_uninstall_option_name();
		if ( '' === $option_name ) {
			return '';
		}

		// get the key kept as secret.
		$place_obj = $this->get_secrets_api_place();
		if ( $place_obj instanceof Places\SecretsApi ) {
			$kept_key = $place_obj->get_kept_key( $this->get_name() );

			// bail if it cannot be read: it is the key all values have been encrypted with.
			if ( $kept_key instanceof WP_Error ) {
				// log this error.
				$this->get_crypt_obj()->add_error(
					'secrets_api_unavailable',
					'The key for encryption, which has been kept during an uninstallation, could not be read from the Secrets API of WordPress. No new key is generated. Restore WP_SECRETS_KEY or the WordPress salts used before - or start over with a new key with discard_kept_keys(), which leaves the values encrypted so far unreadable.',
					array(
						'secret'   => $place_obj->get_kept_key_name( $this->get_name() ),
						'reason'   => $kept_key->get_error_code(),
						'kept_key' => true,
					)
				);

				// do nothing more.
				return '';
			}

			// use it, if there is one.
			if ( '' !== $kept_key ) {
				$this->kept_key_source = 'secret';
				return $kept_key;
			}
		}

		// get the key kept in the database.
		$option_value = get_option( $option_name, '' );
		if ( ! is_string( $option_value ) || '' === $option_value ) {
			return '';
		}

		$this->kept_key_source = 'option';
		return $option_value;
	}

	/**
	 * Remove the key an uninstallation has kept, after it has been saved in
	 * its place - from where get_key_kept_for_uninstall() has found it.
	 *
	 * @return void
	 */
	protected function forget_key_kept_for_uninstall(): void {
		// remove the secret.
		if ( 'secret' === $this->kept_key_source ) {
			$place_obj = $this->get_secrets_api_place();
			if ( $place_obj instanceof Places\SecretsApi ) {
				$place_obj->forget_kept_key( $this->get_name() );
			}
		}

		// remove the option.
		if ( 'option' === $this->kept_key_source && '' !== $this->get_uninstall_option_name() ) {
			delete_option( $this->get_uninstall_option_name() );
		}

		$this->kept_key_source = '';
	}

	/**
	 * Remove the key an uninstallation has kept for this method - as secret
	 * and in the database -, whether it can be read or not.
	 *
	 * @internal Used for internal tasks, see Crypt::discard_kept_keys().
	 *
	 * @return void
	 */
	public function discard_kept_key(): void {
		// bail if this method keeps no key during an uninstallation.
		$option_name = $this->get_uninstall_option_name();
		if ( '' === $option_name ) {
			return;
		}

		// remove the secret.
		$place_obj = $this->get_secrets_api_place();
		if ( $place_obj instanceof Places\SecretsApi ) {
			$place_obj->forget_kept_key( $this->get_name() );
		}

		// remove the option.
		delete_option( $option_name );

		// the previous key is encrypted with the kept key: nothing can read it without it.
		$this->discard_previous_key();

		$this->kept_key_source = '';
	}

	/**
	 * Return the place "secrets_api", if it can keep a key during an
	 * uninstallation - or null.
	 *
	 * It is used whatever the order of the places is, and also if a
	 * configured "force_place" hides it. Only 'block_secrets_api' prevents
	 * its usage.
	 *
	 * @return Places\SecretsApi|null
	 */
	private function get_secrets_api_place(): ?Places\SecretsApi {
		// get the place: an older Crypt class can only hand out the places a
		// configured "force_place" does not hide.
		$crypt_obj = $this->get_crypt_obj();
		$places    = method_exists( $crypt_obj, 'get_place_by_name' ) ? array( $crypt_obj->get_place_by_name( 'secrets_api' ) ) : $crypt_obj->get_places_as_objects(); // @phpstan-ignore function.alreadyNarrowedType

		foreach ( $places as $place_obj ) {
			// bail if it is not the place of the Secrets API, or of an older copy of this package, which cannot keep a key.
			if ( ! $place_obj instanceof Places\SecretsApi || ! method_exists( $place_obj, 'keep_key' ) ) { // @phpstan-ignore function.alreadyNarrowedType
				continue;
			}

			return $place_obj;
		}

		return null;
	}

	/**
	 * Return the name of the option the key of this method is kept in
	 * during an uninstallation, or an empty string if it keeps none.
	 *
	 * @return string
	 */
	protected function get_uninstall_option_name(): string {
		return '';
	}

	/**
	 * Uninstall this method.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// put the key aside, if this has not been done yet.
		$this->keep_key_for_uninstall();

		foreach ( $this->get_crypt_obj()->get_places_as_objects() as $obj ) {
			$obj->uninstall( $this->get_constant() );
		}
	}

	/**
	 * Set the configuration.
	 *
	 * @param array<string,mixed> $configuration The configuration to use.
	 * @return void
	 */
	public function set_config( array $configuration ): void {
		$this->configuration = array_merge( $this->configuration, $configuration );
	}

	/**
	 * Return the configured crypt object.
	 *
	 * @return Crypt
	 */
	protected function get_crypt_obj(): Crypt {
		return $this->crypt_obj;
	}

	/**
	 * Set the place this method gets its key from.
	 *
	 * @param Place_Base $place_obj The place object.
	 * @return void
	 */
	public function set_place( Place_Base $place_obj ): void {
		$this->place_obj           = $place_obj;
		$this->key_is_network_wide = $place_obj->is_network_wide();
	}

	/**
	 * Return the raw key material the configured place derives itself, if any.
	 *
	 * @return string
	 */
	protected function get_derived_key(): string {
		// bail if no place has been set.
		if ( ! $this->place_obj instanceof Place_Base ) {
			return '';
		}

		// get whatever the place is able to derive.
		$this->derived_key = $this->place_obj->get_derived_key();

		return $this->derived_key;
	}
}
