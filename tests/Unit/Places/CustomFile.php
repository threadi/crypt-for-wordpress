<?php
/**
 * Test the CustomFile place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the CustomFile place.
 */
class CustomFile extends CryptForWordPressTests {

	/**
	 * The crypt object.
	 *
	 * @var \CryptForWordPress\Crypt
	 */
	private \CryptForWordPress\Crypt $crypt_obj;

	/**
	 * Paths created during a test, to be removed again in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $created_files = array();

	/**
	 * Run before every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		$this->crypt_obj     = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$this->crypt_obj->set_slug( 'customfile-test-' . uniqid('', true) );
		$this->created_files = array();
	}

	/**
	 * Clean up any file we wrote during a test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->created_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * Return a fresh, unique file path in the system temp directory for this test.
	 *
	 * @return string
	 */
	private function get_temp_file_path(): string {
		$path                  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cfwp-test-' . uniqid('', true) . '.php';
		$this->created_files[] = $path;
		return $path;
	}

	/**
	 * Test the name of the place.
	 *
	 * @return void
	 */
	public function test_name(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$this->assertSame( 'customfile', $place->get_name() );
	}

	/**
	 * Test that this place is unusable without a configured path.
	 *
	 * @return void
	 */
	public function test_unusable_without_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a non-string path is rejected instead of causing a TypeError later on.
	 *
	 * @return void
	 */
	public function test_unusable_with_non_string_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => 123 ) );
		$this->assertFalse( $place->is_usable() );
	}

	/**
	 * Test that a path in a writable directory is usable.
	 *
	 * @return void
	 */
	public function test_usable_with_writable_directory(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $this->get_temp_file_path() ) );
		$this->assertTrue( $place->is_usable() );
	}

	/**
	 * Test that save() writes a file that actually defines the constant with
	 * the given hash, and that load() picks that same file back up.
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$path     = $this->get_temp_file_path();
		$constant = 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) );
		$hash     = 'unit-test-hash-value';

		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $path ) );
		$place->set_constant( $constant );

		$place->save( $hash );

		$this->assertFileExists( $path );
		$this->assertFalse( defined( $constant ) );

		$place->load();

		$this->assertTrue( defined( $constant ) );
		$this->assertSame( $hash, constant( $constant ) );
	}

	/**
	 * Test that load() logs an error and does not fatally error if the
	 * configured file does not exist.
	 *
	 * @return void
	 */
	public function test_load_logs_error_when_file_missing(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $this->get_temp_file_path() ) );

		$this->assertFalse( $this->crypt_obj->has_errors() );

		$place->load();

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'custom_file_path_not_exists', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that save() refuses a stream-wrapper path (e.g. "phar://...")
	 * instead of writing to it.
	 *
	 * @return void
	 */
	public function test_save_rejects_stream_wrapper_path(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => 'phar://some/evil/path.php' ) );
		$place->set_constant( 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) ) );

		$place->save( 'unit-test-hash-value' );

		$this->assertTrue( $this->crypt_obj->has_errors() );
		$this->assertContains( 'custom_file_wrong_path', $this->crypt_obj->get_errors()->get_error_codes() );
	}

	/**
	 * Test that is_saved() only confirms the hash the file really holds.
	 *
	 * @return void
	 */
	public function test_is_saved_reports_what_the_file_holds(): void {
		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $this->get_temp_file_path() ) );
		$place->set_constant( 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) ) );

		$this->assertFalse( $place->is_saved( 'unit-test-hash-value' ) );

		$place->save( 'unit-test-hash-value' );

		$this->assertTrue( $place->is_saved( 'unit-test-hash-value' ) );
		$this->assertFalse( $place->is_saved( 'another-hash-value' ) );
	}

	/**
	 * Test that get_stored_key() reads the key from the file without
	 * executing it, and stays silent if there is none - it is only a lookup.
	 *
	 * @return void
	 */
	public function test_get_stored_key_is_a_lookup_without_side_effects(): void {
		$path     = $this->get_temp_file_path();
		$constant = 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) );

		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $path ) );
		$place->set_constant( $constant );

		// no file yet: nothing to return, nothing is reported.
		$this->assertSame( '', $place->get_stored_key() );
		$this->assertFalse( $this->crypt_obj->has_errors() );

		// a value with characters save() has to escape.
		$hash = "unit-test'hash\\value";
		$place->save( $hash );

		$this->assertSame( $hash, $place->get_stored_key() );
		$this->assertFalse( defined( $constant ) );

		// and it is exactly what the file defines when it is embedded.
		$place->load();
		$this->assertSame( $hash, constant( $constant ) );

		// another constant is not found in this file.
		$place->set_constant( $constant . '_OTHER' );
		$this->assertSame( '', $place->get_stored_key() );
	}

	/**
	 * Test that saving the key of one method does not remove the key of
	 * another method from the file. A plugin may use both, each with a
	 * constant of its own, in the same file.
	 *
	 * @return void
	 */
	public function test_save_keeps_the_key_of_another_constant(): void {
		$path     = $this->get_temp_file_path();
		$constant = 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) );

		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $path ) );

		$place->set_constant( $constant . '-SODIUM-HASH' );
		$place->save( 'key-of-sodium' );

		$place->set_constant( $constant . '-HASH' );
		$place->save( 'key-of-openssl' );

		// saving again replaces the own key only.
		$place->save( 'new-key-of-openssl' );

		$this->assertTrue( $place->is_saved( 'new-key-of-openssl' ) );
		$this->assertSame( 'new-key-of-openssl', $place->get_stored_key() );

		$place->set_constant( $constant . '-SODIUM-HASH' );
		$this->assertTrue( $place->is_saved( 'key-of-sodium' ) );
		$this->assertSame( 'key-of-sodium', $place->get_stored_key() );

		$this->assertSame( 2, substr_count( (string) file_get_contents( $path ), 'define(' ) );
	}

	/**
	 * Test that save() only takes over what it can take over safely from a
	 * file someone edited by hand: complete define() lines in the format
	 * this package writes. A define() over several lines, or one inside a
	 * comment, must not end up as half a statement in the new file.
	 *
	 * @return void
	 */
	public function test_save_never_writes_a_broken_file(): void {
		$path     = $this->get_temp_file_path();
		$constant = 'CUSTOM_FILE_TEST_' . strtoupper( uniqid('', true) );

		file_put_contents(
			$path,
			"<?php\n"
			. "define( '" . $constant . "-SODIUM-HASH', 'key-of-sodium' ); // Added by a plugin.\r\n"
			. "define( '" . $constant . "_LIST', array(\n\t'one',\n\t'two',\n) );\n"
			. "define( '" . $constant . "-OTHER-HASH', 'it\\'s another key' ); if ( true ) {\n\t// something else.\n}\n"
			. "/*\ndefine( '" . $constant . "_OLD', 'an-old-key' );\n*/\n"
		);

		$place = new \CryptForWordPress\Places\CustomFile( $this->crypt_obj );
		$place->set_config( array( 'custom_file_path' => $path ) );
		$place->set_constant( $constant . '-HASH' );
		$place->save( 'key-of-openssl' );

		$content = (string) file_get_contents( $path );

		// the key of the other method is kept, the own one is added.
		$this->assertStringContainsString( "define( '" . $constant . "-SODIUM-HASH', 'key-of-sodium' );", $content );
		$this->assertTrue( $place->is_saved( 'key-of-openssl' ) );

		// a key followed by other code on its line is kept, the code is not.
		$this->assertStringContainsString( "define( '" . $constant . "-OTHER-HASH', 'it\\'s another key' );\r\n", $content );
		$this->assertStringNotContainsString( 'if ( true )', $content );

		// nothing else is taken over ...
		$this->assertStringNotContainsString( $constant . '_LIST', $content );
		$this->assertStringNotContainsString( $constant . '_OLD', $content );

		// ... and the file is valid PHP.
		try {
			token_get_all( $content, TOKEN_PARSE );
			$is_valid = true;
		} catch ( \ParseError $e ) {
			$is_valid = false;
		}
		$this->assertTrue( $is_valid );
	}
}
