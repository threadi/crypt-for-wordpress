<?php
/**
 * Test that an existing key is never replaced silently.
 *
 * Losing the key means losing every value encrypted with it. These tests
 * cover the situations in which the key cannot be found anymore, although
 * this installation had one before - and make sure the package then stops
 * with a clear error instead of generating a new key.
 *
 * A PHP constant cannot be removed again during a process. So every test
 * uses its own unique slug, and "a later request" is simulated by preparing
 * the database the way an earlier request would have left it.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test that an existing key is never replaced silently.
 */
class KeyProtection extends CryptForWordPressTests {

	/**
	 * Path to the temporary, fake wp-config.php of the current test.
	 *
	 * @var string
	 */
	private string $fake_wp_config_path = '';

	/**
	 * A minimal wp-config.php skeleton.
	 *
	 * @var string
	 */
	private const FAKE_WP_CONFIG_CONTENT = <<<'PHP'
<?php
define( 'DB_NAME', 'test_db' );

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP;

	/**
	 * Clean up the temporary "wp-config.php" (and its lock file, if created).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array( $this->fake_wp_config_path, $this->fake_wp_config_path . '.lock' ) as $file ) {
			if ( '' !== $this->fake_wp_config_path && file_exists( $file ) ) {
				unlink( $file );
			}
		}

		parent::tear_down();
	}

	/**
	 * The methods every scenario is tested with.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function methods(): array {
		return array(
			'openssl' => array( 'openssl' ),
			'sodium'  => array( 'sodium' ),
		);
	}

	/**
	 * Build a crypt object with a unique slug.
	 *
	 * @param string              $method The method to force.
	 * @param array<string,mixed> $config Additional configuration.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method, array $config = array() ): \CryptForWordPress\Crypt {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'key-protection-' . $method . '-' . uniqid( '', true ) );
		$crypt_obj->set_config( array_merge( array( 'force_method' => $method ), $config ) );

		return $crypt_obj;
	}

	/**
	 * Return the constant the given method reads its key from.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method The method name.
	 *
	 * @return string
	 */
	private function get_constant( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return strtoupper( $crypt_obj->get_slug() ) . ( 'sodium' === $method ? '-SODIUM-HASH' : '-HASH' );
	}

