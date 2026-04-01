<?php
/**
 * Test this package with forced usage of wp-config.php for the hash.
 *
 * Hint: tests will fail as wp-config.php does not exist in the PHP Unit environment.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test this package with forced usage of wp-config.php for the hash.
 */
class ForceWpConfig extends CryptForWordPressTests {
    /**
     * Test if we force the usage of wp-config.php for the hash.
     *
     * @return void
     */
    public function test_force_wp_config(): void {
        // configure the crypt object.
        $crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
        $crypt_obj->set_config(
            array(
                'force_place' => 'wpconfig',
            )
        );

        // test it.
        $place = $crypt_obj->get_place();
        $this->assertIsBool( $place );
        $this->assertFalse( $place );
        $this->assertNotInstanceOf( '\CryptForWordPress\Places\WpConfig', $place );
    }
}