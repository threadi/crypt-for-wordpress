<?php
/**
 * Test the default behaviour of the two base objects.
 *
 * Plugin authors can add their own methods and places via the
 * "{slug}_crypt_methods" and "{slug}_places" filters by extending these base
 * objects. The defaults therefore have to be safe: an object which has not
 * been implemented yet must never claim to be usable, and must never hand
 * out anything that looks like a valid ciphertext.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the default behaviour of the two base objects.
 */
class BaseObjects extends CryptForWordPressTests {

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

		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'base-objects-' . uniqid( '', true ) );
	}

	/**
	 * Test that a not further implemented method is never considered usable.
	 *
	 * @return void
	 */
	public function test_method_base_is_not_usable(): void {
		$method = new \CryptForWordPress\Method_Base();

		$this->assertFalse( $method->is_usable() );
		$this->assertSame( '', $method->get_name() );
		$this->assertSame( '', $method->get_hash() );
	}

	/**
	 * Test that a not further implemented method never returns the plain
	 * text back as if it had been encrypted.
	 *
	 * @return void
	 */
	public function test_method_base_never_returns_plain_text(): void {
		$method = new \CryptForWordPress\Method_Base();

		$this->assertSame( '', $method->encrypt( 'Hallo World' ) );
		$this->assertSame( '', $method->decrypt( 'some-encrypted-value' ) );
	}

	/**
	 * Test that empty input stays empty in both directions.
	 *
	 * @return void
	 */
	public function test_method_base_passes_empty_values_through(): void {
		$method = new \CryptForWordPress\Method_Base();

		$this->assertSame( '', $method->encrypt( '' ) );
		$this->assertSame( '', $method->decrypt( '' ) );
	}

	/**
	 * Test that set_config() merges into the existing configuration instead
	 * of replacing it, so a plugin author only has to pass the values they
	 * actually want to change.
	 *
	 * @return void
	 */
	public function test_method_config_is_merged(): void {
		$method = new \CryptForWordPress\Methods\OpenSsl( $this->crypt_obj );

		$method->set_config( array( 'cipher_algorithm' => 'AES-256-CBC' ) );
		$method->set_config( array( 'hash_algorithm' => 'sha512' ) );

		$property = new \ReflectionProperty( $method, 'configuration' );
		$property->setAccessible( true );
		$config = (array) $property->getValue( $method );

		$this->assertSame( 'AES-256-CBC', $config['cipher_algorithm'] );
		$this->assertSame( 'sha512', $config['hash_algorithm'] );
		$this->assertSame( 'hash', $config['hash_type'] );
	}

	/**
	 * Test that a not further implemented place is never considered usable,
	 * so it can never be picked to store a key.
	 *
	 * @return void
	 */
	public function test_place_base_is_not_usable(): void {
		$place = new \CryptForWordPress\Place_Base();

		$this->assertFalse( $place->is_usable() );
		$this->assertSame( '', $place->get_name() );
	}

	/**
	 * Test that the no-op methods of the base place can be called without
	 * side effects, so a partly implemented place cannot break the flow.
	 *
	 * @return void
	 */
	public function test_place_base_methods_are_harmless_no_ops(): void {
		$place = new \CryptForWordPress\Place_Base();

		$place->set_constant( 'SOME-CONSTANT' );
		$place->save( 'some-hash' );
		$place->load();
		$place->uninstall( 'SOME-CONSTANT' );

		$this->assertFalse( defined( 'SOME-CONSTANT' ) );
	}

	/**
	 * Test that the constant a place is told to write is the one it gets
	 * back - it must not be derived or rewritten internally.
	 *
	 * @return void
	 */
	public function test_place_keeps_the_given_constant(): void {
		$place    = new \CryptForWordPress\Places\Database( $this->crypt_obj );
		$constant = strtoupper( $this->crypt_obj->get_slug() ) . '-HASH';

		$place->set_constant( $constant );

		$property = new \ReflectionProperty( \CryptForWordPress\Place_Base::class, 'constant' );
		$property->setAccessible( true );

		$this->assertSame( $constant, $property->getValue( $place ) );
	}

	/**
	 * Test that the configuration of a place is merged as well.
	 *
	 * @return void
	 */
	public function test_place_config_is_merged(): void {
		$place = new \CryptForWordPress\Places\Database( $this->crypt_obj );

		$place->set_config( array( 'block_database' => true ) );
		$this->assertFalse( $place->is_usable() );

		$place->set_config( array( 'some_other_key' => 'value' ) );
		$this->assertFalse( $place->is_usable(), 'A later set_config() call must not drop the blocking flag.' );
	}

	/**
	 * Test that the constant name used for the hash can be changed via the
	 * "{slug}_crypt_constant" filter.
	 *
	 * @return void
	 */
	public function test_constant_name_is_filterable(): void {
		$method   = new \CryptForWordPress\Methods\Sodium( $this->crypt_obj );
		$expected = 'MY-OWN-CONSTANT-' . strtoupper( $this->crypt_obj->get_slug() );

		$this->assertSame( strtoupper( $this->crypt_obj->get_slug() ) . '-SODIUM-HASH', $method->get_constant() );

		add_filter(
			$this->crypt_obj->get_slug() . '_crypt_constant',
			function () use ( $expected ) {
				return $expected;
			}
		);

		$this->assertSame( $expected, $method->get_constant() );
	}
}
