<?php
/**
 * File to handle the main crypt tasks.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ReflectionClass;
use ReflectionException;
use WP_Error;

/**
 * Object to handle crypt tasks.
 */
class Crypt {
	/**
	 * The files of the other classes of this package, relative to the
	 * directory of this file. Base classes first.
	 *
	 * The name of a class is the path of its file - they are not listed as
	 * names, so tools which change the namespace of this package have
	 * nothing to rewrite here.
	 *
	 * @var array<int,string>
	 */
	private const PACKAGE_FILES = array(
		'Helper.php',
		'Method_Base.php',
		'Place_Base.php',
		'Methods/OpenSsl.php',
		'Methods/Sodium.php',
		'Methods/Plain.php',
		'Places/WpConfig.php',
		'Places/MuPlugin.php',
		'Places/SecretsApi.php',
		'Places/Database.php',
		'Places/CustomFile.php',
		'Places/EnvironmentVariable.php',
		'Places/ServerVariable.php',
		'Places/WordPressSalts.php',
	);

	/**
	 * Whether the other classes of this package have been loaded.
	 *
	 * @var bool
	 */
	private static bool $package_classes_loaded = false;

	/**
	 * Define the method for crypt-tasks.
	 *
	 * @var false|Method_Base
	 */
	private false|Method_Base $method = false;

	/**
	 * The plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * The slug to use.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The file the slug has been derived from, or an empty string if the
	 * slug has been set with set_slug().
	 *
	 * @var string
	 */
	private string $slug_derived_from;

	/**
	 * Whether the derived slug has been checked already.
	 *
	 * @var bool
	 */
	private bool $slug_checked = false;

	/**
	 * The method configurations.
	 *
	 * @var array<string,array<string,mixed>|string>
	 */
	private array $configuration = array();

	/**
	 * The places save_in_place() has saved a hash in during this request,
	 * per constant.
	 *
	 * @var array<string,Place_Base>
	 */
	private array $saved_places = array();

	/**
	 * List of errors.
	 *
	 * @var WP_Error|null
	 */
	protected ?WP_Error $errors = null;

	/**
	 * The number of errors, which have been added during the life of this
	 * object - clear_errors() does not reset it.
	 *
	 * @var int
	 */
	private int $error_count = 0;

	/**
	 * The errors added since the last call of clear_errors(), with their
	 * number, each with its code, message and data.
	 *
	 * WP_Error keeps the data of an error only if it is not empty, so its
	 * messages and data cannot be matched up again. This list keeps them
	 * together, so encrypt_or_error() and decrypt_or_error() can tell which
	 * errors happened during their call.
	 *
	 * @var array<int,array{code:string,message:string,data:array<string,mixed>}>
	 */
	private array $error_log = array();

	/**
	 * Constructor for this object.
	 *
	 * @param string $plugin_path The path to the WordPress plugin using this object. E.g., __FILE__.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_file       = $plugin_path;
		$this->slug              = dirname( plugin_basename( $plugin_path ) );
		$this->slug_derived_from = $plugin_path;
	}

	/**
	 * Report a derived slug, which is not suited to name the key.
	 *
	 * The slug is the name of the directory of the plugin. Where there is no
	 * such directory, what is derived instead causes trouble later:
	 *
	 * - A plugin consisting of a single file and a Must-Use plugin all get
	 *   the slug ".", and with it the same key.
	 * - A file which is not part of a plugin, e.g. of a theme, gets a slug
	 *   containing the path of this installation. If the website is moved,
	 *   the slug changes - and the key saved under the former one is not
	 *   found anymore.
	 *
	 * The slug is not changed here: that would be a new key for every
	 * installation already using it. It is only reported, once per object,
	 * so the developer can set a slug with set_slug().
	 *
	 * @return void
	 */
	private function report_unsuitable_slug(): void {
		// bail if this has been done already.
		if ( $this->slug_checked ) {
			return;
		}
		$this->slug_checked = true;

		// bail if the slug has been set by the developer.
		if ( '' === $this->slug_derived_from ) {
			return;
		}

		// the file has no directory of its own.
		if ( '.' === $this->slug ) {
			// log this as warning.
			$this->add_error(
				'slug_not_unique',
				'The file given to the Crypt object has no directory of its own, so its slug - and with it the key - is shared with every other plugin of that kind. Set a slug with set_slug().'
			);

			// do nothing more.
			return;
		}

		// the file is not part of a plugin: its path has been left as it is.
		if ( trim( wp_normalize_path( $this->slug_derived_from ), '/' ) === plugin_basename( $this->slug_derived_from ) ) {
			// log this as warning.
			$this->add_error(
				'slug_depends_on_path',
				'The file given to the Crypt object is not part of a plugin, so its slug contains the path of this installation. If the website is moved, the key is not found anymore. Set a slug with set_slug().',
				array(
					'slug' => $this->slug,
				)
			);
		}
	}

