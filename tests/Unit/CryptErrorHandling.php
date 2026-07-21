<?php
/**
 * Test the error handling API of the Crypt object.
 *
 * Errors are the only channel this package uses to report problems to the
 * plugin embedding it - it never throws out of encrypt()/decrypt(). These
 * tests cover collecting, reading, hooking and resetting them.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the error handling API of the Crypt object.
 */
class CryptErrorHandling extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Run before every test - every test gets its own unique slug so the
	 * "{slug}_error" hook of one test can never fire in another.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'errors-' . uniqid( '', true ) );
	}

	/**
	 * Test that a fresh object reports no errors at all.
	 *
	 * @return void
	 */
	public function test_fresh_object_has_no_errors(): void {
		$this->assertFalse( $this->crypt_obj->has_errors() );
		$this->assertNull( $this->crypt_obj->get_errors() );
	}

	/**
	 * Test that an added error is readable with its code, message and data.
	 *
	 * @return void
	 */
	public function test_added_error_is_readable(): void {
		$this->crypt_obj->add_error(
			'unit_test_error',
			'Something went wrong.',
			array( 'context' => 'unit-test' )
		);

		$this->assertTrue( $this->crypt_obj->has_errors() );

		$errors = $this->crypt_obj->get_errors();
		$this->assertInstanceOf( 'WP_Error', $errors );
		$this->assertContains( 'unit_test_error', $errors->get_error_codes() );
		$this->assertSame( 'Something went wrong.', $errors->get_error_message( 'unit_test_error' ) );
		$this->assertSame( array( 'context' => 'unit-test' ), $errors->get_error_data( 'unit_test_error' ) );
	}

	/**
	 * Test that several errors are collected instead of overwriting each
	 * other, including two errors sharing the same code.
	 *
	 * @return void
	 */
	public function test_errors_are_collected(): void {
		$this->crypt_obj->add_error( 'first_error', 'First.' );
		$this->crypt_obj->add_error( 'second_error', 'Second.' );
		$this->crypt_obj->add_error( 'first_error', 'First again.' );

		$errors = $this->crypt_obj->get_errors();
		$this->assertInstanceOf( 'WP_Error', $errors );
		$this->assertSame( array( 'first_error', 'second_error' ), $errors->get_error_codes() );
		$this->assertCount( 2, $errors->get_error_messages( 'first_error' ) );
	}

	/**
	 * Test that the "{slug}_error" action fires with code, message and data.
	 *
	 * @return void
	 */
	public function test_error_action_is_fired(): void {
		$fired = array();

		add_action(
			$this->crypt_obj->get_slug() . '_crypt_error',
			function ( $code, $message, $data ) use ( &$fired ) {
				$fired[] = array( $code, $message, $data );
			},
			10,
			3
		);

		$this->crypt_obj->add_error( 'hooked_error', 'Hooked message.', array( 'foo' => 'bar' ) );

		$this->assertCount( 1, $fired );
		$this->assertSame( 'hooked_error', $fired[0][0] );
		$this->assertSame( 'Hooked message.', $fired[0][1] );
		$this->assertSame( array( 'foo' => 'bar' ), $fired[0][2] );
	}

	/**
	 * Test that clear_errors() resets the object into its initial state, so
	 * a long-running process can re-check for new errors afterwards.
	 *
	 * @return void
	 */
	public function test_clear_errors_resets_the_state(): void {
		$this->crypt_obj->add_error( 'unit_test_error', 'Something went wrong.' );
		$this->assertTrue( $this->crypt_obj->has_errors() );

		$this->crypt_obj->clear_errors();

		$this->assertFalse( $this->crypt_obj->has_errors() );
		$this->assertNull( $this->crypt_obj->get_errors() );
	}

	/**
	 * Test that encrypting without any usable method returns an empty string
	 * and reports it, instead of returning the plaintext unencrypted.
	 *
	 * @return void
	 */
	public function test_encrypt_without_method_reports_an_error(): void {
		add_filter( $this->crypt_obj->get_slug() . '_crypt_methods', '__return_empty_array' );

		$this->assertSame( '', $this->crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'no_method_available', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that decrypting without any usable method returns an empty string
	 * and reports it.
	 *
	 * @return void
	 */
	public function test_decrypt_without_method_reports_an_error(): void {
		add_filter( $this->crypt_obj->get_slug() . '_crypt_methods', '__return_empty_array' );

		$this->assertSame( '', $this->crypt_obj->decrypt( 'some-encrypted-value' ) );
		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'no_method_available', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that a missing place is reported, and that no method is handed
	 * out in that case - a key which cannot be stored anywhere must not be
	 * used to encrypt anything.
	 *
	 * @return void
	 */
	public function test_missing_place_reports_an_error(): void {
		add_filter( $this->crypt_obj->get_slug() . '_crypt_places', '__return_empty_array' );

		$this->assertFalse( $this->crypt_obj->get_place() );
		$this->assertFalse( $this->crypt_obj->get_method() );

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'save_place_not_available', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that save_in_place() reports a missing place as well, instead of
	 * silently dropping the key.
	 *
	 * @return void
	 */
	public function test_save_in_place_without_place_reports_an_error(): void {
		add_filter( $this->crypt_obj->get_slug() . '_crypt_places', '__return_empty_array' );

		$this->crypt_obj->save_in_place( strtoupper( $this->crypt_obj->get_slug() ) . '-HASH', 'some-hash' );

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'save_place_not_available', $this->crypt_obj->get_errors()->get_error_codes() );
	}
}
