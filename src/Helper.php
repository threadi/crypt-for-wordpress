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
}
