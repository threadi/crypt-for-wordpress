<?php
/**
 * File to handle the wp-config.php as place to save the key.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Places;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Helper;
use CryptForWordPress\Place_Base;
use WP_Filesystem_Base;

/**
 * Object to handle the wp-config.php as place to save the key.
 */
class WpConfig extends Place_Base {

	/**
	 * Name of the place.
	 *
	 * @var string
	 */
	protected string $name = 'wpconfig';

	/**
	 * Constructor for this object.
	 *
	 * @param Crypt $crypt_obj The crypt object.
	 */
	public function __construct( Crypt $crypt_obj ) {
		$this->crypt_obj = $crypt_obj;
	}

	/**
	 * Return whether this place could be used.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return Helper::is_writable( $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() ) );
	}

	/**
	 * Save the hash in this place.
	 *
	 * The read-modify-write cycle is wrapped in a file lock, and the
	 * actual write happens atomically via a temp-file + rename, so that
	 * - two processes writing at the same time cannot corrupt the file or lose
	 *   each others changes, and
	 * - any other process reading/requiring wp-config.php never sees a truncated
	 *   or half-written file.
	 *
	 * @param string $hash The hash to save.
	 *
	 * @return void
	 */
	public function save( string $hash ): void {
		// get the wp-config.php path.
		$wp_config_php_path = $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() );

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// run the read-modify-write-cycle inside a lock to serialize concurrent writers.
		$this->with_lock(
			$wp_config_php_path,
			function () use ( $wp_filesystem, $wp_config_php_path, $hash ) {
				// get the contents of the wp-config.php.
				$wp_config_php_content = $wp_filesystem->get_contents( $wp_config_php_path );

				// bail if the file has no contents.
				if ( ! $wp_config_php_content ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'wpconfig_php_no_content',
						'The wp-config.php file is empty.'
					);

					// do nothing more.
					return;
				}

				// remove previous value.
				$placeholder           = '## ' . strtoupper( $this->get_crypt_obj()->get_plugin_name() ) . ' placeholder ##';
				$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . preg_quote( $this->get_constant(), '@' ) . '["\'].*$@miU', $placeholder, $wp_config_php_content );
				$wp_config_php_content = preg_replace( '@\n' . preg_quote( $placeholder, '@' ) . '@', '', (string) $wp_config_php_content );

				// add the constant.
				$define = "define( '" . $this->get_constant() . "', '" . addslashes( $hash ) . "' ); // Added by " . $this->get_crypt_obj()->get_plugin_name() . ".\r\n";

				// insert right before the (non-localized) ABSPATH-check that follows the
				// translatable "stop editing" comment - this works regardless of the
				// installations language, since the comment text itself is translated
				// but this code block never is.
				$abspath_pattern = '@^[\t ]*if\s*\(\s*!\s*defined\s*\(\s*["\']ABSPATH["\']\s*\)\s*\)\s*\{@miU';

				if ( preg_match( $abspath_pattern, (string) $wp_config_php_content ) ) {
					$wp_config_php_content = preg_replace_callback(
						$abspath_pattern,
						static function ( array $matches ) use ( $define ) {
							return $define . $matches[0];
						},
						(string) $wp_config_php_content,
						1
					);
				} else {
					// fallback for very old wp-config.php files that do not contain that block yet.
					$wp_config_php_content = preg_replace( '@<\?php\s*@i', "<?php\n$define", (string) $wp_config_php_content, 1 );
				}

				// bail if resulting value is not a string.
				if ( ! is_string( $wp_config_php_content ) ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'wpconfig_php_not_string',
						'The updated content for wp-config.php is not a string.'
					);

