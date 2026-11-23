<?php
/**
 * A request of WordPress for the test SodiumCompat, run in a PHP process of
 * its own with the database of the test environment - and without the
 * functions of the PHP extension "sodium": the test disables them with
 * "disable_functions". WordPress loads its sodium_compat then, as it does on
 * a server without the extension.
 *
 * The arguments are given as JSON in the first argument. The result is
 * printed as JSON in the last line. Everything this request saves in the
 * database is removed again before it ends.
 *
 * @package crypt-for-wordpress
 */

// phpcs:disable

$args = json_decode( $argv[1], true );

// no requests to the cron of WordPress.
define( 'DISABLE_WP_CRON', true );

// the configuration of the test environment: database, salts, ABSPATH.
require $args['config'];

// a multisite network, as the WordPress test suite sets it up.
if ( ! empty( $args['multisite'] ) ) {
	define( 'MULTISITE', true );
	define( 'SUBDOMAIN_INSTALL', false );
	$base = '/';
}

// a request of a browser.
$_SERVER['HTTP_HOST']      = WP_TESTS_DOMAIN;
$_SERVER['SERVER_NAME']    = WP_TESTS_DOMAIN;
$_SERVER['SERVER_PORT']    = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['PHP_SELF']       = '/index.php';
$PHP_SELF                  = '/index.php';

// load the sodium_compat of WordPress - exactly as wp-includes/compat.php does
// it, if the functions of the extension are missing. The extension itself is
// still loaded here, only its functions are disabled: sodium_compat would
// hand every call back to them, so it is told to use its own code.
if ( ! function_exists( 'sodium_crypto_box' ) ) {
	require ABSPATH . 'wp-includes/sodium_compat/autoload.php';
	ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
}

// load WordPress.
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once $args['src'] . '/Crypt.php';

// let the Crypt object load the other classes of this package, as it does for a plugin.
( new CryptForWordPress\Crypt( $args['plugin_file'] ) )->get_methods_as_objects();

$slug    = $args['slug'];
$counter = 0;

/**
 * Return a Crypt object and a Sodium method, initialized with the given key.
 *
 * @param string $key          The key, as it is stored.
 * @param array  $config       The configuration of the method.
 * @param array  $crypt_config The configuration of the Crypt object.
 *
 * @return array{0:\CryptForWordPress\Crypt,1:\CryptForWordPress\Methods\Sodium}
 */
function crypt_for_wordpress_compat_method( string $key, array $config = array(), array $crypt_config = array() ): array {
	global $args, $slug, $counter;

	$crypt = new CryptForWordPress\Crypt( $args['plugin_file'] );
	$crypt->set_slug( $slug . '-' . ( ++$counter ) );
	$crypt->set_config( $crypt_config );

	$method = new CryptForWordPress\Methods\Sodium( $crypt );
	$method->set_config( $config );
	define( $method->get_constant(), $key );
	$method->init();

	return array( $crypt, $method );
}

/**
 * Return the error codes of a Crypt object.
 *
 * @param \CryptForWordPress\Crypt $crypt The Crypt object.
 *
 * @return array
 */
function crypt_for_wordpress_compat_codes( CryptForWordPress\Crypt $crypt ): array {
	return $crypt->has_errors() ? $crypt->get_errors()->get_error_codes() : array();
}

$result = array();

// whether the functions come from sodium_compat - and not from the extension.
$result['compat'] = function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && ! ( new ReflectionFunction( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) )->isInternal();

// whether the method is usable.
$result['usable'] = ( new CryptForWordPress\Methods\Sodium( new CryptForWordPress\Crypt( $args['plugin_file'] ) ) )->is_usable();

// the test vectors of Sodium: values written with the extension by earlier versions.
$result['vectors'] = array();
foreach ( require $args['vectors'] as $name => $vector ) {
	if ( 'sodium' !== $vector['method'] ) {
		continue;
	}

	// bail if sodium_compat cannot decrypt this algorithm either.
	$requires = 'aes256gcm' === $vector['requires'] ? 'sodium_crypto_aead_aes256gcm_decrypt' : $vector['requires'];
	if ( '' !== $requires && ! function_exists( $requires ) ) {
		continue;
	}
	if ( 'aes256gcm' === $vector['requires'] && ! sodium_crypto_aead_aes256gcm_is_available() ) {
		continue;
	}

	list( $crypt, $method ) = crypt_for_wordpress_compat_method( $vector['key'], $vector['config'] );

	$result['vectors'][ $name ] = array(
		'ok'    => $vector['plain'] === $method->decrypt_with_context( $vector['value'], $vector['context'] ),
		'codes' => crypt_for_wordpress_compat_codes( $crypt ),
	);
}

// the values the test has written with the extension.
$result['from_extension'] = array();
foreach ( $args['values'] as $value ) {
	list( , $method ) = crypt_for_wordpress_compat_method( $value['key'] );

	$result['from_extension'][] = $method->decrypt_with_context( $value['value'], $value['context'] );
}

// values written with sodium_compat - with every algorithm it supports, in both formats.
$result['written'] = array();
foreach ( array( 'xchacha20poly1305', 'chacha20poly1305', 'aes256gcm', 'aegis256' ) as $algorithm ) {
	foreach ( array( false, true ) as $legacy_format ) {
		$key = sodium_bin2base64( random_bytes( 32 ), SODIUM_BASE64_VARIANT_ORIGINAL );

		list( $crypt, $method ) = crypt_for_wordpress_compat_method( $key, array( 'algorithm' => $algorithm ), $legacy_format ? array( 'legacy_format' => true ) : array() );

		$encrypted = $method->encrypt_with_context( $args['plain'], $args['context'] );

		$result['written'][] = array(
			'algorithm'     => $algorithm,
			'legacy_format' => $legacy_format,
			'key'           => $key,
			'value'         => $encrypted,
			'round_trip'    => $args['plain'] === $method->decrypt_with_context( $encrypted, $args['context'] ),
			'codes'         => crypt_for_wordpress_compat_codes( $crypt ),
		);
	}
}

// a new key of every hash type, used by the Crypt object as a plugin does.
$result['hash_types'] = array();
foreach ( $args['hash_types'] as $hash_type ) {
	$crypt = new CryptForWordPress\Crypt( $args['plugin_file'] );
	$crypt->set_slug( $slug . '-hash-' . ( ++$counter ) );
	$crypt->set_config(
		array(
			'force_method' => 'sodium',
			'force_place'  => 'database',
			'sodium'       => array( 'hash_type' => $hash_type ),
		)
	);

	$encrypted = $crypt->encrypt( $args['plain'], $args['context'] );

	$result['hash_types'][ $hash_type ] = array(
		'method' => false !== $crypt->get_method() ? $crypt->get_method()->get_name() : '',
		'ok'     => '' !== $encrypted && $args['plain'] === $crypt->decrypt( $encrypted, $args['context'] ),
		'codes'  => crypt_for_wordpress_compat_codes( $crypt ),
	);
}

// remove everything this request saved - one by one: a DELETE of a range
// would wait for the locks of the test, which runs in a transaction.
global $wpdb;
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $slug ) . '%' ) ) as $option_name ) {
	delete_option( $option_name );
}
if ( is_multisite() ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $slug ) . '%' ) ) as $meta_key ) {
		delete_site_option( $meta_key );
	}
}
wp_cache_flush();

$result['multisite'] = is_multisite();

echo "\n" . json_encode( $result );
