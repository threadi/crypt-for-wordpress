<?php
/**
 * This file contains a helper object.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use WP_Filesystem_Base;
use WP_Filesystem_Direct;

/**
 * Initialize the helper.
 */
class Helper {
	/**
	 * Return whether a given file is writable.
	 *
	 * @param string $file The file with the absolute path.
	 *
	 * @return bool
	 */
	public static function is_writable( string $file ): bool {
		return self::get_wp_filesystem()->is_writable( $file );
	}

	/**
	 * Return the WP Filesystem object.
	 *
	 * @param bool $local Mark with "true" to get the local filesystem object.
	 *
	 * @return WP_Filesystem_Base
	 */
	public static function get_wp_filesystem( bool $local = false ): WP_Filesystem_Base {
		// get WP Filesystem-handler for local files if requested.
		if ( $local ) {
			// embed the local directory object.
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			return new WP_Filesystem_Direct( false );
		}

		// get global WP Filesystem handler.
		require_once ABSPATH . '/wp-admin/includes/file.php';
		\WP_Filesystem();
		global $wp_filesystem;

		// bail if "wp_filesystem" is not of "WP_Filesystem_Base".
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			// embed the local directory object.
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			return new WP_Filesystem_Direct( false );
		}

		// return the local object on any error.
		if ( $wp_filesystem->errors->has_errors() ) {
			// embed the local directory object.
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			return new WP_Filesystem_Direct( false );
		}

		// return the requested filesystem object.
		return $wp_filesystem;
	}

	/**
	 * Make an arbitrary string safe to embed into generated PHP source code
	 * (e.g. inside a "// ..." line comment or a "/** ... *​/" doc comment).
	 *
	 * @param string $value The value to embed (e.g. plugin name, author, slug).
	 *
	 * @return string The sanitized, single-line-safe value.
	 */
	public static function sanitize_for_php_comment( string $value ): string {
		// remove all control characters, including CR/LF, so the value
		// can never end the line the generated comment is written on.
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		// bail if the regex failed for some reason.
		if ( ! is_string( $value ) ) {
			return '';
		}

		// break up any "*/" sequence so a "/** ... */" doc comment cannot be
		// closed prematurely by attacker-/plugin-author-controlled content.
		return str_replace( '*/', '* /', $value );
	}

	/**
	 * Check a given file permission and return the correct octal value.
	 *
	 * @param mixed $file_permission The given file permission.
	 *
	 * @return int
	 */
	public static function get_permission( mixed $file_permission ): int {
		$allowed = array(
			'0600',
			'0640',
			'0644',
			'0660',
			'0664',
		);

		// check if the given value is a string and if it is a valid octal value.
		if ( is_string( $file_permission ) ) {
			$file_permission = sanitize_text_field( $file_permission );

			if ( in_array( $file_permission, $allowed, true ) ) {
				return (int) octdec( $file_permission );
			}
		}

		// check if the given value is an integer and if it is a valid octal value.
		if ( is_int( $file_permission ) ) {
			foreach ( $allowed as $permission ) {
				if ( octdec( $permission ) === $file_permission ) {
					return (int) $file_permission;
				}
			}
		}

		// return the default value.
		return (int) octdec( '0640' );
	}

	/**
	 * Make sure the given option is not loaded with every request.
	 *
	 * An option, which WordPress loads with every request, ends up in the
	 * list of all options - and with it in a persistent object cache like
	 * Redis or Memcached. A key does not belong there.
	 *
	 * The autoload of an existing option is not changed by update_option(),
	 * if its value stays the same. So it is changed here, if it is still
	 * set. Before WordPress 6.4 this is not possible: nothing is done then.
	 *
	 * @param string $option_name The name of the option.
	 *
	 * @return void
	 */
	public static function disable_option_autoload( string $option_name ): void {
		// bail if WordPress cannot change the autoload of an existing option.
		if ( ! function_exists( 'wp_set_option_autoload' ) ) {
			return;
		}

		// bail if the option is not loaded with every request.
		if ( ! array_key_exists( $option_name, wp_load_alloptions() ) ) {
			return;
		}

		// load it only if it is requested.
		wp_set_option_autoload( $option_name, false );
	}
}
