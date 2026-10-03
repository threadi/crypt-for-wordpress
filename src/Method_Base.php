<?php
/**
 * File to handle crypt methods as base-object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

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
		$constant = strtoupper( $this->get_crypt_obj()->get_slug() ) . '-HASH';

		/**
		 * Filter the name of the constant.
		 *
		 * @since 1.1.2 Available since 1.1.2.
		 * @param string $constant The name of the constant.
		 */
		return apply_filters( $this->get_crypt_obj()->get_slug() . '_crypt_constant', $constant );
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

		// log this error.
		$this->get_crypt_obj()->add_error(
			'key_changed',
			'The key found for encryption is not the key this installation has used so far. It is used from now on. Values encrypted with the former key cannot be decrypted anymore, unless that key is restored.',
			array(
				'method' => $this->get_name(),
			)
		);

		// this is the key from now on. If it is a key of the network, a
		// fingerprint of this site no longer stands against it.
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
	 * Put the key of this method aside, before the places are cleaned up
	 * during an uninstallation. Does nothing if it has been done already.
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

		// save the key in the database, exactly as it is - whether it is the
		// known key of this installation is decided when it is used again.
		update_option( $option_name, $stored_key );
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
