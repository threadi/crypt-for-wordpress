<?php
/**
 * Test this package with forced usage of a MU plugin for the hash.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test this package with forced usage of a MU plugin for the hash.
 */
class ForceMuPlugin extends CryptForWordPressTests {
    /**
     * Test if we force the usage of a MU plugin for the hash.
     *
     * @return void
     */
    public function test_force_mu_plugin(): void {
        // configure the crypt object.
        $crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
        $crypt_obj->set_config(
            array(
                'force_place' => 'muplugin',
            )
        );

        // test it.
        $place = $crypt_obj->get_place();
        if( ! is_bool( $place ) ) {
            $this->assertIsObject($place);
            $this->assertInstanceOf('\CryptForWordPress\Places\MuPlugin', $place);
            $this->assertIsBool($place->is_usable());
            $this->assertTrue($place->is_usable());
        }
    }
}