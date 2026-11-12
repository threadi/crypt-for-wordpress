<?php
/**
 * Test that a new key is saved in the next usable place, if saving it in
 * the active place fails.
 *
 * Whether a place is usable is only an estimate. The write itself can still
 * fail - a full disk, "open_basedir", a filesystem accessed via FTP. The key
 * must then end up in the next usable place, instead of nothing being
 * encrypted at all.
 *
 * A PHP constant cannot be removed again during a process. So every test
 * uses its own unique slug.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the fallback to the next usable place.
 */
class SaveFallback extends CryptForWordPressTests {

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
	 * Build a crypt object with a unique slug and the given places.
	 *
	 * @param string              $method The method to force.
	 * @param array<int,string>   $places The places to use, in their order.
	 * @param array<string,mixed> $config Additional configuration.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt( string $method, array $places, array $config = array() ): \CryptForWordPress\Crypt {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'save-fallback-' . $method . '-' . uniqid( '', true ) );
		$crypt_obj->set_config( array_merge( array( 'force_method' => $method ), $config ) );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			static function () use ( $places ) {
				return $places;
			}
		);

		return $crypt_obj;
	}

	/**
	 * Return the name of the option the database place saves the key in.
	 *
	 * @param \CryptForWordPress\Crypt $crypt_obj The crypt object.
	 *
	 * @return string
	 */
	private function get_database_option( \CryptForWordPress\Crypt $crypt_obj ): string {
		return $crypt_obj->get_slug() . '-hash';
	}

	/**
	 * Return the name of the option holding the fingerprint of the key.
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
	 * Test that the key is saved in the next usable place, and used, if the
	 * active place loses it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_is_saved_in_the_next_place( string $method ): void {
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
				'CryptForWordPress\Places\Database',
			)
		);

		// the broken place is the active one.
		$this->assertSame( 'broken', $crypt_obj->get_place()->get_name() );

		$encrypted = $crypt_obj->encrypt( 'Hallo World' );

		$this->assertNotSame( '', $encrypted );
		$this->assertSame( 'Hallo World', $crypt_obj->decrypt( $encrypted ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_saved_in_fallback_place', $codes );
		$this->assertContains( 'insecure_place_database', $codes );
		$this->assertNotContains( 'key_not_saved', $codes );

		// the data names the failed place and the one holding the key.
		$data = $crypt_obj->get_errors()->get_error_data( 'key_saved_in_fallback_place' );
		$this->assertSame( array( 'broken' ), $data['failed_places'] );
		$this->assertSame( 'database', $data['saved_in'] );

		// the key is in the database, and it is remembered.
		$this->assertNotEmpty( get_option( $this->get_database_option( $crypt_obj ) ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', (string) get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		// the place, which has been used, is known to the Crypt object.
		$saved_place = $crypt_obj->get_saved_place( $crypt_obj->get_method()->get_constant() );
		$this->assertInstanceOf( \CryptForWordPress\Places\Database::class, $saved_place );
	}

	/**
	 * Test that a key, which a later request finds in the fallback place,
	 * decrypts what has been encrypted with it.
	 *
	 * "The next request" is simulated: the constant of this request cannot be
	 * removed, so a second slug reads the key the first one has left in the
	 * database.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_key_in_the_fallback_place_is_found_again( string $method ): void {
		$places = array(
			'CryptForWordPress\Tests\Fixtures\BrokenPlace',
			'CryptForWordPress\Places\Database',
		);

		$crypt_obj = $this->get_crypt( $method, $places );
		$encrypted = $crypt_obj->encrypt( 'Hallo World' );
		$this->assertNotSame( '', $encrypted );

		// the next request: the same installation, under a slug whose constant is not defined yet.
		$next = $this->get_crypt( $method, $places );
		update_option( $this->get_database_option( $next ), get_option( $this->get_database_option( $crypt_obj ) ) );
		update_option( $this->get_key_id_option( $next, $method ), get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );

		$this->assertSame( 'Hallo World', $next->decrypt( $encrypted ) );

		$codes = $next->get_errors()->get_error_codes();
		$this->assertContains( 'key_in_inactive_place', $codes );
		$this->assertNotContains( 'key_missing', $codes );
		$this->assertNotContains( 'key_changed', $codes );
	}

	/**
	 * Test that a blocked database is not used as fallback place.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_blocked_database_is_no_fallback_place( string $method ): void {
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
				'CryptForWordPress\Places\Database',
			),
			array( 'block_database' => true )
		);

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_not_saved', $codes );
		$this->assertNotContains( 'key_saved_in_fallback_place', $codes );
		$this->assertFalse( get_option( $this->get_database_option( $crypt_obj ) ) );
		$this->assertFalse( get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}

	/**
	 * Test that a forced place gets no fallback: the developer has chosen it.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_forced_place_gets_no_fallback( string $method ): void {
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
				'CryptForWordPress\Places\Database',
			),
			array( 'force_place' => 'broken' )
		);

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_not_saved', $codes );
		$this->assertNotContains( 'key_saved_in_fallback_place', $codes );
		$this->assertFalse( get_option( $this->get_database_option( $crypt_obj ) ) );
	}

	/**
	 * Test that places, which cannot save anything, are never used as
	 * fallback place - the key would be gone with the next request.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_place_that_cannot_save_is_no_fallback_place( string $method ): void {
		// the salts are usable, but nothing can be saved in them.
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
				'CryptForWordPress\Places\WordPressSalts',
			),
			array( 'salt' => 'CRYPT_FOR_WORDPRESS_TEST_SALT' )
		);

		$this->assertSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		$codes = $crypt_obj->get_errors()->get_error_codes();
		$this->assertContains( 'key_not_saved', $codes );
		$this->assertNotContains( 'key_saved_in_fallback_place', $codes );
	}

	/**
	 * Test that a write into the active place, which succeeds, reports
	 * nothing about a fallback.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_successful_write_reports_no_fallback( string $method ): void {
		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Places\Database',
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
			)
		);

		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );
		$this->assertNotContains( 'key_saved_in_fallback_place', $crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that the fingerprint follows the key into the fallback place.
	 *
	 * The broken place keeps its key for the whole network, the database for
	 * one site only. The fingerprint must end up with the site: as one of
	 * the network, every other site would find it without a key - and report
	 * the key as missing.
	 *
	 * @dataProvider methods
	 *
	 * @param string $method The method name.
	 *
	 * @return void
	 */
	public function test_multisite_fingerprint_follows_the_key_into_the_fallback_place( string $method ): void {
		$this->skipWithoutMultisite();

		$crypt_obj = $this->get_crypt(
			$method,
			array(
				'CryptForWordPress\Tests\Fixtures\BrokenPlace',
				'CryptForWordPress\Places\Database',
			)
		);
		$this->assertNotSame( '', $crypt_obj->encrypt( 'Hallo World' ) );

		// the fingerprint belongs to this site, not to the network.
		$this->assertFalse( get_site_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', (string) get_option( $this->get_key_id_option( $crypt_obj, $method ) ) );
	}
}