					// do nothing more.
					return;
				}

				// save the changed wp-config.php atomically.
				$this->atomic_put_contents( $wp_filesystem, $wp_config_php_path, $wp_config_php_content );
			}
		);
	}


	/**
	 * Run the given callback while holding an exclusive lock for the given target file.
	 *
	 * Uses a dedicated lock-file next to the target (not the target itself, so we never
	 * interfere with the atomic rename in atomic_put_contents()) and a native flock(),
	 * since the "WP_Filesystem" abstraction (e.g., for FTP) does not support locking.
	 * The lock file itself always lives on local disk (ABSPATH is always local, even if
	 * WP_Filesystem uses FTP/SSH2 for the actual transfer), so flock() works reliably here.
	 *
	 * @param string   $target_path The file the callback will modify.
	 * @param callable $callback The code to run while the lock is held.
	 * @return void
	 */
	private function with_lock( string $target_path, callable $callback ): void {
		// define the lock path.
		$lock_path = $target_path . '.lock';

		// open (or create) the lock file directly, bypassing "WP_Filesystem" on purpose.
		$lock_fp = fopen( $lock_path, 'c' );

		// if we could not even open a lock file, run the callback unprotected rather than failing completely.
		if ( ! $lock_fp ) {
			$callback();
			return;
		}

		// block until we get the exclusive lock, then run the callback, then always release it.
		if ( flock( $lock_fp, LOCK_EX ) ) {
			try {
				$callback();
			} finally {
				flock( $lock_fp, LOCK_UN );
			}
		} else {
			// could not get the lock - run unprotected as last resort.
			$callback();
		}

		// close the file lock.
		fclose( $lock_fp );
	}

	/**
	 * Write the given content to the given path atomically.
	 *
	 * Writes to a temporary file first and then moves (renames) it onto the target path.
	 * A rename on the same filesystem is atomic, so any concurrent reader either sees the
	 * complete old content, or the complete new content - never a truncated/partial file.
	 *
	 * @param WP_Filesystem_Base $wp_filesystem The WP_Filesystem-handler to use.
	 * @param string             $path The target path to write to.
	 * @param string             $content The content to write.
	 *
	 * @return void
	 */
	private function atomic_put_contents( WP_Filesystem_Base $wp_filesystem, string $path, string $content ): void {
		// build a unique temp-file-path next to the target, so move() stays on the same filesystem.
		$tmp_path = $path . '.tmp-' . wp_generate_password( 12, false );

		// write the new content to the temp file first.
		if ( ! $wp_filesystem->put_contents( $tmp_path, $content ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'wpconfig_php_could_not_write',
				'The updated content for wp-config.php could not be written.'
			);

			// do nothing more.
			return;
		}

		// atomically move the temp file onto the target, overwriting it.
		if ( ! $wp_filesystem->move( $tmp_path, $path, true ) ) {
			// clean up the temp file if the move failed.
			$wp_filesystem->delete( $tmp_path );

			// log this error.
			$this->get_crypt_obj()->add_error(
				'wpconfig_php_could_not_move',
				'The updated content for wp-config.php could not be moved to the target. Possible write permission error.'
			);

			// do nothing more.
			return;
		}

		// get the configuration.
		$config = $this->get_crypt_obj()->get_config();

		// set the file permissions, if set.
		if ( ! empty( $config['file_permissions'] ) && ! $wp_filesystem->chmod( $path, (int) $config['file_permissions'] ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'wpconfig_php_could_set_permissions',
				'Could not set file permissions. Possible write permission error.'
			);

			// do nothing more.
			return;
		}

		// invalidate a possible OPCache-entry for the file, so other workers do not keep serving the old version.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
	}

	/**
	 * Return the wp-config.php path.
	 *
	 * @param string $slug The plugin slug for the hook names.
	 * @return string
	 */
	private function get_wp_config_path( string $slug ): string {
		$wp_config_php = 'wp-config';
		/**
		 * Filter to change the filename of the used wp-config.php without its extension .php.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $wp_config_php The filename.
		 */
		$wp_config_php = apply_filters( $slug . '_wp_config_name', $wp_config_php );

		// get the path for wp-config.php.
		$wp_config_php_path = ABSPATH . $wp_config_php . '.php';

		/**
		 * Filter the path for the wp-config.php before we return it.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $wp_config_php_path The path.
		 */
		return apply_filters( $slug . '_wp_config_path', $wp_config_php_path );
	}

	/**
	 * Uninstall this place.
	 *
	 * @param string $constant The constant to use during the uninstallation.
	 * @return void
	 */
	public function uninstall( string $constant ): void {
		// get the wp-config.php path.
		$wp_config_php_path = $this->get_wp_config_path( $this->get_crypt_obj()->get_slug() );

		// bail if wp-config.php is not writable.
		if ( ! Helper::is_writable( $wp_config_php_path ) ) {
			// log this error.
			$this->get_crypt_obj()->add_error(
				'wpconfig_php_could_not_write',
				'The wp-config.php is not writeable. Possible write permission error.'
			);

			// do nothing more.
			return;
		}

		// get WP Filesystem-handler.
		$wp_filesystem = Helper::get_wp_filesystem();

		// run the read-modify-write-cycle inside a lock to serialize concurrent writers.
		$this->with_lock(
			$wp_config_php_path,
			function () use ( $wp_filesystem, $wp_config_php_path, $constant ) {
				// get the contents of the wp-config.php.
				$wp_config_php_content = $wp_filesystem->get_contents( $wp_config_php_path );

				// bail if file has no contents.
				if ( ! $wp_config_php_content ) {
					return;
				}

				// remove the value.
				$wp_config_php_content = preg_replace( '@^[\t ]*define\s*\(\s*["\']' . preg_quote( $constant, '@' ) . '["\'].*$@miU', '', $wp_config_php_content );

				if ( ! is_string( $wp_config_php_content ) ) {
					// log this error.
					$this->get_crypt_obj()->add_error(
						'wpconfig_php_not_string',
						'The updated content for wp-config.php is not a string.'
					);

					// do nothing more.
					return;
				}

				// save the changed wp-config.php atomically.
				$this->atomic_put_contents( $wp_filesystem, $wp_config_php_path, $wp_config_php_content );
			}
		);
	}
}
