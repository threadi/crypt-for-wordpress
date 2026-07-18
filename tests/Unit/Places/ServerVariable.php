<?php
/**
 * Test the ServerVariable place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the ServerVariable place.
 */
class ServerVariable extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Names set in $_SERVER during a test, to be removed again in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $server_names = array();

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj    = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'server-variable-test-' . uniqid('', true) );
		$this->server_names = array();
	}

	/**
	 * Clean up any $_SERVER entry we set during a test.
	 *
	 * Note: PHP constants defined via define() during a test cannot be
	 * un-defined again - each test therefore uses a fresh, unique variable
	 * name, so leftover constants from a previous test can never collide.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->server_names as $name ) {
			unset( $_SERVER[ $name ] );
		}
		parent::tear_down();
	}

	/**
	 * Return a fresh, unique server variable name for this test.
	 *
	 * @return string
	 */
	private function get_unique_var_name(): string {
		$name                = 'CFWP_TEST_' . strtoupper( uniqid('', true) );
		$this->server_names[] = $name;
		return $name;
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$this->assertSame( 'server_variable', $place->get_name() );
	}

	/**
	 * Test that this place is unusable without a configured variable name.
	 *
	 * @return void
	 */
	public function test_unusable_without_variable_name(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a non-string variable name is rejected instead of causing a TypeError later on.
	 *
	 * @return void
	 */
	public function test_unusable_with_non_string_config(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$place->set_config( array( 'server_variable' => 123 ) );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that this place is unusable if the configured variable is not actually set.
	 *
	 * @return void
	 */
	public function test_unusable_when_variable_not_set(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$place->set_config( array( 'server_variable' => $this->get_unique_var_name() ) );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a set server variable is usable, and that load() defines a
	 * PHP constant of the same name with its (sanitized) value.
	 *
	 * @return void
	 */
	public function test_usable_and_load_defines_constant(): void {
		$var_name             = $this->get_unique_var_name();
		$_SERVER[ $var_name ] = 'unit-test-secret-value';

		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$place->set_config( array( 'server_variable' => $var_name ) );

		$this->assertTrue( $place->is_usable() );
		$this->assertFalse( defined( $var_name ) );

		$place->load();

		$this->assertTrue( defined( $var_name ) );
		$this->assertSame( 'unit-test-secret-value', constant( $var_name ) );
	}

	/**
	 * Test that load() logs an error if no variable name is configured at all.
	 *
	 * @return void
	 */
	public function test_load_logs_error_when_name_missing(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );

		$place->load();

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'server_variable_missing', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that load() logs an error if the configured variable is not set on the server.
	 *
	 * @return void
	 */
	public function test_load_logs_error_when_variable_not_set(): void {
		$place = new \CryptForWordPress\Places\ServerVariable( $this->crypt_obj );
		$place->set_config( array( 'server_variable' => $this->get_unique_var_name() ) );

		$place->load();

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'server_variable_missing_in_server', $this->crypt_obj->get_errors()->get_error_codes() );
	}
}
