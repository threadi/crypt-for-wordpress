<?php
/**
 * Test the default settings of this package.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test this package in its default settings.
 */
class DefaultSettings extends CryptForWordPressTests {
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
        // configure the crypt object.
        $this->crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
    }

    /**
     * Test if a supported method could be detected.
     *
     * @return void
     */
    public function test_check_method(): void {
        // check if OpenSSL is available.
        $openssl_is_available = function_exists( 'openssl_encrypt' );

        // test it.
        $method = $this->crypt_obj->get_method();
        $this->assertIsObject( $method );
        if( $openssl_is_available ) {
            $this->assertEquals( \CryptForWordPress\Methods\OpenSsl::get_instance( $this->crypt_obj ), $method );
            $this->assertNotEquals( \CryptForWordPress\Methods\Sodium::get_instance( $this->crypt_obj ), $method );
        }
        else {
            $this->assertNotEquals( \CryptForWordPress\Methods\OpenSsl::get_instance( $this->crypt_obj ), $method );
            $this->assertEquals( \CryptForWordPress\Methods\Sodium::get_instance( $this->crypt_obj ), $method );
        }
    }

    /**
     * Test to encrypt a text.
     *
     * @return void
     */
    public function test_encrypt(): void {
        // set the plain text.
        $test_text = "Hallo World";

        // test it.
        $encrypted_text = $this->crypt_obj->encrypt( $test_text );
        $this->assertIsString( $encrypted_text );
        $this->assertNotEmpty( $encrypted_text );
        $this->assertNotEquals( $test_text, $encrypted_text );
    }

    /**
     * Test to decrypt a text.
     *
     * @return void
     */
    public function test_decrypt(): void {
        // set the plain text.
        $test_text = "Hallo World";

        // encrypt it.
        $encrypted_text = $this->crypt_obj->encrypt( $test_text );
        $this->assertIsString( $encrypted_text );
        $this->assertNotEmpty( $encrypted_text );
        $this->assertNotEquals( $test_text, $encrypted_text );

        // decrypt it.
        $decrypted_text = $this->crypt_obj->decrypt( $encrypted_text );
        $this->assertIsString( $decrypted_text );
        $this->assertNotEmpty( $decrypted_text );
        $this->assertEquals( $test_text, $decrypted_text );
    }
}