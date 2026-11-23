<?php
/**
 * File with conformance tests for places of your own.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Testing;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Place_Base;

/**
 * Conformance tests for a place of your own.
 *
 * A place has to follow some rules, which the Crypt object relies on - e.g.
 * that a lookup changes nothing, and that is_saved() only confirms a key,
 * which really has been saved. Breaking one of them can cost the key of all
 * values encrypted so far. These tests check them.
 *
 * Use the trait in a PHPUnit test class, which runs with WordPress loaded -
 * e.g. one extending WP_UnitTestCase - and name the class of your place:
 *
 *     class My_Place_Test extends WP_UnitTestCase {
 *         use \CryptForWordPress\Testing\PlaceConformance;
 *
 *         protected function get_place_class(): string {
 *             return My_Place::class;
 *         }
 *     }
 *
 * Every test uses a slug and a constant of its own: a constant cannot be
 * removed again during a test run. Override the other protected methods of
 * this trait to configure your place, or to tell the tests what it can do.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait PlaceConformance {

	/**
	 * Return the class of the place to test. Its constructor gets the Crypt
	 * object, as with every place, and it extends Place_Base.
	 *
	 * @return string
	 */
	abstract protected function get_place_class(): string;

	/**
	 * Return the configuration of the Crypt object for a test - e.g. the
	 * settings your place needs. Called once per test.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_place_conformance_config(): array {
		return array();
	}

	/**
	 * Return whether the place can save a key. Return false for a place,
	 * which only hands over a key from somewhere else - e.g. from a variable
	 * of the hosting.
	 *
	 * @return bool
	 */
	protected function place_conformance_can_save(): bool {
		return true;
	}

	/**
	 * Return whether uninstall() removes the key from the place. Return
	 * false for a place, which leaves the key where it is - e.g. in a file
	 * the user of your plugin manages.
	 *
	 * @return bool
	 */
	protected function place_conformance_removes_key_on_uninstall(): bool {
		return true;
	}

	/**
	 * Return the key the place hands over for its constant after it has
	 * been saved - or an empty string.
	 *
	 * By default the key from get_stored_key(), or otherwise the constant
	 * defined by load(). Override this, if WordPress loads your place itself
	 * with the next request - like the wp-config.php - and read the key
	 * there.
	 *
	 * @param Place_Base $place    The place.
	 * @param string     $constant The constant of the place.
	 *
	 * @return string
	 */
	protected function get_place_conformance_saved_key( Place_Base $place, string $constant ): string {
		// use the key the place holds, if it hands it over.
		$key = $place->get_stored_key();
		if ( '' !== $key ) {
			return $key;
		}

		// let the place define the constant.
		$place->load();

		return defined( $constant ) && is_string( constant( $constant ) ) ? constant( $constant ) : '';
	}

	/**
	 * Return the file of the plugin the Crypt object of a test belongs to.
	 *
	 * The file has to exist - some places read the name of the plugin from
	 * it. By default it is the file of the test class.
	 *
	 * @return string
	 */
	protected function get_place_conformance_plugin_file(): string {
		return (string) ( new \ReflectionClass( $this ) )->getFileName();
	}

	/**
	 * Return a Crypt object for a test, which only knows the place to test.
	 *
	 * @return Crypt
	 */
	protected function create_place_conformance_crypt(): Crypt {
		$crypt_obj = new Crypt( $this->get_place_conformance_plugin_file() );
		$crypt_obj->set_slug( 'cfwp-conformance-' . str_replace( '.', '', uniqid( '', true ) ) );
		$crypt_obj->set_config( $this->get_place_conformance_config() );

		// use only the place to test.
		$place_class = $this->get_place_class();
		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function () use ( $place_class ): array {
				return array( $place_class );
			}
		);

		return $crypt_obj;
	}

	/**
	 * Return the place to test, for the given constant.
	 *
	 * @param Crypt  $crypt_obj The Crypt object.
	 * @param string $constant  The constant.
	 *
	 * @return Place_Base
	 */
	protected function create_place_conformance_place( Crypt $crypt_obj, string $constant ): Place_Base {
		$place_class = $this->get_place_class();
		$place       = new $place_class( $crypt_obj );

		// bail if it is no place.
		if ( ! $place instanceof Place_Base ) {
			$this->fail( $place_class . ' does not extend \CryptForWordPress\Place_Base.' );
		}

		$place->set_config( $crypt_obj->get_config() );
		$place->set_constant( $constant );

		return $place;
	}

	/**
	 * Return a constant, which is not defined yet.
	 *
	 * @param Crypt  $crypt_obj The Crypt object.
	 * @param string $suffix    What to add to its name.
	 *
	 * @return string
	 */
	private function get_place_conformance_constant( Crypt $crypt_obj, string $suffix = '' ): string {
		return strtoupper( $crypt_obj->get_slug() ) . $suffix . '-HASH';
	}

	/**
	 * Return a new key, as the method OpenSSL generates it.
	 *
	 * @return string
	 */
	private function get_place_conformance_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Skip the test, if the place is not usable here, or cannot save a key.
	 *
	 * @param Place_Base $place The place.
	 *
	 * @return void
	 */
	private function skip_place_conformance_without_save( Place_Base $place ): void {
		if ( ! $this->place_conformance_can_save() ) {
			$this->markTestSkipped( 'The place cannot save a key.' );
		}

		if ( ! $place->is_usable() ) {
			$this->markTestSkipped( 'The place is not usable in this environment.' );
		}
	}

	/**
	 * Test that the place has a name.
	 *
	 * @return void
	 */
	public function test_place_conformance_has_a_name(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$place     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) );

		$this->assertNotSame( '', $place->get_name() );
	}

	/**
	 * Test that the lookups of the place change nothing: they define no
	 * constant and report no error - also not, while it holds a key.
	 *
	 * @return void
	 */
	public function test_place_conformance_lookups_have_no_side_effects(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$constant  = $this->get_place_conformance_constant( $crypt_obj );
		$place     = $this->create_place_conformance_place( $crypt_obj, $constant );

		// count the errors reported from now on.
		$reported = 0;
		add_action(
			$crypt_obj->get_slug() . '_crypt_error',
			static function () use ( &$reported ): void {
				++$reported;
			}
		);

		// nothing saved yet.
		$first = $place->get_stored_key();
		$this->assertSame( $first, $place->get_stored_key() );
		$this->assertFalse( $place->is_key_unavailable() );
		$this->assertSame( $place->get_derived_key(), $place->get_derived_key() );

		// with a saved key.
		if ( $this->place_conformance_can_save() && $place->is_usable() ) {
			$key = $this->get_place_conformance_key();
			$place->save( $key );
			$reported = 0;

			$first = $place->get_stored_key();
			$this->assertSame( $first, $place->get_stored_key() );
			$this->assertFalse( $place->is_key_unavailable() );
			$this->assertTrue( $place->is_saved( $key ) );
		}

		$this->assertFalse( defined( $constant ), 'A lookup has defined the constant.' );
		$this->assertSame( 0, $reported, 'A lookup has reported an error.' );
	}

	/**
	 * Test that is_saved() confirms a key right after save() - and only that
	 * key. The Crypt object only uses a new key, once it is confirmed.
	 *
	 * @return void
	 */
	public function test_place_conformance_is_saved_right_after_save(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$place     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) );
		$this->skip_place_conformance_without_save( $place );

		$key = $this->get_place_conformance_key();
		$this->assertFalse( $place->is_saved( $key ), 'A key is confirmed, which has not been saved.' );

		$place->save( $key );

		$this->assertTrue( $place->is_saved( $key ) );
		$this->assertFalse( $place->is_saved( $this->get_place_conformance_key() ) );
		$this->assertFalse( $place->is_saved( '' ) );
	}

	/**
	 * Test that the place hands over a saved key, exactly as it has been
	 * saved.
	 *
	 * @return void
	 */
	public function test_place_conformance_hands_over_the_saved_key(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$constant  = $this->get_place_conformance_constant( $crypt_obj );
		$place     = $this->create_place_conformance_place( $crypt_obj, $constant );
		$this->skip_place_conformance_without_save( $place );

		// a key with characters a file or a query has to escape.
		$key = $this->get_place_conformance_key() . "'\\\"$";
		$place->save( $key );

		$this->assertSame( $key, $this->get_place_conformance_saved_key( $place, $constant ) );
	}

	/**
	 * Test that saving a key for one constant keeps the key of another one:
	 * a plugin can use two methods, each with a key of its own.
	 *
	 * @return void
	 */
	public function test_place_conformance_keeps_the_key_of_another_constant(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$place     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) );
		$other     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj, '-OTHER' ) );
		$this->skip_place_conformance_without_save( $place );

		$key       = $this->get_place_conformance_key();
		$other_key = $this->get_place_conformance_key();

		$place->save( $key );
		$other->save( $other_key );

		// (a place may keep one key for all constants - then the last one wins.)
		if ( ! $place->is_saved( $key ) ) {
			$this->assertTrue( $place->is_saved( $other_key ), 'The key of the first constant is gone, and the place does not hold the second one for it either.' );
			return;
		}

		$this->assertTrue( $other->is_saved( $other_key ) );
	}

	/**
	 * Test that uninstall() removes the key of the constant - or leaves it
	 * where it is, if the place says so - and does nothing harmful for a
	 * constant, which has no key.
	 *
	 * @return void
	 */
	public function test_place_conformance_uninstall_removes_the_key(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$constant  = $this->get_place_conformance_constant( $crypt_obj );
		$place     = $this->create_place_conformance_place( $crypt_obj, $constant );
		$this->skip_place_conformance_without_save( $place );

		// nothing to remove.
		$place->uninstall( $this->get_place_conformance_constant( $crypt_obj, '-NONE' ) );

		$key = $this->get_place_conformance_key();
		$place->save( $key );
		$this->assertTrue( $place->is_saved( $key ) );

		$place->uninstall( $constant );

		// the key is left where it is, if the place says so.
		$place = $this->create_place_conformance_place( $crypt_obj, $constant );
		if ( ! $this->place_conformance_removes_key_on_uninstall() ) {
			$this->assertTrue( $place->is_saved( $key ) );
			return;
		}

		$this->assertFalse( $place->is_saved( $key ) );
		$this->assertSame( '', $place->get_stored_key() );
	}

	/**
	 * Test that a key the place derives itself is the same every time, and
	 * long enough to be a key.
	 *
	 * @return void
	 */
	public function test_place_conformance_derived_key_is_stable(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$place     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) );

		$derived_key = $place->get_derived_key();

		// bail if the place derives no key.
		if ( '' === $derived_key ) {
			$this->assertSame( '', $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) )->get_derived_key() );
			return;
		}

		$this->assertGreaterThanOrEqual( 32, strlen( $derived_key ) );
		$this->assertSame( $derived_key, $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) )->get_derived_key() );
	}

	/**
	 * Test the round trip through the Crypt object, with the place as its
	 * only place: a value is encrypted, bound to its context, and decrypted.
	 *
	 * @return void
	 */
	public function test_place_conformance_round_trip_with_context(): void {
		$crypt_obj = $this->create_place_conformance_crypt();
		$place     = $this->create_place_conformance_place( $crypt_obj, $this->get_place_conformance_constant( $crypt_obj ) );

		// bail if the place cannot provide a key here.
		if ( ! $place->is_usable() || ( ! $this->place_conformance_can_save() && '' === $place->get_derived_key() ) ) {
			$this->markTestSkipped( 'The place cannot provide a key in this environment.' );
		}

		$encrypted = $crypt_obj->encrypt( 'Hallo World', 'conformance' );
		$codes     = $crypt_obj->get_errors()?->get_error_codes() ?? array();
		$this->assertNotSame( '', $encrypted, 'Nothing has been encrypted: ' . implode( ', ', $codes ) );
		$this->assertNotContains( 'key_not_saved', $codes );

		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted, 'conformance' ) );
		$this->assertSame( '', $crypt_obj->decrypt( $encrypted, 'another-context' ) );
	}
}
