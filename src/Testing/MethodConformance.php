<?php
/**
 * File with conformance tests for methods of your own.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Testing;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use Throwable;

/**
 * Conformance tests for a method of your own.
 *
 * A method has to follow some rules, which the Crypt object and the plugins
 * using it rely on - e.g. that a changed value is never decrypted, and that
 * no input ends in an exception. These tests check them.
 *
 * Use the trait in a PHPUnit test class, which runs with WordPress loaded -
 * e.g. one extending WP_UnitTestCase - and name the class of your method:
 *
 *     class My_Method_Test extends WP_UnitTestCase {
 *         use \CryptForWordPress\Testing\MethodConformance;
 *
 *         protected function get_method_class(): string {
 *             return My_Method::class;
 *         }
 *     }
 *
 * The method is tested through a Crypt object, which only knows this
 * method, with the key in the place "database". Every test uses a slug of
 * its own: a constant cannot be removed again during a test run.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait MethodConformance {

	/**
	 * Return the class of the method to test. Its constructor gets the
	 * Crypt object, as with every method, and it extends Method_Base.
	 *
	 * @return string
	 */
	abstract protected function get_method_class(): string;

	/**
	 * Return the configuration of the method - what is set as
	 * 'your-method-name' => array( ... ) in the configuration of the Crypt
	 * object.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_method_conformance_config(): array {
		return array();
	}

	/**
	 * Return the file of the plugin the Crypt object of a test belongs to.
	 *
	 * The file has to exist - some places read the name of the plugin from
	 * it. By default it is the file of the test class.
	 *
	 * @return string
	 */
	protected function get_method_conformance_plugin_file(): string {
		return (string) ( new \ReflectionClass( $this ) )->getFileName();
	}

	/**
	 * Return a Crypt object for a test, which only knows the method to test.
	 *
	 * @return Crypt
	 */
	protected function create_method_conformance_crypt(): Crypt {
		$crypt_obj = new Crypt( $this->get_method_conformance_plugin_file() );
		$crypt_obj->set_slug( 'cfwp-method-conformance-' . str_replace( '.', '', uniqid( '', true ) ) );

		// use only the method to test.
		$method_class = $this->get_method_class();
		add_filter(
			$crypt_obj->get_slug() . '_crypt_methods',
			static function () use ( $method_class ): array {
				return array( $method_class );
			}
		);

		// get the name of the method for its configuration.
		$method = new $method_class( $crypt_obj );

		// bail if it is no method.
		if ( ! $method instanceof \CryptForWordPress\Method_Base ) {
			$this->fail( $method_class . ' does not extend \CryptForWordPress\Method_Base.' );
		}

		$crypt_obj->set_config(
			array(
				'force_place'       => 'database',
				$method->get_name() => $this->get_method_conformance_config(),
			)
		);

		// bail if the method is not usable here.
		if ( ! $method->is_usable() ) {
			$this->markTestSkipped( 'The method is not usable in this environment.' );
		}

		return $crypt_obj;
	}

	/**
	 * Return copies of the given encrypted value, each with a changed byte.
	 *
	 * If the value is base64 encoded, the decoded bytes are changed - a
	 * changed character of a base64 encoding may not change anything.
	 *
	 * @param string $encrypted The encrypted value.
	 *
	 * @return array<int,string>
	 */
	private function get_method_conformance_changed_values( string $encrypted ): array {
		$raw     = base64_decode( $encrypted, true );
		$encoded = false !== $raw && base64_encode( $raw ) === $encrypted;
		$bytes   = $encoded ? $raw : $encrypted;

		// change a byte at a quarter, at the half and at three quarters of it.
		$changed = array();
		foreach ( array( 1, 2, 3 ) as $quarter ) {
			$copy              = $bytes;
			$position          = (int) floor( strlen( $copy ) * $quarter / 4 );
			$copy[ $position ] = chr( ord( $copy[ $position ] ) ^ 0x10 );

			$changed[] = $encoded ? base64_encode( $copy ) : $copy;
		}

		return $changed;
	}

	/**
	 * Test that the method has a name and a constant for its key.
	 *
	 * @return void
	 */
	public function test_method_conformance_has_a_name_and_a_constant(): void {
		$crypt_obj = $this->create_method_conformance_crypt();
		$method    = $crypt_obj->get_method();

		$this->assertNotFalse( $method, 'The Crypt object does not use the method.' );
		$this->assertNotSame( '', $method->get_name() );
		$this->assertNotSame( '', $method->get_constant() );
	}

	/**
	 * Test the round trip: the value is encrypted - not readable in the
	 * result - and decrypted again, also by another Crypt object.
	 *
	 * @return void
	 */
	public function test_method_conformance_round_trip(): void {
		$crypt_obj = $this->create_method_conformance_crypt();
		$plain     = "Hällo Wörld – 🔐\nconformance";

		$encrypted = $crypt_obj->encrypt( $plain );
		$codes     = $crypt_obj->get_errors()?->get_error_codes() ?? array();

		$this->assertNotSame( '', $encrypted, 'Nothing has been encrypted: ' . implode( ', ', $codes ) );
		$this->assertStringNotContainsString( 'conformance', $encrypted );
		$this->assertStringNotContainsString( 'conformance', (string) base64_decode( $encrypted ) );
		$this->assertSame( $plain, $crypt_obj->decrypt( $encrypted ) );

		// another object with the same slug, as in a later request.
		$second = new Crypt( $this->get_method_conformance_plugin_file() );
		$second->set_slug( $crypt_obj->get_slug() );
		$second->set_config( $crypt_obj->get_config() );

		$this->assertSame( $plain, $second->decrypt( $encrypted ) );
	}

	/**
	 * Test that the same text gives another value every time: otherwise it
	 * would be visible, which values are equal.
	 *
	 * @return void
	 */
	public function test_method_conformance_values_differ(): void {
		$crypt_obj = $this->create_method_conformance_crypt();

		$this->assertNotSame( $crypt_obj->encrypt( 'Hallo World' ), $crypt_obj->encrypt( 'Hallo World' ) );
	}

	/**
	 * Test that a changed value is not decrypted, and that it is reported.
	 *
	 * @return void
	 */
	public function test_method_conformance_changed_value_is_refused(): void {
		$crypt_obj = $this->create_method_conformance_crypt();
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		foreach ( $this->get_method_conformance_changed_values( $encrypted ) as $changed ) {
			$crypt_obj->clear_errors();

			$this->assertSame( '', $crypt_obj->decrypt( $changed ), 'A changed value has been decrypted.' );
			$this->assertTrue( $crypt_obj->has_errors(), 'A changed value has not been reported.' );
		}
	}

	/**
	 * Test that a value is bound to its context - unless the method reports,
	 * that it does not support a context.
	 *
	 * @return void
	 */
	public function test_method_conformance_value_is_bound_to_its_context(): void {
		$crypt_obj = $this->create_method_conformance_crypt();
		$encrypted = $crypt_obj->encrypt( 'Hallo World', 'conformance' );

		// bail if the method does not support a context: then it has to say so.
		if ( '' === $encrypted ) {
			$this->assertContains( 'context_not_supported', $crypt_obj->get_errors()?->get_error_codes() ?? array(), 'Nothing has been encrypted with a context, and the reason has not been reported.' );
			return;
		}

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted, 'conformance' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted, 'another-context' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted ) );
	}

	/**
	 * Test that no input ends in an exception, and that nothing is decrypted
	 * from a value this method has not written.
	 *
	 * @return void
	 */
	public function test_method_conformance_survives_any_input(): void {
		$crypt_obj = $this->create_method_conformance_crypt();

		$inputs = array(
			'not encrypted at all',
			base64_encode( random_bytes( 64 ) ),
			base64_encode( 'a:b:c' ),
			'::::',
			"\0\xff\xfe",
			str_repeat( 'A', 10000 ),
		);

		foreach ( $inputs as $input ) {
			try {
				$this->assertSame( '', $crypt_obj->decrypt( $input ), 'Something has been decrypted from a value this method has not written.' );
				$this->assertSame( '', $crypt_obj->decrypt( $input, 'conformance' ) );
			} catch ( Throwable $e ) {
				$this->fail( 'An input ended in an exception: ' . $e->getMessage() );
			}
		}

		// an empty text is no reason for an exception either.
		try {
			$this->assertSame( '', $crypt_obj->decrypt( $crypt_obj->encrypt( '' ) ) );
		} catch ( Throwable $e ) {
			$this->fail( 'An empty text ended in an exception: ' . $e->getMessage() );
		}
	}
}
