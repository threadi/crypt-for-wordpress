# Using the package in several plugins

Any number of plugins and themes of a website can use _Crypt for WordPress_. Each of them encrypts with its own key, which belongs to its slug. A value of one plugin cannot be decrypted with the key of another one.

The slug is the name of the directory of the plugin. Set it yourself with `set_slug()` where there is no such directory:

* a plugin consisting of a single file directly in the plugins directory, and a Must-Use plugin: all of them would get the same slug, and with it the same key. This is reported as error `slug_not_unique`
* a theme: its slug would contain the path of the installation, and change if the website is moved - the key would not be found anymore. This is reported as error `slug_depends_on_path`

Set the slug before the first value is encrypted. The key is saved under the slug: changing it later means a new key, and the values encrypted so far cannot be decrypted anymore. For that reason the package only reports such a slug and does not change it.

## What cannot be prevented

All plugins of a website run in the same PHP process with the same permissions. A plugin which wants to read the values of another one is able to: it can read the key where it is stored - the `wp-config.php`, the database, the constant - or create a `Crypt` object with the slug of the other plugin.

This is not specific to this package. WordPress does not separate plugins from each other, and PHP has no means to do so. The separate keys protect against mistakes and against vulnerabilities in other plugins, which allow reading or writing the database. They do not protect against a plugin with bad intent. See [What this package protects against](SecurityModel.md).

## One copy for all plugins

PHP loads a class once. If two plugins ship this package, both use the same `Crypt` class: the one of the copy whose autoloader finds it first. Which one that is depends on the order the plugins are loaded in and on which of them uses the class first. Your plugin has no influence on it.

The `Crypt` class takes the other classes of this package from its own copy. So usually one version is in use for all plugins - but not necessarily the one you ship:

* Your plugin may run with an older version of this package, without a fix you rely on.
* PHP does not complain about an additional argument a function does not know: a context passed to `encrypt()` of version 3.1.0 is ignored there. The value is not bound to it - and a newer version will refuse to decrypt it with this context later.
* If the other plugin is removed or updated, the version in use changes. An older version cannot read every value of a newer one: version 3.1.0 cannot read what this version writes with a context, with an OpenSSL cipher without AEAD, or with OpenSSL and a self-chosen key from an environment or server variable.

Classes of two versions can still meet in two cases:

* The `Crypt` class in use is the one of version 3.1.0. It did not load the other classes itself yet, so it takes them from whichever copy the autoloader offers - also from a newer one. They work together. That `Crypt` class ignores a context, and a key outside of a configured `force_place` is not found.
* Another plugin loaded a class of its copy before the first `Crypt` object is used, e.g. a method of its own which extends `Method_Base`. The autoloader decides about the remaining classes then. This can end in a fatal error.

You can check which copy is in use, right where you create the `Crypt` object:

```
$file = (string) ( new \ReflectionClass( \CryptForWordPress\Crypt::class ) )->getFileName();

if ( ! str_starts_with( wp_normalize_path( $file ), wp_normalize_path( plugin_dir_path( __FILE__ ) ) ) ) {
    // the copy of another plugin or theme is in use.
}
```

`__FILE__` has to be a file in the main directory of your plugin.

## Give your plugin its own copy

The reliable solution is to change the namespace of the package for your plugin while building it. Your plugin then uses `MyPlugin\Dependencies\CryptForWordPress\Crypt`, which no other plugin has.

[Strauss](https://github.com/BrianHenryIE/strauss) does this for WordPress plugins. Install it as described there and add to your `composer.json`:

```
"extra": {
    "strauss": {
        "target_directory": "vendor-prefixed",
        "namespace_prefix": "MyPlugin\\Dependencies\\",
        "classmap_prefix": "MyPlugin_",
        "delete_vendor_packages": true
    }
}
```

After running Strauss, load its autoloader in your plugin and use the class with its new namespace:

```
require_once __DIR__ . '/vendor-prefixed/autoload.php';

use MyPlugin\Dependencies\CryptForWordPress\Crypt;
```

Things to know:

* Nothing this package saves depends on the namespace. Existing keys and encrypted values stay readable after the switch.
* Methods and places of your own, added via the filters `{slug}_crypt_methods` and `{slug}_crypt_places`, have to extend the classes with the new namespace.
* If your plugin also loads `vendor/autoload.php`: in a development installation Strauss makes the original class names available there as aliases - with them the copy would be shared again. It does not if the dependencies have been installed with `composer install --no-dev`. Build the version you release that way.
* [PHP-Scoper](https://github.com/humbug/php-scoper) is an alternative to Strauss. It has to be configured to leave the classes and functions of WordPress untouched.
