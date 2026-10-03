<?php
/**
 * File to handle place methods as base-object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle place methods as base-object.
 */
class Place_Base {
	/**
	 * Name of the method.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The constant to set.
	 *
	 * @var string
	 */
	private string $constant = '';

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
	 * Return the internal name of this place.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Return whether this place could be used.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return false;
	}

	/**
	 * Save the hash in this place.
	 *
	 * @param string $hash The hash to save.
	 * @return void
	 */
	public function save( #[\SensitiveParameter] string $hash ): void {}

	/**
	 * Return whether this place holds the given hash.
	 *
	 * Used right after save() to make sure a new key really has been written,
	 * before anything is encrypted with it. Places that cannot check this
	 * report true.
	 *
	 * @param string $hash The hash that has been saved.
	 * @return bool
	 */
	public function is_saved( #[\SensitiveParameter] string $hash ): bool {
		return '' !== $hash;
	}

	/**
	 * Return the PHP statement, which defines the constant with the given hash.
	 *
	 * @param string $hash The hash.
	 * @return string
	 */
	protected function get_define_statement( #[\SensitiveParameter] string $hash ): string {
		return "define( '" . $this->get_constant() . "', '" . addslashes( $hash ) . "' );";
	}

	/**
	 * Return the lines of the given file, which define other constants than
	 * the one of this place.
	 *
	 * A file written by this package holds one define() per line. If a
	 * plugin uses two methods, each has a key and a constant of its own -
	 * rewriting the file for one of them must not drop the other one.
	 *
	 * Only complete lines in the format this package writes are kept: the
	 * name and the value in single quotes, closed on the same line. Anything
	 * else someone added to the file by hand - a define() over several
	 * lines, or one inside a comment - is not taken over, as it could not be
	 * taken over safely.
	 *
	 * @param string $path The absolute path of the file.
	 * @return string The lines, each ending with a line break - or an empty string.
	 */
	protected function get_other_define_lines( string $path ): string {
		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if the file does not exist.
		if ( ! $wp_filesystem->exists( $path ) ) {
			return '';
		}

		// get the contents of the file.
		$content = $wp_filesystem->get_contents( $path );

		// bail if the file could not be read.
		if ( ! is_string( $content ) ) {
			return '';
		}

		// ignore everything inside of block comments.
		$content = preg_replace( '@/\*.*?\*/@s', '', $content );

		// bail if the file defines nothing.
		if ( ! is_string( $content ) || ! preg_match_all( '@^[\t ]*define\(\s*\'([^\']+)\'\s*,\s*(\'(?:[^\'\\\\]|\\\\.)*\')\s*\)[\t ]*;[\t ]*(//[^\n]*)?@m', $content, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		// collect the statements of all other constants. Each one is written
		// anew from its name and its value, with its comment - whatever else
		// followed on that line is not taken over.
		$lines = '';
		foreach ( $matches as $match ) {
			if ( $match[1] !== $this->get_constant() ) {
				// (a closing PHP tag would end the file, even inside a comment.)
				$comment = isset( $match[3] ) && ! str_contains( $match[3], '?>' ) ? ' ' . rtrim( $match[3] ) : '';

				$lines .= "define( '" . $match[1] . "', " . $match[2] . ' );' . $comment . "\r\n";
			}
		}

		return $lines;
	}

	/**
	 * Return whether the given file defines the constant with the given hash.
	 *
	 * @param string $path The absolute path of the file.
	 * @param string $hash The hash.
	 * @return bool
	 */
	protected function file_holds_hash( string $path, #[\SensitiveParameter] string $hash ): bool {
		// bail if nothing could have been saved.
		if ( '' === $hash || '' === $this->get_constant() ) {
			return false;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if the file does not exist.
		if ( ! $wp_filesystem->exists( $path ) ) {
			return false;
		}

		// get the contents of the file.
		$content = $wp_filesystem->get_contents( $path );

		// return whether the statement is part of the file.
		return is_string( $content ) && str_contains( $content, $this->get_define_statement( $hash ) );
	}

	/**
	 * Return the hash the given file defines for the constant, or an empty
	 * string if it does not define it.
	 *
	 * The file is read, not executed: this is a lookup without side effects.
	 *
	 * @param string $path The absolute path of the file.
	 * @return string
	 */
	protected function read_hash_from_file( string $path ): string {
		// bail if no constant is set.
		if ( '' === $this->get_constant() ) {
			return '';
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// bail if the file does not exist.
		if ( ! $wp_filesystem->exists( $path ) ) {
			return '';
		}

		// get the contents of the file.
		$content = $wp_filesystem->get_contents( $path );

		// bail if the file defines no value for the constant.
		if ( ! is_string( $content ) || 1 !== preg_match( '@^[\t ]*define\(\s*\'' . preg_quote( $this->get_constant(), '@' ) . '\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)@m', $content, $matches ) ) {
			return '';
		}

		// return the value as save() has been given it.
		return stripslashes( $matches[1] );
	}

	/**
	 * Return the key this place holds for the constant, or an empty string.
	 *
	 * Asked if the active place has no key: the key may still be in a place
	 * which has been the active one before. Places, whose key is loaded by
	 * WordPress itself (wp-config.php, "must-use"-plugin) have nothing to
	 * return here. This is a lookup: it must not define, save or report
	 * anything.
	 *
	 * @return string
	 */
	public function get_stored_key(): string {
		return '';
	}

	/**
	 * Return whether the key of this place is the same for every site of a
	 * multisite network.
	 *
	 * @return bool
	 */
	public function is_network_wide(): bool {
		return true;
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
	 * Return the constant to use.
	 *
	 * @return string
	 */
	protected function get_constant(): string {
		return $this->constant;
	}

	/**
	 * Set the constant.
	 *
	 * @param string $constant The name of the constant.
	 * @return void
	 */
	public function set_constant( string $constant ): void {
		$this->constant = $constant;
	}

	/**
	 * Uninstall this place.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {}

	/**
	 * Load this places environments before the crypt method is used.
	 *
	 * @return void
	 */
	public function load(): void {}

	/**
	 * Return raw key material this place can derive on its own.
	 *
	 * Places, which only store a key that this package generated return an
	 * empty string here - they hand their key over as a constant via load()
	 * instead. Only places, which compute a key themselves (e.g., from the
	 * WordPress salts) implement this, and they return raw bytes: the
	 * encoding is the business of the method that will use it.
	 *
	 * @return string
	 */
	public function get_derived_key(): string {
		return '';
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
}
