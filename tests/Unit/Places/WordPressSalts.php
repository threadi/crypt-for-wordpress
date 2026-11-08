<?php
/**
 * Test the WordPressSalts place.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit\Places;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the WordPressSalts place.
 */
class WordPressSalts extends CryptForWordPressTests {

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
        $this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
        $this->crypt_obj->set_slug( 'wordpress-salts-test-' . uniqid('', true) );
    }

    /**
     * Remove any filter we added in a test, so it does not leak into other test classes.
     *
     * @return void
     */
    public function tear_down(): void {
        remove_all_filters( $this->crypt_obj->get_slug() . '_places' );
        parent::tear_down();
    }

    /**
     * Test the name of the place.
     *
     * @return void
     */
    public function test_name(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $this->assertSame( 'wordpress_salts', $place->get_name() );
    }

    /**
     * Test that this place is usable with a salt, which is not the placeholder.
     *
     * @return void
     */
    public function test_usable_with_a_real_salt(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'salt' => 'CRYPT_FOR_WORDPRESS_TEST_SALT' ) );
        $this->assertTrue( $place->is_usable() );
    }

    /**
     * Test that this place is usable by default (SECURE_AUTH_SALT) - unless
     * the test environment leaves it at the placeholder.
     *
     * @return void
     */
    public function test_usable_by_default_unless_placeholder(): void {
        $place    = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $is_dummy = defined( 'SECURE_AUTH_SALT' ) && \CryptForWordPress\Places\WordPressSalts::is_placeholder( (string) SECURE_AUTH_SALT );

        $this->assertSame( ! $is_dummy, $place->is_usable() );
    }

    /**
     * Test that 'block_salts' disables this place, regardless of the 'salt' setting.
     *
     * @return void
     */
    public function test_block_salts_disables_place(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'block_salts' => true ) );
        $this->assertFalse( $place->is_usable() );
    }

    /**
     * Test that a non-string 'salt' configuration is rejected instead of causing a TypeError later on.
     *
     * @return void
     */
    public function test_non_string_salt_config_is_unusable(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'salt' => 123 ) );
        $this->assertFalse( $place->is_usable() );
    }

    /**
     * Test that load() logs the insecurity warning every time it runs.
     *
     * @return void
     */
    public function test_load_always_logs_warning(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );

        $this->assertFalse( $this->crypt_obj->has_errors() );

        $place->load();

        $this->assertTrue( $this->crypt_obj->has_errors() );
        $this->assertContains( 'insecure_place_wordpress_salts', $this->crypt_obj->get_errors()->get_error_codes() );
    }

    /**
     * Test that load() defines our constant with the real salt value - and under
     * the exact same constant name Method_Base::get_constant() expects - if the
     * configured salt constant exists.
     *
     * @return void
     */
    public function test_load_with_defined_salt_constant(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'salt' => 'CRYPT_FOR_WORDPRESS_TEST_SALT' ) );

        $expected = hash_hkdf( 'sha256', CRYPT_FOR_WORDPRESS_TEST_SALT, 32, $this->crypt_obj->get_slug() . '-wordpress-salts' );

        $this->assertSame( $expected, $place->get_derived_key() );
        $this->assertSame( 32, strlen( $place->get_derived_key() ) );
    }

    /**
     * Same for not existing salt.
     *
     * @return void
     */
    public function test_derived_key_is_empty_for_undefined_salt_constant(): void {
        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'salt' => 'THIS_SALT_CONSTANT_DOES_NOT_EXIST' ) );

        $this->assertSame( '', $place->get_derived_key() );
    }

    /**
     * Test the full round trip: register the place via the filter (as a consuming
     * plugin would), force its usage, and verify encrypt()/decrypt() actually work
     * with a key derived from the WordPress salts.
     *
     * @return void
     */
    public function test_encrypt_decrypt_with_forced_wordpress_salts(): void {
        // opt in, exactly like a consuming plugin would via its own filter.
        add_filter(
            $this->crypt_obj->get_slug() . '_places',
            function ( array $places ): array {
                $places[] = 'CryptForWordPress\Places\WordPressSalts';
                return $places;
            }
        );
        $this->crypt_obj->set_config(
            array(
                'force_place' => 'wordpress_salts',
                'salt'        => 'CRYPT_FOR_WORDPRESS_TEST_SALT',
            )
        );

        // confirm the forced place actually resolves to our class.
        $place = $this->crypt_obj->get_place();
        $this->assertInstanceOf( \CryptForWordPress\Places\WordPressSalts::class, $place );

        // run a real round trip.
        $test_text      = 'Hallo World';
        $encrypted_text = $this->crypt_obj->encrypt( $test_text );
        $this->assertIsString( $encrypted_text );
        $this->assertNotEmpty( $encrypted_text );
        $this->assertNotEquals( $test_text, $encrypted_text );

        $decrypted_text = $this->crypt_obj->decrypt( $encrypted_text );
        $this->assertSame( $test_text, $decrypted_text );

        // the insecurity warning must have been logged along the way.
        $this->assertTrue( $this->crypt_obj->has_errors() );
        $this->assertContains( 'insecure_place_wordpress_salts', $this->crypt_obj->get_errors()->get_error_codes() );
    }

    /**
     * Test that a salt, which is still the placeholder of wp-config-sample.php,
     * makes this place unusable and is reported once.
     *
     * @return void
     */
    public function test_placeholder_salt_is_unusable(): void {
        if ( ! defined( 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT' ) ) {
            define( 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT', 'put your unique phrase here' );
        }

        $place = new \CryptForWordPress\Places\WordPressSalts( $this->crypt_obj );
        $place->set_config( array( 'salt' => 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT' ) );

        $this->assertFalse( $place->is_usable() );
        $this->assertFalse( $place->is_usable() );
        $this->assertSame( '', $place->get_derived_key() );

        $codes = $this->crypt_obj->get_errors()->get_error_codes();
        $this->assertContains( 'wordpress_salts_placeholder', $codes );
        $this->assertCount( 1, $this->crypt_obj->get_errors()->get_error_messages( 'wordpress_salts_placeholder' ) );
    }

    /**
     * Test that the placeholder is recognized with surrounding whitespace as
     * well, and that other values are not taken for it.
     *
     * @return void
     */
    public function test_placeholder_detection(): void {
        $this->assertTrue( \CryptForWordPress\Places\WordPressSalts::is_placeholder( 'put your unique phrase here' ) );
        $this->assertTrue( \CryptForWordPress\Places\WordPressSalts::is_placeholder( ' put your unique phrase here ' ) );
        $this->assertFalse( \CryptForWordPress\Places\WordPressSalts::is_placeholder( 'put your unique phrase here!' ) );
        $this->assertFalse( \CryptForWordPress\Places\WordPressSalts::is_placeholder( '' ) );
    }

    /**
     * Test that nothing is encrypted with a key derived from the placeholder,
     * if the place is forced.
     *
     * @return void
     */
    public function test_placeholder_salt_encrypts_nothing(): void {
        if ( ! defined( 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT' ) ) {
            define( 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT', 'put your unique phrase here' );
        }

        $this->crypt_obj->set_config(
            array(
                'force_method' => 'sodium',
                'force_place'  => 'wordpress_salts',
                'salt'         => 'CRYPT_FOR_WORDPRESS_TEST_PLACEHOLDER_SALT',
            )
        );

        $this->assertSame( '', $this->crypt_obj->encrypt( 'secret value' ) );
        $this->assertContains( 'wordpress_salts_placeholder', $this->crypt_obj->get_errors()->get_error_codes() );
    }
}
