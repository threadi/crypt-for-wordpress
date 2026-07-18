<?php
/**
 * Test the filesystem-related parts of the Helper object.
 *
 * Helper::get_permission() is a security-relevant allow-list: only a fixed
 * set of file permissions may ever be applied to a generated key file, and
 * anything else must fall back to the restrictive default - never to the
 * value the plugin author passed in.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the filesystem-related parts of the Helper object.
 */
class HelperFilesystem extends CryptForWordPressTests {

	/**
	 * Test that every allowed permission - given as an octal string - is
	 * converted into its integer representation.
	 *
	 * @dataProvider get_allowed_permissions
	 *
	 * @param string $permission The permission as octal string.
	 *
	 * @return void
	 */
	public function test_allowed_string_permission_is_accepted( string $permission ): void {
		$this->assertSame(
			(int) octdec( $permission ),
			\CryptForWordPress\Helper::get_permission( $permission )
		);
	}

	/**
	 * Test that every allowed permission - given as an integer - is returned
	 * unchanged.
	 *
	 * @dataProvider get_allowed_permissions
	 *
	 * @param string $permission The permission as octal string.
	 *
	 * @return void
	 */
	public function test_allowed_int_permission_is_accepted( string $permission ): void {
		$as_int = (int) octdec( $permission );

		$this->assertSame( $as_int, \CryptForWordPress\Helper::get_permission( $as_int ) );
	}

	/**
	 * Return the list of allowed permissions.
	 *
	 * @return array<int,array<int,string>>
	 */
	public function get_allowed_permissions(): array {
		return array(
			array( '0600' ),
			array( '0640' ),
			array( '0644' ),
			array( '0660' ),
			array( '0664' ),
		);
	}

	/**
	 * Test that anything outside the allow-list falls back to the default
	 * 0640 - especially world-readable or world-writable values.
	 *
	 * @dataProvider get_rejected_permissions
	 *
	 * @param mixed $permission The permission to check.
	 *
	 * @return void
	 */
	public function test_rejected_permission_falls_back_to_default( mixed $permission ): void {
		$this->assertSame(
			(int) octdec( '0640' ),
			\CryptForWordPress\Helper::get_permission( $permission )
		);
	}

	/**
	 * Return a list of values which must never be applied to a key file.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function get_rejected_permissions(): array {
		return array(
			'world-writable string' => array( '0777' ),
			'world-readable string' => array( '0666' ),
			'setuid string'         => array( '4755' ),
			'world-writable int'    => array( 0777 ),
			'world-readable int'    => array( 0666 ),
			'decimal misread as is' => array( 777 ),
			'empty string'          => array( '' ),
			'not a number'          => array( 'rw-r-----' ),
			'null'                  => array( null ),
			'boolean'               => array( true ),
			'float'                 => array( 420.0 ),
			'array'                 => array( array( '0640' ) ),
		);
	}

	/**
	 * Test that a permission with surrounding whitespace is still accepted,
	 * as it is sanitized before the allow-list check.
	 *
	 * @return void
	 */
	public function test_permission_is_sanitized_before_the_check(): void {
		$this->assertSame(
			(int) octdec( '0600' ),
			\CryptForWordPress\Helper::get_permission( '  0600  ' )
		);
	}

	/**
	 * Test that the returned value is always usable as a chmod() argument,
	 * i.e. an integer and never a string.
	 *
	 * @return void
	 */
	public function test_permission_is_always_an_int(): void {
		$this->assertIsInt( \CryptForWordPress\Helper::get_permission( '0600' ) );
		$this->assertIsInt( \CryptForWordPress\Helper::get_permission( 'nonsense' ) );
	}

	/**
	 * Test that the local filesystem object is returned on request.
	 *
	 * @return void
	 */
	public function test_get_wp_filesystem_local(): void {
		$filesystem = \CryptForWordPress\Helper::get_wp_filesystem( true );

		$this->assertInstanceOf( 'WP_Filesystem_Base', $filesystem );
		$this->assertInstanceOf( 'WP_Filesystem_Direct', $filesystem );
	}

	/**
	 * Test that the global filesystem object is a usable filesystem object
	 * as well, whatever transport this hosting ends up with.
	 *
	 * @return void
	 */
	public function test_get_wp_filesystem_global(): void {
		$this->assertInstanceOf( 'WP_Filesystem_Base', \CryptForWordPress\Helper::get_wp_filesystem() );
	}

	/**
	 * Test that an existing, writable file is reported as writable.
	 *
	 * @return void
	 */
	public function test_is_writable_for_a_writable_file(): void {
		$file = wp_tempnam( 'crypt-for-wordpress-writable' );

		$this->assertTrue( \CryptForWordPress\Helper::is_writable( $file ) );

		wp_delete_file( $file );
	}

	/**
	 * Test that a path which does not exist is never reported as writable.
	 *
	 * @return void
	 */
	public function test_is_writable_for_a_missing_path(): void {
		$this->assertFalse(
			\CryptForWordPress\Helper::is_writable( '/this/path/does/not/exist/' . uniqid( '', true ) . '.php' )
		);
	}
}
