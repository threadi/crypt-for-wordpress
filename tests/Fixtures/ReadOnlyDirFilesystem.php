<?php
/**
 * A filesystem handler for tests, which treats chosen paths as not writable.
 *
 * File permissions cannot be used for this: tests often run as a user who
 * may write everywhere.
 *
 * WordPress only knows filesystem handlers named "WP_Filesystem_{method}".
 * So a test registers this class under such a name via class_alias(), and
 * selects it with the "filesystem_method" filter.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Fixtures;

use WP_Filesystem_Direct;

/**
 * Object of a local filesystem with some paths that are not writable.
 */
class ReadOnlyDirFilesystem extends WP_Filesystem_Direct {

	/**
	 * The paths to treat as not writable.
	 *
	 * @var array<int,string>
	 */
	public static array $read_only_paths = array();

	/**
	 * Return whether the given path is writable.
	 *
	 * @param string $path The path.
	 * @return bool
	 */
	public function is_writable( $path ) {
		if ( in_array( $path, self::$read_only_paths, true ) ) {
			return false;
		}

		return parent::is_writable( $path );
	}
}
