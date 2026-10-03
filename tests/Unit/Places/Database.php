<?php
/**
 * Test the Database place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the Database place.
 */
class Database extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Run before every test - every test gets its own unique slug, so its
	 * option name and derived "...-HASH" constant do not collide with
	 * anything another test may already have set/defined during this process.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'database-test-' . uniqid('', true) );
	}

	/**
	 * Clean up any option we wrote during a test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( $this->crypt_obj->get_slug() . '-hash' );
		parent::tear_down();
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$this->assertSame( 'database', $place->get_name() );
	}

	/**
	 * Test that this place is usable by default.
	 *
	 * @return void
	 */
	public function test_usable_by_default(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$this->assertTrue( $place->is_usable() );
	}

	/**
	 * Test that 'block_database' disables this place.
	 *
	 * @return void
	 */
	public function test_block_database_disables_place(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$place->set_config( array( 'block_database' => true ) );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that save() persists the hash in an option, and load() reads it
	 * back and defines it under the constant Method_Base expects.
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$place    = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$constant = strtoupper( $this->crypt_obj->get_slug() ) . '-HASH';
		$hash     = 'unit-test-hash-value';

		$place->set_constant( $constant );
		$place->save( $hash );

		$this->assertSame( $hash, get_option( $this->crypt_obj->get_slug() . '-hash' ) );
		$this->assertFalse( defined( $constant ) );

		$place->load();

		$this->assertTrue( defined( $constant ) );
		$this->assertSame( $hash, constant( $constant ) );
	}

	/**
	 * Test that load() always logs the "insecure" warning, even when there is
	 * nothing saved yet.
	 *
	 * @return void
	 */
	public function test_load_always_logs_insecure_warning(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );

		$this->assertFalse( $this->crypt_obj->has_errors() );

		$place->load();

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'insecure_place_database', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that uninstall() removes the stored option.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_option(): void {
		$place       = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$option_name = $this->crypt_obj->get_slug() . '-hash';

		$place->save( 'unit-test-hash-value' );
		$this->assertSame( 'unit-test-hash-value', get_option( $option_name ) );

		$place->uninstall( strtoupper( $this->crypt_obj->get_slug() ) . '-HASH' );

		$this->assertFalse( get_option( $option_name ) );
	}

	/**
	 * Test that is_saved() only confirms the hash the option really holds.
	 *
	 * @return void
	 */
	public function test_is_saved_reports_what_the_option_holds(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );

		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );

		$place->save( 'unit-test-hash-value' );

		$this->assertTrue( $place->is_saved( 'unit-test-hash-value' ) );
		$this->assertFalse( $place->is_saved( 'another-hash-value' ) );
		$this->assertFalse( $place->is_saved( '' ) );
	}

	/**
	 * Test that get_stored_key() returns an existing key without defining or
	 * logging anything - it is only a lookup for a key an earlier request
	 * saved here.
	 *
	 * @return void
	 */
	public function test_get_stored_key_is_a_lookup_without_side_effects(): void {
		$place    = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$constant = strtoupper( $this->crypt_obj->get_slug() ) . '-HASH';

		$place->set_constant( $constant );

		// nothing saved: nothing to return.
		$this->assertSame( '', $place->get_stored_key() );

		$place->save( 'unit-test-hash-value' );

		$this->assertSame( 'unit-test-hash-value', $place->get_stored_key() );
		$this->assertFalse( defined( $constant ) );
		$this->assertFalse( $this->crypt_obj->has_errors() );
	}

	/**
	 * Test that get_stored_key() respects 'block_database'.
	 *
	 * @return void
	 */
	public function test_get_stored_key_respects_block_database(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );

		$place->save( 'unit-test-hash-value' );
		$place->set_config( array( 'block_database' => true ) );

		$this->assertSame( '', $place->get_stored_key() );
	}

	/**
	 * Test that this place is the only one whose key belongs to a single
	 * site of a multisite network.
	 *
	 * @return void
	 */
	public function test_key_is_not_network_wide(): void {
		$this->assertFalse( ( new \CryptForWordPress\Places\Database( $this->crypt_obj ) )->is_network_wide() );
		$this->assertTrue( ( new \CryptForWordPress\Places\WpConfig( $this->crypt_obj ) )->is_network_wide() );
	}
}
