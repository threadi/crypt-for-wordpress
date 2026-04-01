<?php
/**
 * File to handle the main object for each test class.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests;

use WP_Filesystem_Direct;
use WP_UnitTestCase;

/**
 * Object to handle the preparations for each test class.
 */
abstract class CryptForWordPressTests extends WP_UnitTestCase {
    /**
     * Prepare the test environment for each test class.
     *
     * @return void
     */
    public static function set_up_before_class(): void {
        // prepare to load just one time.
        if ( ! did_action('crypt_for_wordpress_test_preparation_loaded') ) {
            // enable error reporting.
            error_reporting( E_ALL );

            // create a pseudo-plugin where the tests will be based on.
            $content = '
/**
 * Plugin Name:       PHP Unit Tests for Crypt for WordPress
 * Description:       Holds the hash value to use encryption within PHP Unit Tests for Crypt for WordPress.
 * Requires at least: 4.9.24
 * Requires PHP:      8.1
 * Version:           1.0.0
 * Author:            Your name
 * Author URI:        Your URI
 * Text Domain:       php-unit-tests-for-crypt-for-wordpress
 *
 * @package php-unit-tests-for-crypt-for-wordpress
 */';

            if ( ! defined( 'FS_CHMOD_DIR' ) ) {
                define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
            }
            if ( ! defined( 'FS_CHMOD_FILE' ) ) {
                define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
            }

            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

            $wp_filesystem = new WP_Filesystem_Direct( false );
            $wp_filesystem->mkdir( dirname( self::get_plugin_path() ) );
            $wp_filesystem->put_contents( self::get_plugin_path(), $content );

            // mark as loaded.
            do_action('crypt_for_wordpress_test_preparation_loaded');
        }

        parent::set_up_before_class();
    }

    /**
     * Return the path for our pseudo-plugin during tests.
     *
     * @return string
     */
    protected static function get_plugin_path(): string {
        return WP_CORE_DIR . '/wp-content/plugins/php-unit-tests-for-crypt-for-wordpress/php-unit-tests-for-crypt-for-wordpress.php';
    }
}