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

	/**
	 * Paths (or the beginning of paths), which cannot be written to.
	 *
	 * Unlike $read_only_paths, this does not change what is_writable()
	 * reports: the write itself fails, as on a full disk or after a change
	 * of the permissions in the meantime.
	 *
	 * @var array<int,string>
	 */
	public static array $failing_writes = array();

	/**
	 * Write the given contents to a file - unless it is one of $failing_writes.
	 *
	 * @param string    $file     The path of the file.
	 * @param string    $contents The contents.
	 * @param int|false $mode     The permissions.
	 * @return bool
	 */
	public function put_contents( $file, $contents, $mode = false ) {
		foreach ( self::$failing_writes as $failing_path ) {
			if ( str_starts_with( (string) $file, $failing_path ) ) {
				return false;
			}
		}

		return parent::put_contents( $file, $contents, $mode );
	}
}
