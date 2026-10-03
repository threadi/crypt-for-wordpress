<?php
/**
 * Test that the classes of this package are taken from one copy of it.
 *
 * Several plugins of a website may ship this package, in different versions.
 * PHP loads each class once, and an autoloader is asked per class. So the
 * Crypt object loads the other classes itself, from its own directory -
 * otherwise classes of two versions could end up working together.
 *
 * Two copies cannot be loaded during one test run: the classes exist as soon
 * as the tests start. So the loading itself is tested in a PHP process of
 * its own, with a second copy of the package next to this one.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Crypt;
use CryptForWordPress\Tests\CryptForWordPressTests;
use ReflectionClass;

/**
 * Object to test that the classes of this package are taken from one copy.
 */
class PackageClasses extends CryptForWordPressTests {

	/**
	 * The temporary directory holding a second copy of the package.
	 *
	 * @var string
	 */
	private string $copy_path = '';

	/**
	 * Remove the second copy of the package.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->copy_path && is_dir( $this->copy_path ) ) {
			foreach ( array_merge( (array) glob( $this->copy_path . '/*/*' ), (array) glob( $this->copy_path . '/*' ) ) as $entry ) {
				is_dir( (string) $entry ) ? rmdir( (string) $entry ) : unlink( (string) $entry );
			}
			rmdir( $this->copy_path );
		}
		$this->copy_path = '';

		parent::tear_down();
	}

	/**
	 * Run a PHP process, in which another plugin ships a second copy of this
	 * package and its autoloader is the one PHP asks. Create a Crypt object
	 * of THIS copy there, let it load the methods, and return where each
	 * class of the package has been loaded from.
	 *
	 * @param string $preloaded_class A class the other plugin has loaded from its copy before, or an empty string.
	 *
	 * @return array<string,string> "own" or "copy" per class name.
	 */
	private function get_class_origins( string $preloaded_class ): array {
		// bail if this hosting does not allow running a process.
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			$this->markTestSkipped( 'Running a PHP process is not possible here.' );
		}

		$src_path = dirname( __DIR__, 2 ) . '/src';

		// create the second copy of the package.
		$this->copy_path = sys_get_temp_dir() . '/crypt-for-wordpress-copy-' . uniqid( '', true );
		foreach ( array( '', '/Methods', '/Places' ) as $directory ) {
			mkdir( $this->copy_path . $directory );
			foreach ( (array) glob( $src_path . $directory . '/*.php' ) as $file ) {
				copy( (string) $file, $this->copy_path . $directory . '/' . basename( (string) $file ) );
			}
		}

		// what happens in the other process.
		$script = <<<'PHP'
<?php
list( , $copy_path, $src_path, $preloaded_class ) = $argv;

// the few things of WordPress the Crypt object needs to list its methods.
define( 'ABSPATH', '/' );
function plugin_basename( $file ) { return 'my-plugin/my-plugin.php'; }
function apply_filters( $hook, $value ) { return $value; }

// the autoloader of the other plugin: it knows every class of the package.
spl_autoload_register(
	static function ( $class_name ) use ( $copy_path ) {
		if ( str_starts_with( $class_name, 'CryptForWordPress\\' ) ) {
			require $copy_path . '/' . str_replace( '\\', '/', substr( $class_name, 18 ) ) . '.php';
		}
	}
);

// a class the other plugin loaded already.
if ( '' !== $preloaded_class ) {
	class_exists( 'CryptForWordPress\\' . $preloaded_class );
}

// this plugin got the Crypt class of its own copy.
require $src_path . '/Crypt.php';
( new CryptForWordPress\Crypt( '/my-plugin/my-plugin.php' ) )->get_methods_as_objects();

$origins = array();
foreach ( get_declared_classes() as $class_name ) {
	if ( str_starts_with( $class_name, 'CryptForWordPress\\' ) ) {
		$file                   = (string) realpath( (string) ( new ReflectionClass( $class_name ) )->getFileName() );
		$origins[ $class_name ] = str_starts_with( $file, (string) realpath( $src_path ) ) ? 'own' : 'copy';
	}
}
echo json_encode( $origins );
PHP;
		file_put_contents( $this->copy_path . '/run.php', $script );

