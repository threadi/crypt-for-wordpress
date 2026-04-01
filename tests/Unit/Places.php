<?php
/**
 * Test the supported places.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test this package in its default settings.
 */
class Places extends CryptForWordPressTests {

    /**
     * Test each supported method with the same tests.
     *
     * @dataProvider get_places
     * @param \CryptForWordPress\Place_Base $place The place to test.
     * @return void
     */
    public function test_place( \CryptForWordPress\Place_Base $place ): void {
        // test the name.
        $name = $place->get_name();
        $this->assertIsString( $name );
        $this->assertNotEmpty( $name );

        // test usability.
        $is_usable = $place->is_usable();
        $this->assertIsBool( $is_usable );
    }

    /**
     * Return the list of supported methods.
     *
     * @return iterable
     */
    public function get_places(): iterable {
        // get the crypt object.
        $crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );

        // return each supported method.
        foreach( $crypt_obj->get_places_as_object() as $method ) {
            yield array( $method );
        }
    }
}