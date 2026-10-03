<?php
/**
 * Test that plain texts and keys do not show up in stack traces.
 *
 * A stack trace lists the arguments of every function on the way to an
 * error. It ends up in the error log, in the output of debugging plugins
 * and in error trackers - places a secret does not belong to. PHP leaves
 * out every argument, which is marked with #[\SensitiveParameter].
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Object to test that plain texts and keys do not show up in stack traces.
 */
class SensitiveParameters extends CryptForWordPressTests {

	/**
	 * The names of parameters, which hold a plain text or a key.
	 *
	 * @var array<int,string>
	 */
	private const SENSITIVE_NAMES = array( 'plain_text', 'hash', 'key', 'stored_key', 'key_material', 'raw_key', 'enc_key', 'hmac_key' );

	/**
	 * The setting of PHP to restore after a test.
	 *
	 * @var string
	 */
	private string $ignore_args = '';

	/**
	 * Make sure PHP collects the arguments for stack traces. Many servers
	 * do, the command line used for tests often does not.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->ignore_args = (string) ini_get( 'zend.exception_ignore_args' );
		ini_set( 'zend.exception_ignore_args', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored in tear_down().
	}

	/**
	 * Restore the setting of PHP.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ini_set( 'zend.exception_ignore_args', $this->ignore_args ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the value it had before.

		parent::tear_down();
	}

	/**
	 * Return every argument of the given stack trace, per function.
	 *
	 * @param array<int,array<string,mixed>> $trace The stack trace.
	 *
	 * @return array<int,array{function:string,args:array<int,mixed>}>
	 */
	private function get_frames( array $trace ): array {
		$frames = array();
		foreach ( $trace as $frame ) {
			$frames[] = array(
				'function' => ( $frame['class'] ?? '' ) . '::' . $frame['function'],
				'args'     => $frame['args'] ?? array(),
			);
		}

		return $frames;
	}

	/**
	 * Test that the text to encrypt is not part of a stack trace.
	 *
	 * @return void
	 */
	public function test_plain_text_is_not_part_of_a_stack_trace(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sensitive-plain-text-' . uniqid( '', true ) );
		$crypt_obj->set_config( array( 'force_place' => 'database' ) );

		add_filter(
			$crypt_obj->get_slug() . '_crypt_methods',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\ThrowingMethod' );
			}
		);

		$trace = array();
		try {
			$crypt_obj->encrypt( 'sk_live_123' );
		} catch ( RuntimeException $e ) {
			$trace = $e->getTrace();

			// what would be written into an error log.
			$this->assertStringContainsString( 'Crypt->encrypt(Object(SensitiveParameterValue)', $e->getTraceAsString() );
		}

		$frames = $this->get_frames( $trace );

		// the method of the test does not hide it: arguments are collected.
		$this->assertSame( 'CryptForWordPress\Tests\Fixtures\ThrowingMethod::encrypt', $frames[0]['function'] );
		$this->assertSame( 'sk_live_123', $frames[0]['args'][0] );

		// this package hides it.
		$this->assertSame( 'CryptForWordPress\Crypt::encrypt', $frames[1]['function'] );
		$this->assertInstanceOf( SensitiveParameterValue::class, $frames[1]['args'][0] );
	}

	/**
	 * Test that the key is not part of a stack trace.
	 *
	 * @return void
	 */
	public function test_key_is_not_part_of_a_stack_trace(): void {
		$crypt_obj = new \CryptForWordPress\Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'sensitive-key-' . uniqid( '', true ) );
		$crypt_obj->set_config(
			array(
				'force_method' => 'openssl',
				'force_place'  => 'throwing',
			)
		);

		add_filter(
			$crypt_obj->get_slug() . '_crypt_places',
			function () {
				return array( 'CryptForWordPress\Tests\Fixtures\ThrowingPlace' );
			}
		);

		$trace = array();
		try {
			$crypt_obj->encrypt( 'sk_live_123' );
		} catch ( RuntimeException $e ) {
			$trace = $e->getTrace();
		}

		$frames = $this->get_frames( $trace );

		// the place of the test does not hide it: this is the new key.
		$this->assertSame( 'CryptForWordPress\Tests\Fixtures\ThrowingPlace::save', $frames[0]['function'] );
		$key = $frames[0]['args'][0];
		$this->assertIsString( $key );
		$this->assertSame( 64, strlen( $key ) );

		// this package hides it.
		$this->assertSame( 'CryptForWordPress\Crypt::save_in_place', $frames[1]['function'] );
		$this->assertInstanceOf( SensitiveParameterValue::class, $frames[1]['args'][1] );

		// neither the key nor the plain text is an argument anywhere else.
		foreach ( array_slice( $frames, 1 ) as $frame ) {
			$this->assertNotContains( $key, $frame['args'], $frame['function'] );
			$this->assertNotContains( 'sk_live_123', $frame['args'], $frame['function'] );
		}
	}

	/**
	 * Test that every parameter of this package, which holds a plain text or
	 * a key, is marked as sensitive. Parameters are found by their names, so
	 * a new one is only checked if it is named like the existing ones.
	 *
	 * @return void
	 */
	public function test_every_parameter_with_a_secret_is_marked(): void {
		$src_path = dirname( __DIR__, 2 ) . '/src/';
		$checked  = 0;

		$files = array_merge( (array) glob( $src_path . '*.php' ), (array) glob( $src_path . '*/*.php' ) );
		foreach ( $files as $file ) {
			$class_name = 'CryptForWordPress\\' . str_replace( '/', '\\', substr( (string) $file, strlen( $src_path ), -4 ) );
			$this->assertTrue( class_exists( $class_name ), $class_name );

			$class = new ReflectionClass( $class_name );
			foreach ( $class->getMethods() as $method ) {
				// bail if the method is inherited: it is checked on its own class.
				if ( $method->getDeclaringClass()->getName() !== $class_name ) {
					continue;
				}

				foreach ( $method->getParameters() as $parameter ) {
					// bail if this parameter does not hold a secret.
					if ( ! in_array( $parameter->getName(), self::SENSITIVE_NAMES, true ) ) {
						continue;
					}

					$this->assertNotEmpty(
						$parameter->getAttributes( SensitiveParameter::class ),
						$class_name . '::' . $method->getName() . '( $' . $parameter->getName() . ' )'
					);
					++$checked;
				}
			}
		}

		// make sure something has been checked at all.
		$this->assertGreaterThan( 0, $checked );

		// the rewritten wp-config.php, and the function writing it, hold the key as well.
		foreach ( array( 'atomic_put_contents' => 'content', 'with_lock' => 'callback' ) as $method_name => $parameter_name ) {
			foreach ( ( new ReflectionMethod( \CryptForWordPress\Places\WpConfig::class, $method_name ) )->getParameters() as $parameter ) {
				if ( $parameter->getName() === $parameter_name ) {
					$this->assertNotEmpty( $parameter->getAttributes( SensitiveParameter::class ), $method_name );
				}
			}
		}
	}
}