		$output  = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->copy_path . '/run.php' ) . ' ' . escapeshellarg( $this->copy_path ) . ' ' . escapeshellarg( $src_path ) . ' ' . escapeshellarg( $preloaded_class ) . ' 2>&1' );
		$origins = json_decode( (string) $output, true );

		$this->assertIsArray( $origins, (string) $output );

		return $origins;
	}

	/**
	 * Test that a Crypt object takes the other classes from its own copy,
	 * although the autoloader would hand out the ones of another copy.
	 *
	 * @return void
	 */
	public function test_classes_are_taken_from_the_own_copy(): void {
		$origins = $this->get_class_origins( '' );

		// the Crypt class and all the others.
		$this->assertCount( 14, $origins );
		$this->assertSame( array( 'own' ), array_values( array_unique( $origins ) ), (string) wp_json_encode( $origins ) );
	}

	/**
	 * Test that nothing is taken from the own copy, if a class of another
	 * copy exists already: the classes of this copy must not be added to
	 * the ones of the other. The autoloader decides, as it did before.
	 *
	 * @return void
	 */
	public function test_classes_are_not_mixed_with_the_ones_of_another_copy(): void {
		$origins = $this->get_class_origins( 'Method_Base' );

		$this->assertSame( 'own', $origins['CryptForWordPress\\Crypt'] );
		unset( $origins['CryptForWordPress\\Crypt'] );

		// the base class and the methods extending it.
		$this->assertGreaterThanOrEqual( 4, count( $origins ) );
		$this->assertSame( array( 'copy' ), array_values( array_unique( $origins ) ), (string) wp_json_encode( $origins ) );
	}

	/**
	 * Test that every class of this package is part of the list - also one
	 * that is added later.
	 *
	 * @return void
	 */
	public function test_every_class_is_loaded_by_the_crypt_object(): void {
		$src_path = dirname( __DIR__, 2 ) . '/src/';

		// collect the files of all classes, except the Crypt class itself.
		$files = array();
		foreach ( array_merge( (array) glob( $src_path . '*.php' ), (array) glob( $src_path . '*/*.php' ) ) as $file ) {
			$files[] = substr( (string) $file, strlen( $src_path ) );
		}
		$files = array_values( array_diff( $files, array( 'Crypt.php' ) ) );
		sort( $files );

		$listed = ( new ReflectionClass( Crypt::class ) )->getConstant( 'PACKAGE_FILES' );
		$this->assertIsArray( $listed );

		// base classes have to be loaded before the classes extending them.
		$this->assertSame( array( 'Helper.php', 'Method_Base.php', 'Place_Base.php' ), array_slice( $listed, 0, 3 ) );

		sort( $listed );
		$this->assertSame( $files, $listed );
	}

	/**
	 * Test that every file of the list holds the class its path stands for.
	 *
	 * @return void
	 */
	public function test_every_file_holds_its_class(): void {
		$crypt_obj = new Crypt( self::get_plugin_path() );
		$crypt_obj->set_slug( 'package-classes-' . uniqid( '', true ) );

		// this loads the classes.
		$crypt_obj->get_methods_as_objects();

		$listed = ( new ReflectionClass( Crypt::class ) )->getConstant( 'PACKAGE_FILES' );
		$this->assertIsArray( $listed );

		foreach ( $listed as $file ) {
			$class_name = 'CryptForWordPress\\' . str_replace( '/', '\\', substr( $file, 0, -4 ) );

			$this->assertTrue( class_exists( $class_name, false ), $class_name );
			$this->assertSame(
				realpath( dirname( __DIR__, 2 ) . '/src/' . $file ),
				realpath( (string) ( new ReflectionClass( $class_name ) )->getFileName() ),
				$class_name
			);
		}
	}
}
