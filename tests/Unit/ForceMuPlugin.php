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

        if ( ! is_bool( $place ) ) {
            // the mu-plugins directory is writable in this environment: full check.
            $this->assertIsObject( $place );
            $this->assertInstanceOf( '\CryptForWordPress\Places\MuPlugin', $place );
            $this->assertIsBool( $place->is_usable() );
            $this->assertTrue( $place->is_usable() );
            return;
        }

        // the mu-plugins directory is not writable in this environment
        // (e.g. WPMU_PLUGIN_DIR missing or not writable on this host) -
        // get_place() must then reliably return false instead of an object.
        $this->assertFalse( $place );
    }
}