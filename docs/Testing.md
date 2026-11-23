# Testing places and methods of your own

You can add places and methods of your own with the filters `{slug}_crypt_places` and `{slug}_crypt_methods`. They have to follow some rules the Crypt object relies on. Breaking one of them can cost the key of all values encrypted so far - e.g. a place, which confirms a key with `is_saved()` before it really has been written.

This package ships two PHPUnit traits, which check these rules:

* `CryptForWordPress\Testing\PlaceConformance` for places,
* `CryptForWordPress\Testing\MethodConformance` for methods.

The places and methods of this package are tested with them as well.

## Requirements

* PHPUnit with WordPress loaded - e.g. a test class extending `WP_UnitTestCase` of the WordPress test suite.
* The class of your place or method is loaded by your autoloader.

## Places

```
class My_Place_Test extends WP_UnitTestCase {
	use \CryptForWordPress\Testing\PlaceConformance;

	protected function get_place_class(): string {
		return My_Place::class;
	}
}
```

The tests check:

* `get_stored_key()`, `is_key_unavailable()` and `get_derived_key()` are lookups: they define no constant and report no error.
* `is_saved()` confirms a key right after `save()` - and only that key, never an empty one.
* The place hands over the key exactly as it has been saved - also with quotes and backslashes.
* Saving the key of one constant keeps the key of another one: a plugin can use two methods.
* `uninstall()` removes the key, and does nothing harmful for a constant without a key.
* A key the place derives itself is the same every time, and at least 32 bytes long.
* The round trip through the Crypt object with a context, with your place as its only place.

Override these protected methods to adapt the tests:

| Method                                          | Default                                     | Use it, if ...                                                                                 |
|-------------------------------------------------|---------------------------------------------|------------------------------------------------------------------------------------------------|
| `get_place_conformance_config()`                | empty array                                 | your place needs settings, e.g. a path. Called once per test.                                  |
| `place_conformance_can_save()`                  | `true`                                      | your place only hands over a key from somewhere else, e.g. from a variable of the hosting.     |
| `place_conformance_removes_key_on_uninstall()`  | `true`                                      | `uninstall()` leaves the key where it is on purpose, e.g. in a file managed by the user.       |
| `get_place_conformance_saved_key()`             | `get_stored_key()`, else the constant `load()` defines | WordPress loads your place itself with the next request, like the `wp-config.php`. Read the key there. |
| `get_place_conformance_plugin_file()`           | the file of the test class                  | your place reads data of the plugin from its file.                                             |

## Methods

```
class My_Method_Test extends WP_UnitTestCase {
	use \CryptForWordPress\Testing\MethodConformance;

	protected function get_method_class(): string {
		return My_Method::class;
	}
}
```

The method is used through a Crypt object, which knows only this method, with the key in the place `database`. The tests check:

* The method has a name and a constant for its key.
* The round trip: the text is not readable in the encrypted value, and another Crypt object with the same slug decrypts it.
* The same text gives another value every time.
* A changed value is not decrypted, and this is reported.
* A value is bound to its context - or the method reports `context_not_supported`.
* No input ends in an exception, and nothing is decrypted from a value the method has not written.

Override `get_method_conformance_config()` to set the configuration of your method, and `get_method_conformance_plugin_file()` like for places.

Every test is skipped, if your method is not usable in the test environment.

## Hints

* Every test uses a slug and a constant of its own: a PHP constant cannot be removed during a test run.
* The traits are part of the package, but are never loaded by it. They do not end up in your plugin's requests.
