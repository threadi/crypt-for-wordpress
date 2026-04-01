<?php
/**
 * Test the supported methods.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use WP_Filesystem_Direct;

/**
 * Object to test the supported methods.
 */
class Methods extends CryptForWordPressTests {

    /**
     * Test each supported method with the same tests.
     *
     * @dataProvider get_methods
     * @param \CryptForWordPress\Method_Base $method The method to test.
     * @return void
     */
    public function test_method( \CryptForWordPress\Method_Base $method ): void {
        // set the plain text.
        $test_text = "Hallo World";

        // encrypt it.
        $encrypted_text = $method->encrypt( $test_text );
        $this->assertIsString( $encrypted_text );
        $this->assertNotEmpty( $encrypted_text );
        $this->assertNotEquals( $test_text, $encrypted_text );

        // decrypt it.
        $decrypted_text = $method->decrypt( $encrypted_text );
        $this->assertIsString( $decrypted_text );
        $this->assertNotEmpty( $decrypted_text );
        $this->assertEquals( $test_text, $decrypted_text );
    }

    /**
     * Return the list of supported methods.
     *
     * @return iterable
     */
    public function get_methods(): iterable {
        self::set_up_before_class();

        // get the crypt object.
        $crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );

        // return each supported method.
        foreach( $crypt_obj->get_methods_as_objects() as $method ) {
            $method->init();
            yield array( $method );
        }
    }
}