	/**
	 * Load the other classes of this package from the directory of this file.
	 *
	 * Several plugins of a website may ship this package, in different
	 * versions. An autoloader is asked per class: without this, the methods
	 * and places could be taken from the copy of another plugin than this
	 * class - classes of two versions, which do not fit together.
	 *
	 * These are the files the autoloader would load anyway as soon as a
	 * method or a place is needed.
	 *
	 * If one of the classes has been loaded from another copy already - e.g.
	 * as the parent of a method of another plugin - nothing is loaded here:
	 * the classes cannot be of one version anymore, and adding the ones of
	 * this copy to the ones of the other is the mix this should prevent. The
	 * autoloader decides then, as it did before.
	 *
	 * @return void
	 */
	private static function load_package_classes(): void {
		// bail if this has been done already.
		if ( self::$package_classes_loaded ) {
			return;
		}
		self::$package_classes_loaded = true;

		// collect the files of the classes, which do not exist yet.
		$files_to_load = array();
		foreach ( self::PACKAGE_FILES as $file ) {
			$class_name = __NAMESPACE__ . '\\' . str_replace( '/', '\\', substr( $file, 0, -4 ) );
			$path       = __DIR__ . '/' . $file;

			// load the class, if it does not exist yet.
			if ( ! class_exists( $class_name, false ) ) {
				$files_to_load[] = $path;
				continue;
			}

			// get the file the class has been loaded from.
			try {
				$loaded_from = ( new ReflectionClass( $class_name ) )->getFileName();
			} catch ( ReflectionException $e ) {
				// bail completely if this cannot be found out: nothing is
				// loaded here then, and the autoloader decides as before.
				return;
			}

			// bail completely if the class has been loaded from another copy.
			if ( realpath( (string) $loaded_from ) !== realpath( $path ) ) {
				return;
			}
		}

		foreach ( $files_to_load as $path ) {
			// load the class. If its file does not exist, the autoloader is asked later.
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Return the method object to use for encryption.
	 *
	 * @return false|Method_Base
	 */
	public function get_method(): false|Method_Base {
		if ( $this->method instanceof Method_Base ) {
			return $this->method;
		}

		// report a slug which is not suited to name the key.
		$this->report_unsuitable_slug();

		// get the place object.
		$place_obj = $this->get_place();

		// a place is only needed to save a key. If none is usable, but the key
		// is there - e.g. defined in a wp-config.php which cannot be written
		// to - or the method needs none, everything still works. Report it
		// all the same, as a new key could not be saved.
		if ( ! $place_obj instanceof Place_Base ) {
			$this->add_error(
				'save_place_not_available',
				'Could not find any place to save the key for encryption.'
			);
		}

		// loop through the objects to check, which one we could use.
		foreach ( $this->get_methods_as_objects() as $obj ) {
			// bail if the method is unusable.
			if ( ! $obj->is_usable() ) {
				continue;
			}

			if ( $place_obj instanceof Place_Base ) {
				// tell the place, which constant this method expects, then let it
				// load whatever it holds into exactly that constant.
				$place_obj->set_constant( $obj->get_constant() );
				$place_obj->load();

				// set the used place.
				$obj->set_place( $place_obj );
			}

			// initiate the method.
			$obj->init();

			// bail if there is no place, and the method cannot do anything without one.
			if ( ! $place_obj instanceof Place_Base && ! $obj->is_usable_without_place() ) {
				continue;
			}

			// set method as our method to use.
			$this->method = $obj;

			return $this->method;
		}

		// return false if no usable method has been found.
		return false;
	}

	/**
	 * Return the keys the places hold, without loading them.
	 *
	 * The active place is simply the first usable one. Which one that is can
	 * change over time - file permissions get fixed, a configuration changes.
	 * A key saved earlier in another place must still be found then, instead
	 * of being replaced by a new one.
	 *
	 * This is only a lookup: nothing is defined, saved or reported here. The
	 * method decides whether one of these keys is usable for it.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $constant The constant the key would be defined as.
	 * @param bool   $include_active_place False to skip the active place, as it has been asked already.
	 *
	 * @return array<int,array{place:Place_Base,key:string}>
	 */
	public function get_stored_keys( string $constant, bool $include_active_place = false ): array {
		// get the name of the active place.
		$active_place      = $this->get_place();
		$active_place_name = $active_place instanceof Place_Base ? $active_place->get_name() : '';

		// collect the keys of the places.
		$keys = array();
		foreach ( $this->get_place_objects( true ) as $place_obj ) {
			// bail if this is the active place, and it should be skipped.
			if ( ! $include_active_place && $place_obj->get_name() === $active_place_name ) {
				continue;
			}

			// ask the place for a key it holds.
			$place_obj->set_constant( $constant );
			$key = $place_obj->get_stored_key();

			// bail if this place does not hold a key.
			if ( '' === $key ) {
				continue;
			}

			$keys[] = array(
				'place' => $place_obj,
				'key'   => $key,
			);
		}

		return $keys;
	}

	/**
	 * Return whether any place holds a key for the given constant, which it
	 * cannot hand over right now.
	 *
	 * No new key may be generated then: it would replace the key all values
	 * have been encrypted with. This is only a lookup: nothing is defined,
	 * saved or reported here.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $constant The constant the key would be defined as.
	 *
	 * @return bool
	 */
	public function is_stored_key_unavailable( string $constant ): bool {
		foreach ( $this->get_place_objects( true ) as $place_obj ) {
			$place_obj->set_constant( $constant );

			// (a place of an older copy of this package cannot tell.)
			if ( method_exists( $place_obj, 'is_key_unavailable' ) && $place_obj->is_key_unavailable() ) { // @phpstan-ignore function.alreadyNarrowedType
				return true;
			}
		}

		return false;
	}

	/**
	 * Return an encrypted string.
	 *
	 * The optional context binds the encrypted value to what it belongs to -
	 * e.g. the name of the field it is saved in. It is not encrypted and not
	 * part of the result, but the value can only be decrypted with the very
	 * same context. An encrypted value copied into another field does not
	 * decrypt there.
	 *
	 * @param string $plain_text String to encrypt.
	 * @param string $context    Optional. What the value belongs to.
	 *
	 * @return string
	 */
	public function encrypt( #[\SensitiveParameter] string $plain_text, string $context = '' ): string {
		// get the active method.
		$method_obj = $this->get_method();

		// bail if the method could not be found.
		if ( false === $method_obj ) {
			// log this error.
			$this->add_error(
				'no_method_available',
				'Could not find any supported method to encrypt strings.'
			);

			// do nothing more.
			return '';
		}

		// encrypt the string with the detected method.
		return '' === $context ? $method_obj->encrypt( $plain_text ) : $method_obj->encrypt_with_context( $plain_text, $context );
	}

	/**
	 * Return the decrypted string.
	 *
	 * @param string $encrypted_text Text to decrypt.
	 * @param string $context        Optional. The context the value has been encrypted with.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_text, string $context = '' ): string {
		// get the active method.
		$method_obj = $this->get_method();

		// bail if the method could not be found.
		if ( false === $method_obj ) {
			// log this error.
			$this->add_error(
				'no_method_available',
				'Could not find any supported method to encrypt strings.'
			);

			// do nothing more.
			return '';
		}

		// decrypt the string with the detected method.
		return '' === $context ? $method_obj->decrypt( $encrypted_text ) : $method_obj->decrypt_with_context( $encrypted_text, $context );
	}

	/**
	 * Return an encrypted string - or a WP_Error, if the value could not be
	 * encrypted.
	 *
	 * The method encrypt() returns an empty string on any error, so a
	 * failure cannot be told apart from a value without content. This method returns the
	 * same string as encrypt() on success, and a WP_Error otherwise. Its
	 * code is "encrypt_failed", its other codes are the errors, which have
	 * been reported during this call - e.g. "key_unavailable" and
	 * "no_key_available". They are collected in get_errors() as well.
	 *
	 * An empty plain text is no error: there is nothing to encrypt.
	 * Warnings, which do not prevent the encryption - e.g.
	 * "insecure_place_database" - are only collected in get_errors().
	 *
	 * @since 4.3.0 Available since 4.3.0.
	 *
	 * @param string $plain_text String to encrypt.
	 * @param string $context    Optional. What the value belongs to.
	 *
	 * @return string|WP_Error
	 */
	public function encrypt_or_error( #[\SensitiveParameter] string $plain_text, string $context = '' ): string|WP_Error {
		// remember which errors have been reported before this call.
		$errors_before = $this->error_count;

		// get the active method first: what its initialization reports is no
		// error of the value, unless the value cannot be encrypted.
		$this->get_method();
		$errors_before_value = $this->error_count;

		// encrypt the value.
		$encrypted = $this->encrypt( $plain_text, $context );

		// return the encrypted value.
		if ( '' !== $encrypted ) {
			return $encrypted;
		}

		// return the empty string, if there was nothing to encrypt.
		if ( '' === $plain_text && $errors_before_value === $this->error_count ) {
			return '';
		}

		// return the errors of this call.
		return $this->get_errors_since( $errors_before, 'encrypt_failed', 'The value could not be encrypted.', $context );
	}

	/**
	 * Return the decrypted string - or a WP_Error, if the value could not be
	 * decrypted.
	 *
	 * The method decrypt() returns an empty string on any error, so a
	 * failure cannot be told apart from a value without content. This method returns the
	 * same string as decrypt() on success, and a WP_Error otherwise. Its
	 * code is "decrypt_failed", its other codes are the errors, which have
	 * been reported during this call - e.g. "sodium_decrypt_error" for a
	 * value, which has been changed or belongs to another context. They are
	 * collected in get_errors() as well.
	 *
	 * An empty string is returned for an empty value, and for a value,
	 * whose content is empty: both are no errors. A method, which reports
	 * none of its errors, cannot be told apart from such a value.
	 *
	 * @since 4.3.0 Available since 4.3.0.
	 *
	 * @param string $encrypted_text Text to decrypt.
	 * @param string $context        Optional. The context the value has been encrypted with.
	 *
	 * @return string|WP_Error
	 */
	public function decrypt_or_error( string $encrypted_text, string $context = '' ): string|WP_Error {
		// bail if there is no value: there is nothing to decrypt.
		if ( '' === $encrypted_text ) {
			return '';
		}

		// remember which errors have been reported before this call.
		$errors_before = $this->error_count;

		// get the active method first: what its initialization reports is no
		// error of the value, unless the value cannot be decrypted.
		$method_obj          = $this->get_method();
		$errors_before_value = $this->error_count;

		// decrypt the value.
		$decrypted = $this->decrypt( $encrypted_text, $context );

		// return the decrypted value - also an empty one, if no error has been
		// reported for it.
		if ( '' !== $decrypted || ( false !== $method_obj && $errors_before_value === $this->error_count ) ) {
			return $decrypted;
		}

		// return the errors of this call.
		return $this->get_errors_since( $errors_before, 'decrypt_failed', 'The value could not be decrypted.', $context );
	}

	/**
	 * Return a WP_Error with the given error, followed by every error, which
	 * has been reported after the given number of errors.
	 *
	 * @param int    $error_count The number of errors reported before.
	 * @param string $code        The code of the error.
	 * @param string $message     The message of the error.
	 * @param string $context     The context of the value.
	 *
	 * @return WP_Error
	 */
	private function get_errors_since( int $error_count, string $code, string $message, string $context ): WP_Error {
		// create the error.
		$error = new WP_Error( $code, $message, array( 'context' => $context ) );

		// add every error reported since then.
		foreach ( $this->error_log as $number => $entry ) {
			if ( $number > $error_count ) {
				$error->add( $entry['code'], $entry['message'], $entry['data'] );
			}
		}

		return $error;
	}

	/**
	 * Return the list of supported methods.
	 *
	 * @return array<int,string>
	 */
	private function get_available_methods(): array {
		$methods = array(
			Methods\OpenSsl::class,
			Methods\Sodium::class,
			Methods\Plain::class,
		);

		$slug = $this->get_slug();
		/**
		 * Filter the available crypt-methods.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $methods List of methods.
		 */
		return apply_filters( $slug . '_crypt_methods', $methods );
	}

	/**
	 * Return the list of available methods as objects.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return array<int,Method_Base>
	 */
	public function get_methods_as_objects(): array {
		return $this->get_method_objects( false );
	}

	/**
	 * Return the list of methods as objects.
	 *
	 * @param bool $ignore_forced_method True to also return the methods a configured "force_method" hides.
	 *
	 * @return array<int,Method_Base>
	 */
	private function get_method_objects( bool $ignore_forced_method ): array {
		// bail if this is not a WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			return array();
		}

		// make sure the classes of this package come from its own copy.
		self::load_package_classes();

		// define the list for objects.
		$list = array();

		// get all available methods.
		foreach ( $this->get_available_methods() as $method_class_name ) {
			// bail if it is not callable.
			if ( ! class_exists( $method_class_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $method_class_name( $this );

			// bail if the object could not be loaded.
			if ( ! $obj instanceof Method_Base ) {
				continue;
			}

			// bail if a method is forced and this is not the forced method.
			if ( ! $ignore_forced_method && ! empty( $this->configuration['force_method'] ) && $obj->get_name() !== $this->configuration['force_method'] ) { // @phpstan-ignore notIdentical.alwaysTrue
				continue;
			}

			// add settings.
			$obj->set_config( $this->get_method_config( $obj->get_name() ) );

			// add the object to the list.
			$list[] = $obj;
		}

		// return the resulting list of objects.
		return $list;
	}

	/**
	 * Remove the keys an uninstallation has kept - as secret and in the
	 * database - for every method.
	 *
	 * An uninstallation keeps the key, so a later installation can read the
	 * values encrypted with it again. If the key has been kept as secret,
	 * and the Secrets API cannot hand it over anymore - e.g. because the
	 * WordPress salts have been changed, while WP_SECRETS_KEY is not used -,
	 * no new key is generated: "key_unavailable" is reported, and nothing is
	 * encrypted. Restoring the former salts or WP_SECRETS_KEY makes the key
	 * readable again.
	 *
	 * If that is not possible, this method allows starting over: the kept
	 * keys are removed, and the next usage generates a new key. This is
	 * reported as "key_missing". Every value encrypted with the former key
	 * stays unreadable. So only call it after the user of your plugin has
	 * confirmed this.
	 *
	 * @since 4.3.0 Available since 4.3.0.
	 *
	 * @return void
	 */
	public function discard_kept_keys(): void {
		foreach ( $this->get_method_objects( true ) as $obj ) {
			// (a method of an older copy of this package keeps its key in the database only.)
			if ( method_exists( $obj, 'discard_kept_key' ) ) { // @phpstan-ignore function.alreadyNarrowedType
				$obj->discard_kept_key();
			}
		}

		// let the next usage look for a key again.
		$this->method = false;
	}

	/**
	 * Run uninstall tasks for crypt.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// load the place first, so the key it holds is available as constant -
		// otherwise the methods below cannot tell an existing key from none.
		$place_obj = $this->get_place();
		if ( $place_obj instanceof Place_Base ) {
			$place_obj->load();
		}

		// get the methods, and tell them which place is in use. All of them:
		// the places are cleaned up completely, so the key of every method
		// has to be put aside - not only of one that is forced right now.
		$methods = $this->get_method_objects( true );
		if ( $place_obj instanceof Place_Base ) {
			foreach ( $methods as $obj ) {
				$obj->set_place( $place_obj );
			}
		}

		// let every method put its key aside first. The places are shared by
		// the methods: the first one cleaning them up would otherwise remove
		// the keys of the others with it.
		foreach ( $methods as $obj ) {
			$obj->keep_key_for_uninstall();
		}

		// check the methods for their uninstallation tasks.
		foreach ( $methods as $obj ) {
			$obj->uninstall();
		}
	}

	/**
	 * Return the slug to use.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Set the slug to use.
	 *
	 * @param string $slug The slug to use.
	 * @return void
	 */
	public function set_slug( string $slug ): void {
		$this->slug              = $slug;
		$this->slug_derived_from = '';
	}

	/**
	 * Return the prefix to use.
	 *
	 * @return string
	 */
	private function get_plugin_file(): string {
		return $this->plugin_file;
	}

	/**
	 * Set the plugin file.
	 *
	 * @param string $plugin_file The absolute path to the plugin file.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 * @noinspection PhpUnused
	 */
	public function set_plugin_file( string $plugin_file ): void {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Return the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['Name'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['Name'];
	}

	/**
	 * Return the plugin author name.
	 *
	 * @return string
	 */
	public function get_plugin_author(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['AuthorName'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['AuthorName'];
	}

	/**
	 * Return the plugin author URL.
	 *
	 * @return string
	 */
	public function get_plugin_author_url(): string {
		// get the plugin data.
		$plugin_data = get_plugin_data( $this->get_plugin_file() );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['AuthorURI'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['AuthorURI'];
	}

	/**
	 * Return the configuration for a specific method by its name.
	 *
	 * @param string $method_name The method name.
	 * @return array<string,mixed>
	 */
	public function get_method_config( string $method_name ): array {
		// bail if no configuration for this name is set.
		if ( ! isset( $this->configuration[ $method_name ] ) ) {
			return array();
		}

		// bail if the configuration is not an array.
		if ( ! is_array( $this->configuration[ $method_name ] ) ) {
			return array();
		}

		// return the configuration.
		return $this->configuration[ $method_name ];
	}

	/**
	 * Return the configuration.
	 *
	 * @return array<string,array<string,mixed>|string>
	 */
	public function get_config(): array {
		return $this->configuration;
	}

	/**
	 * Set the custom configuration for this object.
	 *
	 * @param array<string,array<string,mixed>|string> $configurations List of configurations.
	 * @return void
	 */
	public function set_config( array $configurations ): void {
		$this->configuration = $configurations;
	}

	/**
	 * Return the list of possible places where the hash could be saved.
	 *
	 * @return array<int,string>
	 */
	private function get_places(): array {
		$places = array(
			'CryptForWordPress\Places\WpConfig',
			'CryptForWordPress\Places\MuPlugin',
			'CryptForWordPress\Places\SecretsApi',
			'CryptForWordPress\Places\Database',
			'CryptForWordPress\Places\CustomFile',
			'CryptForWordPress\Places\EnvironmentVariable',
			'CryptForWordPress\Places\ServerVariable',
			'CryptForWordPress\Places\WordPressSalts',
		);

		// prefer the Secrets API of WordPress over the Must-Use plugin, if it
		// does not depend on the WordPress salts.
		if ( class_exists( 'CryptForWordPress\Places\SecretsApi' ) && Places\SecretsApi::has_dedicated_key_source() ) {
			$places = array(
				'CryptForWordPress\Places\WpConfig',
				'CryptForWordPress\Places\SecretsApi',
				'CryptForWordPress\Places\MuPlugin',
				'CryptForWordPress\Places\Database',
				'CryptForWordPress\Places\CustomFile',
				'CryptForWordPress\Places\EnvironmentVariable',
				'CryptForWordPress\Places\ServerVariable',
				'CryptForWordPress\Places\WordPressSalts',
			);
		}

		$slug = $this->get_slug();
		/**
		 * Filter the available places.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $places List of methods.
		 */
		return apply_filters( $slug . '_crypt_places', $places );
	}

	/**
	 * Return the list of available places as objects.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return array<int,Place_Base>
	 */
	public function get_places_as_objects(): array {
		return $this->get_place_objects( false );
	}

	/**
	 * Return the list of places as objects.
	 *
	 * @param bool $ignore_forced_place True to also return the places a configured "force_place" hides.
	 *
	 * @return array<int,Place_Base>
	 */
	private function get_place_objects( bool $ignore_forced_place ): array {
		// bail if this is not a WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			return array();
		}

		// make sure the classes of this package come from its own copy.
		self::load_package_classes();

		// define the list for objects.
		$list = array();

		// get all available methods.
		foreach ( $this->get_places() as $method_class_name ) {
			// bail if it is not callable.
			if ( ! class_exists( $method_class_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $method_class_name( $this );

			// bail if the object could not be loaded.
			if ( ! $obj instanceof Place_Base ) {
				continue;
			}

			// bail if a method is forced and this is not the forced method.
			if ( ! $ignore_forced_place && ! empty( $this->configuration['force_place'] ) && $obj->get_name() !== $this->configuration['force_place'] ) { // @phpstan-ignore notIdentical.alwaysTrue
				continue;
			}

			// set the configuration.
			$obj->set_config( $this->get_config() );

			// add the object to the list.
			$list[] = $obj;
		}

		// return the resulting list of objects.
		return $list;
	}

	/**
	 * Return the place with the given name, also if a configured
	 * "force_place" hides it - or false, if there is none.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $name The name of the place.
	 *
	 * @return false|Place_Base
	 */
	public function get_place_by_name( string $name ): false|Place_Base {
		foreach ( $this->get_place_objects( true ) as $place_obj ) {
			if ( $name === $place_obj->get_name() ) {
				return $place_obj;
			}
		}

		return false;
	}

	/**
	 * Return the place, where the token should be saved.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @return false|Place_Base
	 */
	public function get_place(): false|Place_Base {
		// loop through the objects to check, which one we could use.
		foreach ( $this->get_places_as_objects() as $obj ) {
			// bail if the method is unusable.
			if ( ! $obj->is_usable() ) {
				continue;
			}

			// return this place object.
			return $obj;
		}

		// return false if no usable method has been found.
		return false;
	}

	/**
	 * Save the given hash in the constant on the configured place.
	 *
	 * The places are tried in their order, beginning with the active one.
	 * Whether a place is usable is only an estimate - the write itself can
	 * still fail, e.g. because the disk is full, "open_basedir" forbids it
	 * or the filesystem is accessed via FTP. The hash is then saved in the
	 * next usable place, as if the failed one had not been usable at all.
	 * A write is only trusted once the place confirms it holds the hash.
	 *
	 * Places, which cannot save anything (environment or server variable,
	 * WordPress salts), never confirm a write, so they are never used here.
	 * A configured "force_place" leaves only that one place to try.
	 *
	 * A hash saved in another place than the active one is found there with
	 * the next request, as every place a hash can be saved in is either
	 * loaded by WordPress itself (wp-config.php, "must-use"-plugin) or is
	 * searched for a key (see get_stored_keys()).
	 *
	 * @param string $constant The constant to use.
	 * @param string $hash The hash to use.
	 * @return void
	 */
	public function save_in_place( string $constant, #[\SensitiveParameter] string $hash ): void {
		// forget where a former hash for this constant has been saved.
		unset( $this->saved_places[ $constant ] );

		// try the usable places in their order.
		$failed_places = array();
		foreach ( $this->get_places_as_objects() as $place_obj ) {
			// bail if the place is unusable.
			if ( ! $place_obj->is_usable() ) {
				continue;
			}

			// Set configuration.
			$place_obj->set_constant( $constant );

			// save the hash in the place.
			$place_obj->save( $hash );

			// bail if the place does not hold the hash: try the next one.
			if ( ! $place_obj->is_saved( $hash ) ) {
				$failed_places[] = $place_obj->get_name();
				continue;
			}

			// remember the place holding the hash.
			$this->saved_places[ $constant ] = $place_obj;

			// report that the hash is not where it should be.
			if ( ! empty( $failed_places ) ) {
				$this->add_error(
					'key_saved_in_fallback_place',
					'The key for encryption could not be saved in the active place. It has been saved in the next usable place instead.',
					array(
						'failed_places' => $failed_places,
						'saved_in'      => $place_obj->get_name(),
					)
				);

				// the database holds the encrypted values as well: never let this pass silently.
				if ( 'database' === $place_obj->get_name() ) {
					$this->add_error(
						'insecure_place_database',
						'Keys and encrypted data are stored in the same data source: your projects database. This is insecure and must be addressed.'
					);
				}
			}

			// do nothing more.
			return;
		}

		// bail if a place has been tried: the method reports the failed save.
		if ( ! empty( $failed_places ) ) {
			return;
		}

		// log this error.
		$this->add_error(
			'save_place_not_available',
			'Could not find any place to save the key for encryption.'
		);
	}

	/**
	 * Return whether the place, the given hash has been saved in, holds it
	 * in the constant.
	 *
	 * Used right after save_in_place(): write is not trusted until the
	 * place confirms it. That is the place save_in_place() has used - which
	 * may be another one than the active place, if saving there failed.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $constant The constant to use.
	 * @param string $hash The hash to check.
	 * @return bool
	 */
	public function is_saved_in_place( string $constant, #[\SensitiveParameter] string $hash ): bool {
		// get the place the hash has been saved in, or the active one.
		$place_obj = $this->get_saved_place( $constant );
		if ( ! $place_obj instanceof Place_Base ) {
			$place_obj = $this->get_place();
		}

		// bail if no place could be loaded.
		if ( ! $place_obj instanceof Place_Base ) {
			return false;
		}

		// Set configuration.
		$place_obj->set_constant( $constant );

		// ask the place.
		return $place_obj->is_saved( $hash );
	}

	/**
	 * Return the place save_in_place() has saved the hash for the given
	 * constant in during this request - or false, if it has saved none.
	 *
	 * @internal Used for internal tasks.
	 *
	 * @param string $constant The constant to use.
	 * @return false|Place_Base
	 */
	public function get_saved_place( string $constant ): false|Place_Base {
		return $this->saved_places[ $constant ] ?? false;
	}

	/**
	 * Return the used settings for debug purposes.
	 *
	 * @return array<string,array<string,mixed>|string>
	 */
	public function debug(): array {
		// get the place.
		$place      = $this->get_place();
		$place_name = '';
		if ( $place instanceof Place_Base ) {
			$place_name = $place->get_name();
		}

		// get the method.
		$method      = $this->get_method();
		$method_name = '';
		if ( $method instanceof Method_Base ) {
			$method_name = $method->get_name();
		}

		// return the settings.
		return array(
			'configuration' => $this->get_config(),
			'place'         => $place_name,
			'method'        => $method_name,
		);
	}

	/**
	 * Add an error to the list.
	 *
	 * @param string              $code The error code.
	 * @param string              $message The error message.
	 * @param array<string,mixed> $data The error data.
	 *
	 * @return void
	 */
	public function add_error( string $code, string $message, array $data = array() ): void {
		// create a new error object, if not already set.
		if ( null === $this->errors ) {
			$this->errors = new WP_Error();
		}

		// add the error to the list.
		$this->errors->add(
			$code,
			$message,
			$data
		);

		// remember it with its number, for encrypt_or_error() and decrypt_or_error().
		++$this->error_count;
		$this->error_log[ $this->error_count ] = array(
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);

		$slug = $this->get_slug();
		/**
		 * Run tasks if an error is added to the list.
		 *
		 * @since 2.0.0 Available since 2.0.0.
		 *
		 * @param string $code The error code.
		 * @param string $message The message.
		 * @param array $data Data for the error.
		 */
		do_action( $slug . '_crypt_error', $code, $message, $data );
	}

	/**
	 * Return errors.
	 *
	 * @return WP_Error|null
	 */
	public function get_errors(): ?WP_Error {
		return $this->errors;
	}

	/**
	 * Return whether errors have been occurred.
	 *
	 * @return bool
	 */
	public function has_errors(): bool {
		return $this->errors instanceof WP_Error
			&& $this->errors->has_errors();
	}

	/**
	 * Reset the list of errors.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 * @noinspection PhpUnused
	 */
	public function clear_errors(): void {
		$this->errors    = null;
		$this->error_log = array();
	}
}