	/**
	 * Return a valid key in the format the given method stores it in.
	 *
	 * @param string $method The method name.
	 *
	 * @return string
	 */
	private function get_stored_key( string $method ): string {
		if ( 'sodium' === $method ) {
			return sodium_bin2base64( sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL );
		}

		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Return the option holding the fingerprint of the known key.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 * @param string                   $method The method name.
	 *
	 * @return string
	 */
	private function get_key_id_option( \CryptForWordPress\Crypt $crypt_obj, string $method ): string {
		return $crypt_obj->get_slug() . '_crypt_key_id_' . $method;
	}

	/**
	 * Redirect the wp-config.php place of the given crypt object to a
	 * temporary file.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 *
	 * @return void
	 */
	private function use_fake_wp_config( \CryptForWordPress\Crypt $crypt_obj ): void {
		$this->fake_wp_config_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cfwp-wp-config-' . uniqid( '', true ) . '.php';
		file_put_contents( $this->fake_wp_config_path, self::FAKE_WP_CONFIG_CONTENT );

		add_filter(
			$crypt_obj->get_slug() . '_wp_config_path',
			fn() => $this->fake_wp_config_path
		);
	}

	/**
	 * Test that the very first usage generates a key, saves it and remembers
	 * its fingerprint.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_first_usage_generates_and_remembers_a_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		// the key is saved in its place ...
		$this->assertNotEmpty( get_option( $crypt_obj->get_slug() . '-hash' ) );

		// ... and its fingerprint is known, without being the key itself.
		$key_id = get_option( $this->get_key_id_option( $crypt_obj, $method ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $key_id );
		$this->assertStringNotContainsString( $key_id, (string) get_option( $crypt_obj->get_slug() . '-hash' ) );
	}

	/**
	 * Test that an installation updating to this version keeps working: its
	 * existing key is remembered with the first usage.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_existing_key_is_remembered_on_first_usage( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		define( $this->get_constant( $crypt_obj, $method ), $this->get_stored_key( $method ) );
		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertNotEmpty( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test the core of it: the key is gone, but this installation had one.
	 * A new key is generated without asking anybody, so the plugin keeps
	 * working - but not silently: the loss is reported, once.
	 *
	 * This is what happens if wp-config.php is replaced during a migration,
	 * a deployment or a restore.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_missing_key_is_replaced_and_reported( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		// an earlier request has used a key - which is not there anymore.
		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		// the plugin keeps working with a new key ...
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertNotEmpty( get_option( $crypt_obj->get_slug() . '-hash' ) );

		// ... and is told that the former one is gone.
		$this->assertContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );

		// the new key is the known one from now on: it is reported only once.
		$this->assertNotSame( '0123456789abcdef', get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_missing', $second->get_errors()->get_error_codes() );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a key, which is not the key this installation has used so
	 * far, is used - but reported, once. E.g. the database of one
	 * installation combined with the wp-config.php of another one.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_changed_key_is_used_and_reported( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );
		define( $this->get_constant( $crypt_obj, $method ), $this->get_stored_key( $method ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertContains( 'key_changed', $crypt_obj->get_errors()->get_error_codes() );

		// it is the known key from now on: it is reported only once.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a key, which could not be saved, is not used at all.
	 * Otherwise it would only live for this request, and the value
	 * encrypted with it could never be read again.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_that_could_not_be_saved_is_not_used( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\BrokenPlace' );
			}
		);

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$this->assertTrue( $crypt_obj->has_errors() );
		$this->assertContains( 'key_not_saved', $crypt_obj->get_errors()->get_error_codes() );
		$this->assertContains( 'no_key_available', $crypt_obj->get_errors()->get_error_codes() );

		// the lost key is neither kept for this request nor remembered - so
		// the next request can try again.
		$this->assertFalse( defined( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that the key an uninstallation left in the database is only
	 * removed there once the place really holds it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_legacy_option_survives_a_failed_save( string $method ): void {
		$crypt_obj   = $this->get_crypt( $method );
		$option_name = $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' );
		$stored_key  = $this->get_stored_key( $method );

		update_option( $option_name, $stored_key );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\BrokenPlace' );
			}
		);

		// the key is still usable, as the option holds it ...
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		// ... the failed save is reported ...
		$this->assertContains( 'key_not_saved', $crypt_obj->get_errors()->get_error_codes() );

		// ... and the only copy of the key has not been deleted.
		$this->assertSame( $stored_key, get_option( $option_name ) );
	}

	/**
	 * Test that a key saved in the database is still found after
	 * wp-config.php became the active place - instead of generating a
	 * second key there.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_found_in_a_place_that_is_not_active_anymore( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );

		// an earlier request saved the key in the database.
		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		// today the wp-config.php is writable and therefore the active place.
		$place = $crypt_obj->get_place();
		$this->assertInstanceOf( '\CryptForWordPress\Places\WpConfig', $place );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		// the key from the database is used ...
		$this->assertSame( $stored_key, constant( $this->get_constant( $crypt_obj, $method ) ) );
		$this->assertContains( 'key_in_inactive_place', $crypt_obj->get_errors()->get_error_codes() );

		// ... and no second key has been written into the wp-config.php.
		$this->assertSame( self::FAKE_WP_CONFIG_CONTENT, file_get_contents( $this->fake_wp_config_path ) );
	}

	/**
	 * Test that the lookup in other places respects 'block_database': the
	 * key left there is not used, a new one is generated and reported.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_blocked_database_is_not_searched_for_a_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'block_database' => true ) );
		$this->use_fake_wp_config( $crypt_obj );

		$left_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $left_key );

		// the fingerprint of a key in the wp-config.php belongs to the whole
		// network (on a single site this is an ordinary option).
		update_site_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );
		$this->assertNotContains( 'key_in_inactive_place', $crypt_obj->get_errors()->get_error_codes() );

		// the new key is in the wp-config.php, and it is not the one from the database.
		$content = (string) file_get_contents( $this->fake_wp_config_path );
		$this->assertStringContainsString( $this->get_constant( $crypt_obj, $method ), $content );
		$this->assertStringNotContainsString( $left_key, $content );
	}

	/**
	 * Test that nothing is ever encrypted with an empty key. OpenSSL accepts
	 * one without complaint - the result could be decrypted by anybody.
	 *
	 * Older versions did that if no key could be generated, e.g. with an
	 * unknown 'hash_algorithm'. Now the problem is still reported, but a key
	 * is generated with the default settings - so the plugin keeps working,
	 * and its values are protected.
	 *
	 * @return void
	 */
	public function test_openssl_never_encrypts_with_an_empty_key(): void {
		foreach ( array( array( 'hash_algorithm' => 'this-algorithm-does-not-exist' ), array( 'hash_type' => 'this-type-does-not-exist' ) ) as $openssl_config ) {
			$crypt_obj = $this->get_crypt(
				'openssl',
				array(
					'force_place' => 'database',
					'openssl'     => $openssl_config,
				)
			);

			$encrypted = $crypt_obj->encrypt( 'Hallo World' );

			$this->assertNotSame( '', $encrypted );
			$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
			$this->assertContains( 'openssl_hash_algo_unknown', $crypt_obj->get_errors()->get_error_codes() );
			$this->assertNotContains( 'openssl_unprotected_value', $crypt_obj->get_errors()->get_error_codes() );

			// a real key has been saved, and the value is not readable without it.
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) get_option( $crypt_obj->get_slug() . '-hash' ) );

			$parts = explode( ':', (string) base64_decode( $encrypted, true ) );
			$this->assertFalse( openssl_decrypt( (string) base64_decode( $parts[2], true ), 'aes-256-gcm', '', OPENSSL_RAW_DATA, (string) base64_decode( $parts[0], true ), (string) base64_decode( $parts[1], true ) ) );
		}
	}

	/**
	 * Test that a key the place derives itself still wins over a key left
	 * in another place: the lookup in other places is the last resort, it
	 * must not change which key an installation has been using.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_derived_key_wins_over_a_leftover_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'wordpress_salts' ) );

		// what the derived key produces.
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		// a second object of the same plugin, with a key left in the database.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );
		update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( $method ) );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_in_inactive_place', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that the key of another method, left in the database, is not
	 * adopted. Both methods share the option, but not the key format.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_of_another_method_is_not_adopted( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );

		// the database holds a key of the other method.
		update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( 'sodium' === $method ? 'openssl' : 'sodium' ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_in_inactive_place', (array) $crypt_obj->get_errors()?->get_error_codes() );

		// a key of its own has been generated and saved in the active place.
		$this->assertStringContainsString( $this->get_constant( $crypt_obj, $method ), (string) file_get_contents( $this->fake_wp_config_path ) );
	}

	/**
	 * Test that the fingerprint identifies the key, not its encoding: with
	 * a derived key the OpenSSL setting 'hash_type' only changes how the
	 * very same key is written down.
	 *
	 * @return void
	 */
	public function test_fingerprint_does_not_depend_on_the_encoding_of_the_key(): void {
		$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'wordpress_salts' ) );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config(
			array(
				'force_method' => 'openssl',
				'force_place'  => 'wordpress_salts',
				'openssl'      => array( 'hash_type' => 'hash_pbkdf2' ),
			)
		);

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a fingerprint, which cannot be read as one, does not lock a
	 * healthy installation out: it is replaced by the one of the key in use.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_unusable_fingerprint_does_not_lock_out( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		update_option( $this->get_key_id_option( $crypt_obj, $method ), array( 'not', 'a', 'fingerprint' ) );
		define( $this->get_constant( $crypt_obj, $method ), $this->get_stored_key( $method ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that a place, which cannot save anything, never gets a generated
	 * key: it would be gone with the next request.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_place_that_cannot_save_gets_no_generated_key( string $method ): void {
		// the salts place with a salt that does not exist: it has no key of
		// its own, and nothing can be saved in it.
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'force_place' => 'wordpress_salts',
				'salt'        => 'CFWP_TEST_SALT_THAT_IS_NOT_DEFINED',
			)
		);

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_not_saved', $crypt_obj->get_errors()->get_error_codes() );
		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		$this->assertFalse( defined( $this->get_constant( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that the key from an environment or server variable is used,
	 * whatever the variable is called. Its value is a self-chosen key, as
	 * the documentation of these places asks for.
	 *
	 * Older versions only found it if the variable happened to be named
	 * like the constant of the method - otherwise they generated a new key
	 * with every request, which nothing could be decrypted with afterwards.
	 *
	 * @return void
	 */
	public function test_key_from_a_variable_with_any_name_is_used(): void {
		foreach ( array( 'environment_variable', 'server_variable' ) as $place ) {
			$variable = 'CFWP_TEST_' . strtoupper( str_replace( '.', '', uniqid( '', true ) ) );

			if ( 'environment_variable' === $place ) {
				$_ENV[ $variable ] = 'My-own_secret key!2026';
			} else {
				$_SERVER[ $variable ] = 'My-own_secret key!2026';
			}

			$config = array(
				'force_place' => $place,
				$place        => $variable,
			);

			$crypt_obj = $this->get_crypt( 'openssl', $config );
			$encrypted = $crypt_obj->encrypt( 'Hallo World' );

			$this->assertNotSame( '', $encrypted, $place );
			$this->assertNotContains( 'key_not_saved', (array) $crypt_obj->get_errors()?->get_error_codes(), $place );

			// the key is the one from the variable: nothing has been generated.
			$this->assertSame( 'My-own_secret key!2026', constant( $this->get_constant( $crypt_obj, 'openssl' ) ), $place );

			// a second object reads it the same way.
			$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$second->set_slug( $crypt_obj->get_slug() );
			$second->set_config( $crypt_obj->get_config() );

			$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ), $place );

			unset( $_ENV[ $variable ], $_SERVER[ $variable ] );
		}
	}

	/**
	 * Test that everything keeps working if no place is usable anymore, as
	 * long as the key is there. A place is only needed to save a key.
	 *
	 * E.g. the key has been written into the wp-config.php by an earlier
	 * version, and the file - or its directory - is not writable anymore.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_existing_key_is_used_without_a_usable_place( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );

		// no place at all.
		add_filter( $crypt_obj->get_slug() . '_crypt_places', '__return_empty_array' );
		$this->assertFalse( $crypt_obj->get_place() );

		// without a key nothing can be done ...
		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'save_place_not_available', $crypt_obj->get_errors()->get_error_codes() );

		// ... with the key everything works.
		define( $this->get_constant( $crypt_obj, $method ), $this->get_stored_key( $method ) );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$encrypted = $second->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
	}

	/**
	 * Test that a method, which needs no key, works without a usable place
	 * as well - like the method "plain".
	 *
	 * @return void
	 */
	public function test_method_without_a_key_works_without_a_usable_place(): void {
		$crypt_obj = $this->get_crypt( 'plain' );
		add_filter( $crypt_obj->get_slug() . '_crypt_places', '__return_empty_array' );

		$this->assertSame( 'Hallo World', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( 'Hallo World' ) );
	}

	/**
	 * Test that the name of the hash algorithm may be written the way PHP
	 * accepts it: "SHA256" works like "sha256". Values of a cipher without
	 * AEAD, written with such a setting, have to stay readable.
	 *
	 * @return void
	 */
	public function test_hash_algorithm_may_be_written_in_any_case(): void {
		$crypt_obj = $this->get_crypt(
			'openssl',
			array(
				'force_place' => 'database',
				'openssl'     => array(
					'hash_algorithm'   => 'SHA256',
					'cipher_algorithm' => 'AES-256-CBC',
				),
			)
		);

		$stored_key = $this->get_stored_key( 'openssl' );
		define( $this->get_constant( $crypt_obj, 'openssl' ), $stored_key );

		// what the previous version wrote with this setting and this key.
		$master    = (string) hex2bin( $stored_key );
		$enc_key   = hash_hkdf( 'SHA256', $master, 32, 'encryption' );
		$hmac_key  = hash_hkdf( 'SHA256', $master, 32, 'authentication' );
		$iv        = random_bytes( 16 );
		$raw       = (string) openssl_encrypt( 'old value', 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv );
		$old_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( hash_hmac( 'SHA256', $raw, $hmac_key, true ) . $raw ) );

		$this->assertSame( 'old value', $crypt_obj->decrypt( $old_value ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );
	}

	/**
	 * Test that a hash algorithm, which PHP cannot use for the configured
	 * hash type, never raises an error: a key is generated with the default
	 * instead, and the problem is reported.
	 *
	 * @return void
	 */
	public function test_unusable_hash_algorithm_never_raises_an_error(): void {
		foreach ( array( 'CRC32B', 'crc32b', 'XXH64' ) as $hash_algorithm ) {
			if ( ! in_array( strtolower( $hash_algorithm ), hash_algos(), true ) ) {
				continue;
			}

			foreach ( array( 'hash', 'hash_pbkdf2' ) as $hash_type ) {
				foreach ( array( 'AES-256-GCM', 'AES-256-CBC' ) as $cipher ) {
					$crypt_obj = $this->get_crypt(
						'openssl',
						array(
							'force_place' => 'database',
							'openssl'     => array(
								'hash_algorithm'   => $hash_algorithm,
								'hash_type'        => $hash_type,
								'cipher_algorithm' => $cipher,
							),
						)
					);

					$name      = $hash_algorithm . ' / ' . $hash_type . ' / ' . $cipher;
					$encrypted = $crypt_obj->encrypt( 'Hallo World' );

					// without AEAD the algorithm is needed for every value: nothing
					// can be encrypted, but nothing raises an error either.
					if ( 'AES-256-CBC' === $cipher ) {
						$this->assertSame( '', $encrypted, $name );
						$this->assertContains( 'openssl_hash_algo_unknown', $crypt_obj->get_errors()->get_error_codes(), $name );
						continue;
					}

					$this->assertNotSame( '', $encrypted, $name );
					$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ), $name );
				}
			}
		}
	}

	/**
	 * Test that uninstall() puts the keys of all methods aside, also if one
	 * method is forced at that moment: the places are cleaned up completely.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_the_keys_of_all_methods(): void {
		$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'database' ) );

		$key_of_openssl = $this->get_stored_key( 'openssl' );
		$key_of_sodium  = $this->get_stored_key( 'sodium' );

		define( $this->get_constant( $crypt_obj, 'openssl' ), $key_of_openssl );
		define( $this->get_constant( $crypt_obj, 'sodium' ), $key_of_sodium );

		$crypt_obj->uninstall();

		$this->assertSame( $key_of_openssl, get_option( $crypt_obj->get_slug() . '_hash' ) );
		$this->assertSame( $key_of_sodium, get_option( $crypt_obj->get_slug() . '_sodium_hash' ) );
	}

	/**
	 * Test that the key a place derives itself wins over a key an
	 * uninstallation left in the database. Such a place could never take
	 * the left key over, so it would be used forever instead of the key
	 * all values have been encrypted with.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_derived_key_wins_over_the_key_of_an_uninstallation( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'wordpress_salts' ) );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		$option_name = $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' );
		$left_key    = $this->get_stored_key( $method );
		update_option( $option_name, $left_key );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
		$this->assertNotContains( 'key_not_saved', $second->get_errors()->get_error_codes() );

		// the left key is not touched.
		$this->assertSame( $left_key, get_option( $option_name ) );
	}

	/**
	 * Test that uninstall() keeps nothing if the place derives the key
	 * itself - also if it is the first thing that happens after an update,
	 * when no fingerprint is known yet. A leftover key from another place
	 * would be read first by a later installation.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_nothing_next_to_a_derived_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'wordpress_salts' ) );
		update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( $method ) );

		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		$crypt_obj->uninstall();

		$this->assertFalse( get_option( $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' ) ) );
	}

	/**
	 * Test that uninstall() does not keep a key from the constant, which
	 * the method cannot work with: a later installation would be stuck
	 * with it instead of generating a usable one.
	 *
	 * @return void
	 */
	public function test_uninstall_does_not_keep_an_unusable_key(): void {
		$crypt_obj = $this->get_crypt( 'sodium', array( 'force_place' => 'database' ) );

		// a key of OpenSSL in the constant of Sodium.
		define( $this->get_constant( $crypt_obj, 'sodium' ), bin2hex( random_bytes( 32 ) ) );

		$crypt_obj->uninstall();

		$this->assertFalse( get_option( $crypt_obj->get_slug() . '_sodium_hash' ) );
	}

	/**
	 * Test that a constant defined as "0" is no key, as it has never been:
	 * the key of the place is used.
	 *
	 * @return void
	 */
	public function test_constant_zero_is_not_a_key(): void {
		$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'wordpress_salts' ) );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		define( $this->get_constant( $crypt_obj, 'openssl' ), '0' );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
	}

	/**
	 * Test that a second object of the same plugin works with the key the
	 * first one generated during the same request.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_second_object_uses_the_key_generated_in_this_request( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
	}

	/**
	 * Test that OpenSSL encrypts with a real key, if the stored key is one
	 * someone chose himself - as the places for an environment or server
	 * variable ask for. It cannot be decoded like a generated key. Older
	 * versions then continued with the empty result of the failed decoding:
	 * the value could be decrypted by anybody, without any key.
	 *
	 * @return void
	 */
	public function test_openssl_uses_a_self_chosen_key_as_key(): void {
		foreach ( array( 'AES-256-GCM', 'AES-256-CBC' ) as $cipher ) {
			$crypt_obj = $this->get_crypt(
				'openssl',
				array(
					'force_place' => 'database',
					'openssl'     => array( 'cipher_algorithm' => $cipher ),
				)
			);

			define( $this->get_constant( $crypt_obj, 'openssl' ), 'My-own_secret key!2026' );

			$encrypted = $crypt_obj->encrypt( 'Hallo World' );

			$this->assertNotSame( '', $encrypted, $cipher );
			$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ), $cipher );
			$this->assertNotContains( 'openssl_unprotected_value', (array) $crypt_obj->get_errors()?->get_error_codes(), $cipher );

			// it is not readable without the key.
			$parts = explode( ':', (string) base64_decode( $encrypted, true ) );
			if ( 'AES-256-GCM' === $cipher ) {
				$this->assertFalse( openssl_decrypt( (string) base64_decode( $parts[2], true ), 'aes-256-gcm', '', OPENSSL_RAW_DATA, (string) base64_decode( $parts[0], true ), (string) base64_decode( $parts[1], true ) ) );
			}

			// and another self-chosen key does not read it.
			$other = $this->get_crypt(
				'openssl',
				array(
					'force_place' => 'database',
					'openssl'     => array( 'cipher_algorithm' => $cipher ),
				)
			);
			define( $this->get_constant( $other, 'openssl' ), 'Another secret key!2026' );

			$this->assertSame( '', $other->decrypt( $encrypted ), $cipher );
		}
	}

	/**
	 * Test that values, which older versions wrote without a usable key,
	 * stay readable - and are reported as unprotected, so they can be
	 * encrypted again.
	 *
	 * Older versions had no usable key if the stored key could not be
	 * decoded: a self-chosen key from a variable, or a key generated with
	 * the 'hash_type' "hash_pbkdf2" and read with "hash". They used an
	 * empty key then.
	 *
	 * @return void
	 */
	public function test_values_written_without_a_usable_key_stay_readable(): void {
		$undecodable_keys = array(
			'self-chosen key'       => 'My-own_secret key!2026',
			'key of hash_pbkdf2'    => base64_encode( random_bytes( 32 ) ),
		);

		foreach ( $undecodable_keys as $name => $stored_key ) {
			// AEAD: what the older version wrote.
			$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'database' ) );
			define( $this->get_constant( $crypt_obj, 'openssl' ), $stored_key );

			$iv        = random_bytes( 12 );
			$tag       = '';
			$raw       = (string) openssl_encrypt( 'old value', 'aes-256-gcm', '', OPENSSL_RAW_DATA, $iv, $tag );
			$old_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $raw ) );

			$this->assertSame( 'old value', $crypt_obj->decrypt( $old_value ), $name );
			$this->assertContains( 'openssl_unprotected_value', $crypt_obj->get_errors()->get_error_codes(), $name );

			// saved again, it is protected.
			$crypt_obj->clear_errors();
			$new_value = $crypt_obj->encrypt( 'old value' );
			$this->assertSame( 'old value', $crypt_obj->decrypt( $new_value ), $name );
			$this->assertNotContains( 'openssl_unprotected_value', (array) $crypt_obj->get_errors()?->get_error_codes(), $name );

			// without AEAD: what the older version wrote.
			$crypt_obj = $this->get_crypt(
				'openssl',
				array(
					'force_place' => 'database',
					'openssl'     => array( 'cipher_algorithm' => 'AES-256-CBC' ),
				)
			);
			define( $this->get_constant( $crypt_obj, 'openssl' ), $stored_key );

			$iv        = random_bytes( 16 );
			$raw       = (string) openssl_encrypt( 'old value', 'AES-256-CBC', '', OPENSSL_RAW_DATA, $iv );
			$old_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( hash_hmac( 'sha256', $raw, '', true ) . $raw ) );

			$this->assertSame( 'old value', $crypt_obj->decrypt( $old_value ), $name . ' / CBC' );
			$this->assertContains( 'openssl_unprotected_value', $crypt_obj->get_errors()->get_error_codes(), $name . ' / CBC' );
		}
	}

	/**
	 * Test that a value "encrypted" with no key at all is refused, if this
	 * installation has a usable key: no version has ever written such a
	 * value then, so it can only be a forged one.
	 *
	 * @return void
	 */
	public function test_value_without_a_key_is_refused_with_a_usable_key(): void {
		$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'database' ) );
		define( $this->get_constant( $crypt_obj, 'openssl' ), bin2hex( random_bytes( 32 ) ) );

		$iv     = random_bytes( 12 );
		$tag    = '';
		$raw    = (string) openssl_encrypt( 'forged value', 'aes-256-gcm', '', OPENSSL_RAW_DATA, $iv, $tag );
		$forged = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $raw ) );

		$this->assertSame( '', $crypt_obj->decrypt( $forged ) );
	}

	/**
	 * Test that values, which an older version wrote while no key could be
	 * generated (e.g. with an unknown 'hash_algorithm'), stay readable as
	 * well - also if a key is found or generated now.
	 *
	 * @return void
	 */
	public function test_values_written_while_no_key_could_be_generated_stay_readable(): void {
		$iv        = random_bytes( 12 );
		$tag       = '';
		$raw       = (string) openssl_encrypt( 'old value', 'aes-256-gcm', '', OPENSSL_RAW_DATA, $iv, $tag );
		$old_value = base64_encode( base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $raw ) );

		// with a key generated now, with one found in a place that is not the
		// active one, and with one in the constant.
		foreach ( array( 'generated', 'found', 'constant' ) as $state ) {
			$crypt_obj = $this->get_crypt( 'openssl', array( 'openssl' => array( 'hash_algorithm' => 'this-algorithm-does-not-exist' ) ) );
			$this->use_fake_wp_config( $crypt_obj );

			if ( 'found' === $state ) {
				update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( 'openssl' ) );
			}
			if ( 'constant' === $state ) {
				define( $this->get_constant( $crypt_obj, 'openssl' ), $this->get_stored_key( 'openssl' ) );
			}

			$this->assertSame( 'old value', $crypt_obj->decrypt( $old_value ), $state );
			$this->assertContains( 'openssl_unprotected_value', $crypt_obj->get_errors()->get_error_codes(), $state );

			// encrypted again, it is protected.
			$crypt_obj->clear_errors();
			$new_value = $crypt_obj->encrypt( 'old value' );

			$this->assertNotSame( '', $new_value, $state );
			$this->assertSame( 'old value', $crypt_obj->decrypt( $new_value ), $state );
			$this->assertNotContains( 'openssl_unprotected_value', (array) $crypt_obj->get_errors()?->get_error_codes(), $state );
		}
	}

	/**
	 * Test that uninstall() keeps a key from the database place in the
	 * option a later installation reads it from - even if nothing has been
	 * encrypted during this request.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_the_key_of_the_database_place( string $method ): void {
		$crypt_obj  = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		$stored_key = $this->get_stored_key( $method );

		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		$crypt_obj->uninstall();

		$this->assertFalse( get_option( $crypt_obj->get_slug() . '-hash' ) );
		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' ) ) );
	}

	/**
	 * Test that uninstall() forgets the fingerprint if it keeps no key.
	 * Otherwise a later installation would wait forever for a key that has
	 * been removed on purpose.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_without_a_key_forgets_the_fingerprint( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$crypt_obj->uninstall();

		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		// so a later installation starts over.
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
	}

	/**
	 * Test that uninstall() keeps the key it finds, even if it is not the
	 * known one. Whether it may be used is decided when it is used again -
	 * uninstall() must not destroy it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_a_key_that_is_not_the_known_one( string $method ): void {
		$crypt_obj  = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		$stored_key = $this->get_stored_key( $method );

		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );
		define( $this->get_constant( $crypt_obj, $method ), $stored_key );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' ) ) );
		$this->assertSame( '0123456789abcdef', get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that uninstall() also keeps a key which lives in a place that is
	 * not the active one anymore - the places would delete the only copy.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_a_key_from_an_inactive_place( string $method ): void {
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );

		$stored_key = $this->get_stored_key( $method );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' ) ) );
	}

	/**
	 * Test that uninstall() keeps the key for the method it belongs to,
	 * even if it is called without the configuration used at runtime. The
	 * database place has one option for both methods.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_the_key_for_the_method_it_belongs_to(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'key-protection-uninstall-' . uniqid( '', true ) );
		$crypt_obj->set_config( array( 'force_place' => 'database' ) );

		// at runtime Sodium has been forced, uninstall() runs without it.
		$stored_key = $this->get_stored_key( 'sodium' );
		update_option( $crypt_obj->get_slug() . '-hash', $stored_key );

		$crypt_obj->uninstall();

		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . '_sodium_hash' ) );
		$this->assertFalse( get_option( $crypt_obj->get_slug() . '_hash' ) );
	}

	/**
	 * Test that changing the OpenSSL setting 'hash_type' does not stop the
	 * encryption. The stored key is still the key of this installation -
	 * that values encrypted before the change cannot be read with the new
	 * setting is a documented consequence of changing it.
	 *
	 * @return void
	 */
	public function test_changed_hash_type_does_not_lock_out(): void {
		$crypt_obj = $this->get_crypt( 'openssl', array( 'force_place' => 'database' ) );
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config(
			array(
				'force_method' => 'openssl',
				'force_place'  => 'database',
				'openssl'      => array( 'hash_type' => 'hash_pbkdf2' ),
			)
		);

		$encrypted = $second->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that on a multisite network a known key is missed, wherever its
	 * fingerprint has been saved: which place is the active one can change,
	 * and with it where a fingerprint would be saved today.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_known_key_is_missed_in_every_scope( string $method ): void {
		$this->skipWithoutMultisite();

		// remembered for this site (the database was the active place then),
		// today the wp-config.php is the active place.
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );
		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );

		// remembered for the network, today the database is the active place.
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		update_site_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that on a multisite network the key of one site, found in the
	 * database, is never remembered as the key of the network - not even
	 * by a second object, which only finds the constant defined.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_key_of_one_site_is_not_remembered_for_the_network( string $method ): void {
		$this->skipWithoutMultisite();

		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );
		update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( $method ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		// a second object of the same plugin during the same request.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', (array) $second->get_errors()?->get_error_codes() );

		// it is the key of this site only.
		$this->assertNotEmpty( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		$this->assertFalse( get_site_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that uninstall() does not keep a leftover key next to a key the
	 * place derives itself. A later installation would read the kept key
	 * first - and refuse it, as it is not the key in use.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_uninstall_does_not_keep_a_leftover_key( string $method ): void {
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'wordpress_salts' ) );
		update_option( $crypt_obj->get_slug() . '-hash', $this->get_stored_key( $method ) );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		$crypt_obj->uninstall();

		$this->assertFalse( get_option( $crypt_obj->get_slug() . ( 'sodium' === $method ? '_sodium_hash' : '_hash' ) ) );

		// a later installation works with the derived key as before.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( 'Hallo World', $second->decrypt( $encrypted ) );
		$this->assertNotContains( 'key_changed', $second->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a site of a multisite network, which has used a key of its
	 * own, does not change to a key of the network unnoticed: the key of
	 * the network is used, but the change is reported on this site.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_key_of_the_network_does_not_replace_the_key_of_a_site_unnoticed( string $method ): void {
		$this->skipWithoutMultisite();

		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );

		// the network has a key, known to the network.
		define( $this->get_constant( $crypt_obj, $method ), $this->get_stored_key( $method ) );
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$network_key_id = get_site_option( $this->get_key_id_option( $crypt_obj, $method ) );
		$this->assertNotEmpty( $network_key_id );

		// this site has used another key of its own before.
		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );
		$this->use_fake_wp_config( $second );

		$this->assertNotSame( '', $second->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_changed', $second->get_errors()->get_error_codes() );

		// from now on this site follows the key of the network.
		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		$this->assertSame( $network_key_id, get_site_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that on a multisite network the key of one site is not
	 * remembered as the key of another site, while that one is switched to.
	 * The constant holds the key of the site the request started on.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_switched_site_does_not_remember_the_key_of_another_site( string $method ): void {
		$this->skipWithoutMultisite();

		$other_site = self::factory()->blog->create();

		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertNotEmpty( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		switch_to_blog( $other_site );

		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );
		$second->encrypt( 'Hallo World' );

		$key_id_of_other_site = get_option( $this->get_key_id_option( $crypt_obj, $method ) );
		restore_current_blog();

		$this->assertFalse( $key_id_of_other_site );
	}

	/**
	 * Test that uninstall() keeps the known key, even if it is called with
	 * another 'hash_type' than the one used at runtime - a file like
	 * uninstall.php often creates the object without any configuration.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_the_known_key_with_another_configuration(): void {
		$crypt_obj = $this->get_crypt(
			'openssl',
			array(
				'force_place' => 'database',
				'openssl'     => array( 'hash_type' => 'hash_pbkdf2' ),
			)
		);

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$stored_key = get_option( $crypt_obj->get_slug() . '-hash' );
		$this->assertNotEmpty( $stored_key );

		// uninstall with the default configuration, without the constant of
		// this request being available: as in a request of its own.
		$uninstaller = new class( self::get_plugin_path() ) extends \CryptForWordPress\Crypt {
			/**
			 * Return the methods, each reading its key from a constant that
			 * is not defined - like in a new request.
			 *
			 * @return array<int,\CryptForWordPress\Method_Base>
			 */
			public function get_methods_as_objects(): array {
				add_filter( $this->get_slug() . '_crypt_constant', array( $this, 'rename_constant' ) );

				return parent::get_methods_as_objects();
			}

			/**
			 * Return another name for the constant.
			 *
			 * @param string $constant The name of the constant.
			 *
			 * @return string
			 */
			public function rename_constant( string $constant ): string {
				return str_ends_with( $constant, '-NEW-REQUEST' ) ? $constant : $constant . '-NEW-REQUEST';
			}
		};
		$uninstaller->set_slug( $crypt_obj->get_slug() );
		$uninstaller->set_config( array( 'force_place' => 'database' ) );
		$uninstaller->uninstall();

		$this->assertSame( $stored_key, get_option( $crypt_obj->get_slug() . '_hash' ) );
		$this->assertNotEmpty( get_option( $this->get_key_id_option( $crypt_obj, 'openssl' ) ) );
	}

	/**
	 * Test that on a multisite network the key of the site, on which the
	 * constant has been defined, is not remembered as the key of another
	 * one - whichever of them is the site the request started on.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_key_of_a_switched_site_is_not_remembered_for_this_site( string $method ): void {
		$this->skipWithoutMultisite();

		$other_site = self::factory()->blog->create();
		$crypt_obj  = $this->get_crypt( $method, array( 'force_place' => 'database' ) );

		// the first usage of the request happens on another site.
		switch_to_blog( $other_site );
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertNotEmpty( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		restore_current_blog();

		// back on this site, another object finds the constant defined.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );
		$second->encrypt( 'Hallo World' );

		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that on a multisite network the fingerprint lives where the key
	 * lives. A key in the wp-config.php is the key of every site: if it is
	 * gone, this is reported on whichever site notices it first. A key in
	 * the database belongs to one site only.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_fingerprint_lives_where_the_key_lives( string $method ): void {
		$this->skipWithoutMultisite();

		$other_site = self::factory()->blog->create();

		// wp-config.php: one site has used a key, which is gone now.
		$crypt_obj = $this->get_crypt( $method );
		$this->use_fake_wp_config( $crypt_obj );
		update_site_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		switch_to_blog( $other_site );
		$encrypted_on_other_site = $crypt_obj->encrypt( 'Hallo World' );
		$key_id_of_other_site    = get_option( $this->get_key_id_option( $crypt_obj, $method ) );
		restore_current_blog();

		$this->assertNotSame( '', $encrypted_on_other_site );
		$this->assertContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );

		// the new key is the key of the network.
		$this->assertFalse( $key_id_of_other_site );
		$this->assertNotSame( '0123456789abcdef', get_site_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		$this->assertNotEmpty( get_site_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		// database: the fingerprint of one site says nothing about another one.
		$crypt_obj = $this->get_crypt( $method, array( 'force_place' => 'database' ) );
		update_option( $this->get_key_id_option( $crypt_obj, $method ), '0123456789abcdef' );

		switch_to_blog( $other_site );
		$encrypted_on_other_site = $crypt_obj->encrypt( 'Hallo World' );
		restore_current_blog();

		$this->assertNotSame( '', $encrypted_on_other_site );
		$this->assertNotContains( 'key_missing', $crypt_obj->get_errors()->get_error_codes() );
	}

}
