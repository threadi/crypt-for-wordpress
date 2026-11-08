<?php
/**
 * A request of WordPress for the test NextRequest, run in a PHP process of
 * its own with the database of the test environment.
 *
 * Modes:
 * - "write":   generate and save the key, encrypt the value.
 * - "read":    load the key from its place, decrypt the value.
 * - "cleanup": remove everything the requests saved.
 *
 * The arguments are given as JSON in the first argument. The result is
 * printed as JSON in the last line. With "multisite", WordPress is loaded
 * as the main site of the network the WordPress test suite installed.
 *
 * @package crypt-for-wordpress
 */

// phpcs:disable

$args = json_decode( $argv[1], true );

// no requests to the cron of WordPress.
define( 'DISABLE_WP_CRON', true );

// the configuration of the test environment: database, salts, ABSPATH.
require $args['config'];

// like the WordPress test suite: a fixed key for the Secrets API, if the
// salts are the placeholder of wp-config-sample.php.
if ( ! defined( 'WP_SECRETS_KEY' ) && defined( 'LOGGED_IN_KEY' ) && 'put your unique phrase here' === LOGGED_IN_KEY ) {
	define( 'WP_SECRETS_KEY', base64_encode( str_repeat( 'k', 32 ) ) );
}

// a multisite network, as the WordPress test suite sets it up.
if ( ! empty( $args['multisite'] ) ) {
	define( 'MULTISITE', true );
	define( 'SUBDOMAIN_INSTALL', false );
	$base = '/';
}

// a request of a browser.
$_SERVER['HTTP_HOST']       = WP_TESTS_DOMAIN;
$_SERVER['SERVER_NAME']     = WP_TESTS_DOMAIN;
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['PHP_SELF']        = '/index.php';
$PHP_SELF                   = '/index.php';

// the wp-config.php of this test: WordPress loads it before everything else.
if ( 'cleanup' !== $args['mode'] && file_exists( $args['wp_config'] ) ) {
	require $args['wp_config'];
}

// load WordPress - including the "must-use"-plugins.
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once $args['src'] . '/Crypt.php';

$slug          = $args['slug'];
$key_id_option = $slug . '_crypt_key_id_' . $args['method'];

// remove everything the requests saved.
if ( 'cleanup' === $args['mode'] ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( $slug ) . '%', $wpdb->esc_like( '_wp_secret_' . $slug . '/' ) . '%' ) );
	if ( is_multisite() ) {
		// the fingerprints of keys, which are the same for the whole network.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $slug ) . '%' ) );
		wp_cache_flush();
	}
	if ( empty( $args['root_key_existed'] ) ) {
		delete_site_option( '_wp_secrets_root_key' );
	}
	$mu_plugin = WPMU_PLUGIN_DIR . '/' . $slug . '-hash.php';
	if ( file_exists( $mu_plugin ) ) {
		unlink( $mu_plugin );
	}
	echo "\n" . json_encode( array( 'cleaned' => true ) );
	exit;
}

// the Crypt object, as the plugin creates it.
$crypt = new CryptForWordPress\Crypt( $args['plugin_file'] );
$crypt->set_slug( $slug );
$crypt->set_config(
	array(
		'force_method'     => $args['method'],
		'force_place'      => $args['place'],
		'custom_file_path' => $args['custom_file'],
	)
);
add_filter( $slug . '_wp_config_path', static fn() => $args['wp_config'] );

$result = array();

if ( 'write' === $args['mode'] ) {
	$result['root_key_existed'] = false !== get_site_option( '_wp_secrets_root_key' );
	$result['value']            = $crypt->encrypt( $args['plain'], $args['context'] );

	// whether the place really holds the key now.
	$method                   = $crypt->get_method();
	$constant                 = $method->get_constant();
	$result['saved_in_place'] = defined( $constant ) && $crypt->is_saved_in_place( $constant, (string) constant( $constant ) );
}

if ( 'read' === $args['mode'] ) {
	$result['plain'] = $crypt->decrypt( $args['value'], $args['context'] );
}

$key_id           = get_option( $key_id_option, '' );
$result['key_id'] = '' !== $key_id && false !== $key_id ? $key_id : get_site_option( $key_id_option, '' );
$result['codes']     = $crypt->has_errors() ? $crypt->get_errors()->get_error_codes() : array();
$result['multisite'] = is_multisite();

echo "\n" . json_encode( $result );
