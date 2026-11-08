<?php
/**
 * Test the SecretsApi place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the SecretsApi place.
 */
class SecretsApi extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Run before every test - every test gets its own unique slug, so its
	 * secret and its constants do not collide with anything another test
	 * may already have set or defined during this process.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! \CryptForWordPress\Places\SecretsApi::is_api_available() ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}

		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'secrets-api-test-' . str_replace( '.', '', uniqid( '', true ) ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wp_secrets_dropin_loaded'] );
		parent::tear_down();
	}

	/**
	 * Return a place object with the given constant.
	 *
	 * @param string $constant The constant.
	 *
	 * @return \CryptForWordPress\Places\SecretsApi
	 */
	private function get_place( string $constant ): \CryptForWordPress\Places\SecretsApi {
		$place = new \CryptForWordPress\Places\SecretsApi( $this->crypt_obj );
		$place->set_constant( $constant );

		return $place;
	}

	/**
	 * Return the constant the Sodium method uses for the slug of this test.
	 *
	 * @return string
	 */
	private function get_sodium_constant(): string {
		return strtoupper( $this->crypt_obj->get_slug() ) . '-SODIUM-HASH';
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\SecretsApi( $this->crypt_obj );
		$this->assertSame( 'secrets_api', $place->get_name() );
	}

	/**
	 * Test that this place is usable with the Secrets API.
	 *
	 * @return void
	 */
	public function test_usable_with_secrets_api(): void {
		$place = new \CryptForWordPress\Places\SecretsApi( $this->crypt_obj );
		$this->assertTrue( $place->is_usable() );
	}

	/**
	 * Test that 'block_secrets_api' disables this place.
	 *
	 * @return void
	 */
	public function test_block_secrets_api_disables_place(): void {
		$place = $this->get_place( $this->get_sodium_constant() );
		$place->set_config( array( 'block_secrets_api' => true ) );

		$this->assertFalse( $place->is_usable() );

		$place->save( 'unit-test-hash-value' );
		$this->assertSame( '', $place->get_stored_key() );
		$this->assertFalse( $place->is_key_unavailable() );
	}

	/**
	 * Test that the name of the secret is one WordPress accepts.
	 *
	 * @return void
	 */
	public function test_secret_name_is_valid(): void {
		$place = $this->get_place( $this->get_sodium_constant() );
		$name  = $place->get_secret_name();

		$this->assertSame( $this->crypt_obj->get_slug() . '/' . strtolower( $this->get_sodium_constant() ), $name );
		$this->assertTrue( wp_secrets_validate_name( $name ) );
	}

	/**
	 * Test that slugs and constants WordPress does not accept as they are
	 * get a valid, distinct and stable name.
	 *
	 * @return void
	 */
	public function test_secret_name_for_unusual_slugs(): void {
		$names = array();
		foreach ( array( 'crypt.for.wordpress-demo', 'crypt-for-wordpress-demo', 'crypt_for_wordpress_demo', '.', str_repeat( 'very-long-slug-', 20 ), '-starts-with-hyphen' ) as $slug ) {
			$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
			$crypt_obj->set_slug( $slug );

			$place = new \CryptForWordPress\Places\SecretsApi( $crypt_obj );
			$place->set_constant( strtoupper( $slug ) . '-SODIUM-HASH' );
			$name = $place->get_secret_name();

			$this->assertTrue( wp_secrets_validate_name( $name ), $name );
			$this->assertLessThanOrEqual( 172, strlen( $name ) );
			$this->assertSame( $name, $place->get_secret_name() );

			$names[] = $name;
		}

		$this->assertSame( $names, array_unique( $names ) );
	}

	/**
	 * Test that save() stores the hash as secret, and load() defines it
	 * under the constant the method expects.
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$constant = $this->get_sodium_constant();
		$place    = $this->get_place( $constant );

		$place->save( 'unit-test-hash-value' );

		$secret = wp_get_secret( $place->get_secret_name() );
		$this->assertInstanceOf( \WP_Secret::class, $secret );
		$this->assertSame( 'unit-test-hash-value', $secret->reveal() );
		$this->assertFalse( defined( $constant ) );

		$place->load();

		$this->assertTrue( defined( $constant ) );
		$this->assertSame( 'unit-test-hash-value', constant( $constant ) );
		$this->assertFalse( $this->crypt_obj->has_errors() );
	}

	/**
	 * Test that the key is not stored in plain text in the database.
	 *
	 * @return void
	 */
	public function test_key_is_not_stored_in_plain_text(): void {
		global $wpdb;

		$place = $this->get_place( $this->get_sodium_constant() );
		$place->save( 'unit-test-plain-text-marker' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%unit-test-plain-text-marker%' ) );

		$this->assertSame( 0, $count );
	}

	/**
	 * Test that is_saved() only confirms the hash the secret really holds.
	 *
	 * @return void
	 */
	public function test_is_saved_reports_what_the_secret_holds(): void {
		$place = $this->get_place( $this->get_sodium_constant() );

		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );

		$place->save( 'unit-test-hash-value' );

		$this->assertTrue( $place->is_saved( 'unit-test-hash-value' ) );
		$this->assertFalse( $place->is_saved( 'another-hash-value' ) );
		$this->assertFalse( $place->is_saved( '' ) );
	}

	/**
	 * Test that get_stored_key() returns an existing key without defining or
	 * logging anything.
	 *
	 * @return void
	 */
	public function test_get_stored_key_is_a_lookup_without_side_effects(): void {
		$constant = $this->get_sodium_constant();
		$place    = $this->get_place( $constant );

		$this->assertSame( '', $place->get_stored_key() );

		$place->save( 'unit-test-hash-value' );

		$this->assertSame( 'unit-test-hash-value', $place->get_stored_key() );
		$this->assertFalse( defined( $constant ) );
		$this->assertFalse( $this->crypt_obj->has_errors() );
	}

	/**
	 * Test that uninstall() removes the secret.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_secret(): void {
		$constant = $this->get_sodium_constant();
		$place    = $this->get_place( $constant );

		$place->save( 'unit-test-hash-value' );
		$place->uninstall( $constant );

		$this->assertNull( wp_get_secret( $place->get_secret_name() ) );
	}

	/**
	 * Test that the key of this place belongs to a single site.
	 *
	 * @return void
	 */
	public function test_key_is_not_network_wide(): void {
		$this->assertFalse( ( new \CryptForWordPress\Places\SecretsApi( $this->crypt_obj ) )->is_network_wide() );
	}

	/**
	 * Test that this place comes after the Must-Use plugin as long as the
	 * Secrets API depends on the WordPress salts, and before it otherwise.
	 *
	 * @return void
	 */
	public function test_order_of_places(): void {
		$get_names = function (): array {
			return array_map(
				static fn( \CryptForWordPress\Place_Base $place ): string => $place->get_name(),
				$this->crypt_obj->get_places_as_objects()
			);
		};

		// protected by the salts: after the Must-Use plugin, before the database.
		// (the WordPress test suite defines WP_SECRETS_KEY itself, if the salts
		// of the test environment are the placeholder.)
		if ( ! defined( 'WP_SECRETS_KEY' ) ) {
			$this->assertFalse( \CryptForWordPress\Places\SecretsApi::has_dedicated_key_source() );
			$names = $get_names();
			$this->assertSame( array( 'wpconfig', 'muplugin', 'secrets_api', 'database' ), array_slice( $names, 0, 4 ) );
		}

		// protected by WP_SECRETS_KEY: before the Must-Use plugin.
		if ( defined( 'WP_SECRETS_KEY' ) ) {
			$this->assertTrue( \CryptForWordPress\Places\SecretsApi::has_dedicated_key_source() );
			$names = $get_names();
			$this->assertSame( array( 'wpconfig', 'secrets_api', 'muplugin', 'database' ), array_slice( $names, 0, 4 ) );
		}

		// protected by a drop-in: before the Must-Use plugin.
		$GLOBALS['wp_secrets_dropin_loaded'] = true;
		$this->assertTrue( \CryptForWordPress\Places\SecretsApi::has_dedicated_key_source() );
		$names = $get_names();
		$this->assertSame( array( 'wpconfig', 'secrets_api', 'muplugin', 'database' ), array_slice( $names, 0, 4 ) );
	}

	/**
	 * Test the full round trip with the place forced.
	 *
	 * @return void
	 */
	public function test_encrypt_and_decrypt_with_forced_place(): void {
		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'secrets_api',
			)
		);

		$encrypted = $this->crypt_obj->encrypt( 'secret value', 'api_secret' );

		$this->assertNotSame( '', $encrypted, (string) wp_json_encode( $this->crypt_obj->get_errors() ) );
		$this->assertSame( 'secret value', $this->crypt_obj->decrypt( $encrypted, 'api_secret' ) );

		// the key is the one in the Secrets API.
		$place = $this->get_place( $this->get_sodium_constant() );
		$this->assertSame( constant( $this->get_sodium_constant() ), $place->get_stored_key() );

		// a second object of the same plugin decrypts it as well.
		$second = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$second->set_slug( $this->crypt_obj->get_slug() );
		$second->set_config( $this->crypt_obj->get_config() );
		$this->assertSame( 'secret value', $second->decrypt( $encrypted, 'api_secret' ) );
	}

	/**
	 * Test that a key an earlier version saved in the database is still
	 * used, if this place is the active one now.
	 *
	 * @return void
	 */
	public function test_key_in_database_is_still_used(): void {
		$key = base64_encode( random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$database = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$database->set_constant( $this->get_sodium_constant() );
		$database->save( $key );

		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'secrets_api',
			)
		);

		$this->assertNotSame( '', $this->crypt_obj->encrypt( 'secret value' ) );
		$this->assertSame( $key, constant( $this->get_sodium_constant() ) );
		$this->assertContains( 'key_in_inactive_place', $this->crypt_obj->get_errors()->get_error_codes() );
		$this->assertNotContains( 'key_missing', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Store a record WordPress cannot read under the name of the secret.
	 *
	 * @return string The name of the secret.
	 */
	private function store_unreadable_secret(): string {
		$name = $this->get_place( $this->get_sodium_constant() )->get_secret_name();
		update_option( \WP_Secrets_Option_Store::SITE_PREFIX . $name, 'not a secret record', false );

		return $name;
	}

	/**
	 * Test that a secret, which cannot be read, is reported as unavailable,
	 * and is never replaced.
	 *
	 * @return void
	 */
	public function test_unreadable_secret_fails_closed(): void {
		$name  = $this->store_unreadable_secret();
		$place = $this->get_place( $this->get_sodium_constant() );

		$this->assertInstanceOf( \WP_Error::class, wp_get_secret( $name ) );
		$this->assertTrue( $place->is_key_unavailable() );
		$this->assertSame( '', $place->get_stored_key() );

		// load() defines nothing and reports it.
		$place->load();
		$this->assertFalse( defined( $this->get_sodium_constant() ) );
		$this->assertContains( 'secrets_api_unavailable', $this->crypt_obj->get_errors()->get_error_codes() );

		// save() does not replace it.
		$place->save( 'unit-test-hash-value' );
		$this->assertSame( 'not a secret record', get_option( \WP_Secrets_Option_Store::SITE_PREFIX . $name ) );
		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );
	}

	/**
	 * Test that no new key is generated, while the key in the Secrets API
	 * cannot be read - also if another place would accept one.
	 *
	 * @return void
	 */
	public function test_no_new_key_while_secret_is_unavailable(): void {
		$name = $this->store_unreadable_secret();

		// a key has been used on this installation before.
		update_option( $this->crypt_obj->get_slug() . '_crypt_key_id_sodium', 'abcdef0123456789' );

		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'database',
			)
		);

		$this->assertSame( '', $this->crypt_obj->encrypt( 'secret value' ) );

		$codes = $this->crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_unavailable', $codes );
		$this->assertNotContains( 'key_missing', $codes );

		// nothing has been replaced or forgotten.
		$this->assertSame( '', (string) get_option( $this->crypt_obj->get_slug() . '-hash', '' ) );
		$this->assertSame( 'abcdef0123456789', get_option( $this->crypt_obj->get_slug() . '_crypt_key_id_sodium' ) );
		$this->assertSame( 'not a secret record', get_option( \WP_Secrets_Option_Store::SITE_PREFIX . $name ) );
	}

	/**
	 * Test that on a multisite network every site has a secret of its own.
	 *
	 * @return void
	 */
	public function test_multisite_every_site_has_its_own_secret(): void {
		$this->skipWithoutMultisite();

		$other_site = self::factory()->blog->create();
		$constant   = $this->get_sodium_constant();

		$this->get_place( $constant )->save( 'key-of-this-site' );

		switch_to_blog( $other_site );
		$on_other_site = $this->get_place( $constant );
		$before        = $on_other_site->get_stored_key();
		$on_other_site->save( 'key-of-the-other-site' );
		$after = $on_other_site->get_stored_key();
		restore_current_blog();

		$this->assertSame( '', $before );
		$this->assertSame( 'key-of-the-other-site', $after );
		$this->assertSame( 'key-of-this-site', $this->get_place( $constant )->get_stored_key() );
	}

	/**
	 * Test that a key generated on a switched site is saved on that site,
	 * together with its fingerprint - and that nothing of it is left on the
	 * site the request started on.
	 *
	 * @return void
	 */
	public function test_multisite_key_of_a_switched_site_stays_there(): void {
		$this->skipWithoutMultisite();

		$other_site = self::factory()->blog->create();
		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'secrets_api',
			)
		);
		$key_id_option = $this->crypt_obj->get_slug() . '_crypt_key_id_sodium';
		$name          = $this->get_place( $this->get_sodium_constant() )->get_secret_name();

		switch_to_blog( $other_site );
		$encrypted    = $this->crypt_obj->encrypt( 'Hallo World' );
		$secret_there = wp_get_secret( $name );
		$key_id_there = get_option( $key_id_option );
		restore_current_blog();

		$this->assertNotSame( '', $encrypted );
		$this->assertInstanceOf( \WP_Secret::class, $secret_there );
		$this->assertNotEmpty( $key_id_there );

		$this->assertNull( wp_get_secret( $name ) );
		$this->assertFalse( get_option( $key_id_option ) );
	}
}
