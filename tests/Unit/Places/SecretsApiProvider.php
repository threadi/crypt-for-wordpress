<?php
/**
 * Test the SecretsApi place with providers WordPress cannot switch to during
 * a request: unavailable, read-only, withholding values, failing writes,
 * unreachable.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;
use CryptForWordPress\Tests\Fixtures\FakeSecretsApi;

/**
 * Object to test the SecretsApi place with different providers.
 */
class SecretsApiProvider extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WP_Secret' ) ) {
			$this->markTestSkipped( 'The Secrets API is not available in this WordPress version.' );
		}

		FakeSecretsApi::reset();

		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'secrets-api-provider-' . str_replace( '.', '', uniqid( '', true ) ) );

		// use the fake instead of the real place.
		add_filter(
			$this->crypt_obj->get_slug() . '_crypt_places',
			static function ( array $places ): array {
				return array_map(
					static fn( string $place ): string => 'CryptForWordPress\Places\SecretsApi' === $place ? FakeSecretsApi::class : $place,
					$places
				);
			}
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		FakeSecretsApi::reset();
		parent::tear_down();
	}

	/**
	 * Return the constant the Sodium method uses.
	 *
	 * @return string
	 */
	private function get_constant(): string {
		return strtoupper( $this->crypt_obj->get_slug() ) . '-SODIUM-HASH';
	}

	/**
	 * Return the fake place, with the constant of the Sodium method.
	 *
	 * @return FakeSecretsApi
	 */
	private function get_place(): FakeSecretsApi {
		$place = new FakeSecretsApi( $this->crypt_obj );
		$place->set_constant( $this->get_constant() );

		return $place;
	}

	/**
	 * Force the Sodium method and the place "secrets_api".
	 *
	 * @return void
	 */
	private function force_secrets_api(): void {
		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'secrets_api',
			)
		);
	}

	/**
	 * Return the error codes reported so far.
	 *
	 * @return array<int,string|int>
	 */
	private function get_codes(): array {
		return $this->crypt_obj->has_errors() ? $this->crypt_obj->get_errors()->get_error_codes() : array();
	}

	/**
	 * Test the full round trip with the fake: the key ends up in its store.
	 *
	 * @return void
	 */
	public function test_round_trip_saves_the_key_as_secret(): void {
		$this->force_secrets_api();

		$encrypted = $this->crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted, (string) wp_json_encode( $this->get_codes() ) );
		$this->assertSame( 'Hallo World', $this->crypt_obj->decrypt( $encrypted ) );

		$name = $this->get_place()->get_secret_name();
		$this->assertSame( constant( $this->get_constant() ), FakeSecretsApi::$secrets[ $name ] );
	}

	/**
	 * Test that nothing happens without the Secrets API (WordPress before 7.2).
	 *
	 * @return void
	 */
	public function test_without_secrets_api_nothing_happens(): void {
		FakeSecretsApi::$available = false;
		$place                     = $this->get_place();

		$this->assertFalse( $place->is_usable() );
		$this->assertFalse( $place->is_key_unavailable() );
		$this->assertSame( '', $place->get_stored_key() );

		$place->save( 'unit-test-hash-value' );
		$place->load();
		$place->uninstall( $this->get_constant() );

		$this->assertSame( array(), FakeSecretsApi::$secrets );
		$this->assertFalse( defined( $this->get_constant() ) );
		$this->assertSame( array(), $this->get_codes() );
	}

	/**
	 * Test that a read-only provider is only usable, if it holds a secret of
	 * this plugin - and that nothing is written to it.
	 *
	 * @return void
	 */
	public function test_read_only_provider(): void {
		FakeSecretsApi::$writable = false;
		$place                    = $this->get_place();

		// nothing of this plugin there: not usable.
		$this->assertFalse( $place->is_usable() );

		// a secret of another plugin does not count.
		FakeSecretsApi::$secrets['another-plugin/key'] = 'unit-test-hash-value';
		$this->assertFalse( $place->is_usable() );

		// the hosting stored the key of this plugin: usable, and loaded.
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		FakeSecretsApi::$secrets[ $place->get_secret_name() ] = $key;
		$this->assertTrue( $place->is_usable() );

		$place->load();
		$this->assertSame( $key, constant( $this->get_constant() ) );

		// nothing is written.
		$place->save( 'another-hash-value' );
		$this->assertSame( $key, FakeSecretsApi::$secrets[ $place->get_secret_name() ] );
		$this->assertContains( 'secrets_api_read_only', $this->get_codes() );

		// and nothing is removed.
		$place->uninstall( $this->get_constant() );
		$this->assertSame( $key, FakeSecretsApi::$secrets[ $place->get_secret_name() ] );
	}

	/**
	 * Test that a read-only provider, which holds the key, is used for the
	 * whole round trip.
	 *
	 * @return void
	 */
	public function test_read_only_provider_with_key_round_trip(): void {
		FakeSecretsApi::$writable = false;
		$key                      = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		FakeSecretsApi::$secrets[ $this->get_place()->get_secret_name() ] = $key;

		$this->force_secrets_api();

		$encrypted = $this->crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $this->crypt_obj->decrypt( $encrypted ) );
		$this->assertSame( $key, constant( $this->get_constant() ) );
	}

	/**
	 * Test that a secret, whose value the provider withholds, is reported and
	 * blocks a new key.
	 *
	 * @return void
	 */
	public function test_withheld_secret(): void {
		$place = $this->get_place();
		FakeSecretsApi::$secrets[ $place->get_secret_name() ] = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		FakeSecretsApi::$withheld = true;

		$this->assertSame( '', $place->get_stored_key() );
		$this->assertTrue( $place->is_key_unavailable() );

		$place->load();
		$this->assertFalse( defined( $this->get_constant() ) );
		$this->assertContains( 'secrets_api_withheld', $this->get_codes() );

		// encrypting generates no new key.
		$this->force_secrets_api();
		$this->assertSame( '', $this->crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_unavailable', $this->get_codes() );
	}

	/**
	 * Test that a failing write is reported, and the key is not used.
	 *
	 * @return void
	 */
	public function test_failing_write(): void {
		FakeSecretsApi::$set_fails = true;
		$place                     = $this->get_place();

		$place->save( 'unit-test-hash-value' );
		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );
		$this->assertContains( 'secrets_api_not_saved', $this->get_codes() );

		// a new key, which could not be saved, is never used.
		$this->force_secrets_api();
		$this->assertSame( '', $this->crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_not_saved', $this->get_codes() );
		$this->assertFalse( defined( $this->get_constant() ) );
	}

	/**
	 * Test that an unreachable provider blocks a new key - in every place.
	 *
	 * @return void
	 */
	public function test_unreachable_provider(): void {
		FakeSecretsApi::$unreachable = true;
		$place                       = $this->get_place();

		$this->assertTrue( $place->is_key_unavailable() );
		$this->assertSame( '', $place->get_stored_key() );

		$place->save( 'unit-test-hash-value' );
		$this->assertSame( array(), FakeSecretsApi::$secrets );
		$this->assertContains( 'secrets_api_unavailable', $this->get_codes() );

		// also with the database as active place.
		$this->crypt_obj->set_config(
			array(
				'force_method' => 'sodium',
				'force_place'  => 'database',
			)
		);
		$this->assertSame( '', $this->crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertContains( 'key_unavailable', $this->get_codes() );
		$this->assertFalse( get_option( $this->crypt_obj->get_slug() . '-hash' ) );
	}
}
