<?php
/**
 * Test the public configuration API of the Crypt object.
 *
 * These tests cover the parts of \CryptForWordPress\Crypt which are not
 * about encrypting itself: slug handling, the configuration array, the
 * "force_method"/"force_place" shortcuts, the two filters and the plugin
 * metadata getters.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the public configuration API of the Crypt object.
 */
class CryptConfiguration extends CryptForWordPressTests {

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

		// get_plugin_data() lives in an admin include which is not loaded
		// on every request - make sure it is available here.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
	}

	/**
	 * Test that the slug is derived from the given plugin path.
	 *
	 * @return void
	 */
	public function test_slug_is_derived_from_plugin_path(): void {
		$slug = $this->crypt_obj->get_slug();

		$this->assertIsString( $slug );
		$this->assertNotEmpty( $slug );
		$this->assertStringEndsWith( 'php-unit-tests-for-crypt-for-wordpress', $slug );
	}

	/**
	 * Test that a manually set slug wins over the derived one.
	 *
	 * @return void
	 */
	public function test_set_slug_overrides_derived_slug(): void {
		$this->crypt_obj->set_slug( 'my-custom-slug' );

		$this->assertSame( 'my-custom-slug', $this->crypt_obj->get_slug() );
	}

	/**
	 * Test that the configuration is empty by default and can be set and
	 * read back unchanged.
	 *
	 * @return void
	 */
	public function test_configuration_round_trip(): void {
		$this->assertSame( array(), $this->crypt_obj->get_config() );

		$config = array(
			'force_place' => 'database',
			'openssl'     => array(
				'cipher_algorithm' => 'AES-256-CBC',
			),
		);
		$this->crypt_obj->set_config( $config );

		$this->assertSame( $config, $this->crypt_obj->get_config() );
	}

	/**
	 * Test that set_config() replaces the previous configuration instead of
	 * merging into it - the method-level configs are merged, the crypt-level
	 * one is not.
	 *
	 * @return void
	 */
	public function test_set_config_replaces_previous_configuration(): void {
		$this->crypt_obj->set_config( array( 'force_place' => 'database' ) );
		$this->crypt_obj->set_config( array( 'force_method' => 'openssl' ) );

		$this->assertSame( array( 'force_method' => 'openssl' ), $this->crypt_obj->get_config() );
	}

	/**
	 * Test that the per-method configuration is returned for the matching
	 * method name only.
	 *
	 * @return void
	 */
	public function test_get_method_config_returns_matching_section(): void {
		$this->crypt_obj->set_config(
			array(
				'openssl' => array(
					'cipher_algorithm' => 'AES-256-CBC',
				),
			)
		);

		$this->assertSame( array( 'cipher_algorithm' => 'AES-256-CBC' ), $this->crypt_obj->get_method_config( 'openssl' ) );
	}

	/**
	 * Test that an unknown method name yields an empty configuration
	 * instead of a warning or a null value.
	 *
	 * @return void
	 */
	public function test_get_method_config_for_unknown_method_is_empty(): void {
		$this->assertSame( array(), $this->crypt_obj->get_method_config( 'does-not-exist' ) );
	}

	/**
	 * Test that a non-array configuration entry (e.g. the scalar
	 * "force_place" key) is never handed out as a method configuration.
	 *
	 * @return void
	 */
	public function test_get_method_config_ignores_non_array_values(): void {
		$this->crypt_obj->set_config( array( 'force_place' => 'database' ) );

		$this->assertSame( array(), $this->crypt_obj->get_method_config( 'force_place' ) );
	}

	/**
	 * Test that every returned method object is really a Method_Base and
	 * carries a non-empty name.
	 *
	 * @return void
	 */
	public function test_get_methods_as_objects_returns_method_objects(): void {
		$methods = $this->crypt_obj->get_methods_as_objects();

		$this->assertNotEmpty( $methods );
		foreach ( $methods as $method ) {
			$this->assertInstanceOf( '\CryptForWordPress\Method_Base', $method );
			$this->assertNotEmpty( $method->get_name() );
		}
	}

	/**
	 * Test that "force_method" reduces the list of method objects to
	 * exactly the requested one.
	 *
	 * @return void
	 */
	public function test_force_method_limits_the_method_list(): void {
		$this->crypt_obj->set_config( array( 'force_method' => 'sodium' ) );

		$methods = $this->crypt_obj->get_methods_as_objects();

		$this->assertCount( 1, $methods );
		$this->assertSame( 'sodium', $methods[0]->get_name() );
		$this->assertInstanceOf( '\CryptForWordPress\Methods\Sodium', $methods[0] );
	}

	/**
	 * Test that an unknown "force_method" leaves no method at all, rather
	 * than silently falling back to a different one.
	 *
	 * @return void
	 */
	public function test_unknown_force_method_leaves_no_method(): void {
		$this->crypt_obj->set_config( array( 'force_method' => 'this-method-does-not-exist' ) );

		$this->assertSame( array(), $this->crypt_obj->get_methods_as_objects() );
	}

	/**
	 * Test that "force_place" reduces the list of place objects to exactly
	 * the requested one.
	 *
	 * @return void
	 */
	public function test_force_place_limits_the_place_list(): void {
		$this->crypt_obj->set_config( array( 'force_place' => 'database' ) );

		$places = $this->crypt_obj->get_places_as_objects();

		$this->assertCount( 1, $places );
		$this->assertSame( 'database', $places[0]->get_name() );
		$this->assertInstanceOf( '\CryptForWordPress\Places\Database', $places[0] );
	}

	/**
	 * Test that the per-method configuration is handed down to the method
	 * object itself, and merged into its defaults instead of replacing them.
	 *
	 * @return void
	 */
	public function test_method_config_is_passed_to_the_method_object(): void {
		$this->crypt_obj->set_config(
			array(
				'force_method' => 'openssl',
				'openssl'      => array(
					'cipher_algorithm' => 'AES-256-CBC',
				),
			)
		);

		$methods = $this->crypt_obj->get_methods_as_objects();
		$this->assertCount( 1, $methods );

		// read the protected configuration of the method object.
		$property = new \ReflectionProperty( $methods[0], 'configuration' );
		$config = (array) $property->getValue( $methods[0] );

		// the given value has been applied ...
		$this->assertSame( 'AES-256-CBC', $config['cipher_algorithm'] );

		// ... and the untouched defaults survived.
		$this->assertSame( 'hash', $config['hash_type'] );
		$this->assertSame( 'sha256', $config['hash_algorithm'] );
	}

	/**
	 * Test that the "{slug}_crypt_methods" filter is respected.
	 *
	 * @return void
	 */
	public function test_crypt_methods_filter_is_applied(): void {
		$this->crypt_obj->set_slug( 'filter-methods-' . uniqid( '', true ) );

		add_filter( $this->crypt_obj->get_slug() . '_crypt_methods', '__return_empty_array' );

		$this->assertSame( array(), $this->crypt_obj->get_methods_as_objects() );
	}

	/**
	 * Test that the "{slug}_places" filter is respected.
	 *
	 * @return void
	 */
	public function test_places_filter_is_applied(): void {
		$this->crypt_obj->set_slug( 'filter-places-' . uniqid( '', true ) );

		add_filter(
			$this->crypt_obj->get_slug() . '_crypt_places',
			function () {
				return array( 'CryptForWordPress\Places\Database' );
			}
		);

		$places = $this->crypt_obj->get_places_as_objects();

		$this->assertCount( 1, $places );
		$this->assertSame( 'database', $places[0]->get_name() );
	}

	/**
	 * Test that class names added via filter which do not exist, or which
	 * are not a Place_Base, are skipped silently instead of causing a fatal
	 * error.
	 *
	 * @return void
	 */
	public function test_invalid_place_classes_are_skipped(): void {
		$this->crypt_obj->set_slug( 'filter-invalid-places-' . uniqid( '', true ) );

		add_filter(
			$this->crypt_obj->get_slug() . '_crypt_places',
			function () {
				return array( 'CryptForWordPress\Places\ThisClassDoesNotExist', 'stdClass' );
			}
		);

		$this->assertSame( array(), $this->crypt_obj->get_places_as_objects() );
	}

	/**
	 * Test that the plugin metadata is read from the plugin file the object
	 * was constructed with.
	 *
	 * @return void
	 */
	public function test_plugin_metadata_is_read_from_the_plugin_file(): void {
		$this->assertSame( 'PHP Unit Tests for Crypt for WordPress', $this->crypt_obj->get_plugin_name() );
		$this->assertSame( 'Your name', $this->crypt_obj->get_plugin_author() );
		$this->assertSame( esc_url( 'Your URI' ), $this->crypt_obj->get_plugin_author_url() );
	}

	/**
	 * Test that a plugin file without any headers yields empty metadata
	 * instead of a warning or a null value.
	 *
	 * @return void
	 */
	public function test_plugin_metadata_of_a_file_without_headers_is_empty(): void {
		$file = wp_tempnam( 'crypt-for-wordpress-no-headers' );
		file_put_contents( $file, '<?php // no plugin headers at all.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_plugin_file( $file );

		$this->assertSame( '', $crypt_obj->get_plugin_name() );
		$this->assertSame( '', $crypt_obj->get_plugin_author() );
		$this->assertSame( '', $crypt_obj->get_plugin_author_url() );

		wp_delete_file( $file );
	}

	/**
	 * Test the structure of the debug output.
	 *
	 * @return void
	 */
	public function test_debug_returns_the_used_settings(): void {
		$this->crypt_obj->set_slug( 'debug-' . uniqid( '', true ) );
		$this->crypt_obj->set_config( array( 'block_database' => true ) );

		// pre-define the hash constant, so the method initializes from it
		// and debug() never writes a key into a real place.
		try {
			define( strtoupper( $this->crypt_obj->get_slug() ) . '-HASH', bin2hex( random_bytes( 32 ) ) );
		} catch ( \Exception $e ) {
			$this->fail( $e->getMessage() );
		}

		$debug = $this->crypt_obj->debug();

		$this->assertArrayHasKey( 'configuration', $debug );
		$this->assertArrayHasKey( 'place', $debug );
		$this->assertArrayHasKey( 'method', $debug );

		$this->assertSame( array( 'block_database' => true ), $debug['configuration'] );
		$this->assertIsString( $debug['place'] );
		$this->assertIsString( $debug['method'] );
	}

    /**
     * Test that the configuration reaches a place BEFORE it is asked whether
     * it is usable - otherwise every config-driven check decides on defaults.
     *
     * @return void
     */
    public function test_blocked_place_is_never_selected(): void {
        $this->crypt_obj->set_slug( 'block-database-' . uniqid( '', true ) );
        $this->crypt_obj->set_config(
            array(
                'force_place'    => 'database',
                'block_database' => true,
            )
        );

        $this->assertFalse( $this->crypt_obj->get_place() );
    }

    /**
     * Test that a place, which only becomes usable through its configuration
     * can actually be selected.
     *
     * @return void
     */
    public function test_configured_place_becomes_selectable(): void {
        $this->crypt_obj->set_slug( 'custom-file-' . uniqid( '', true ) );
        $this->crypt_obj->set_config(
            array(
                'force_place'      => 'customfile',
                'custom_file_path' => get_temp_dir() . uniqid( 'cfwp-', true ) . '.php',
            )
        );

        $this->assertInstanceOf( '\CryptForWordPress\Places\CustomFile', $this->crypt_obj->get_place() );
    }
}
