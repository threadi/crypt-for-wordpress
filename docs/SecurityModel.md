# What this package protects against - and what not

_Crypt for WordPress_ encrypts values before they are saved in the database. The key is kept somewhere else, e.g. in the `wp-config.php`. Whoever gets the database alone cannot read the values.

## Protected

* **The database in the wrong hands**: a dump, a backup, an export, access via phpMyAdmin or a vulnerability which allows reading the database (SQL injection) - in your plugin or in any other one. The values are unreadable without the key.
* **Changed values**: a value that has been changed in the database is not decrypted. `decrypt()` returns an empty string and reports an error. For values an older version wrote with a cipher without AEAD (not the default) this holds once they have been encrypted again.
* **Moved values**: with a [context](Context.md), an encrypted value copied into another field is not decrypted there: `$crypt->encrypt( $value, 'api_secret' )`.
* **Stack traces**: PHP replaces the text to encrypt and the key in stack traces, wherever they are arguments of a function of this package. So they do not end up in the error log or in an error tracker this way. Functions of WordPress, of your own code and of methods and places of your own are not covered by this.

All of this only holds as long as the key is not in the database as well:

* The place `database` is used if neither the `wp-config.php` nor a Must-Use plugin can be written. It is reported as `insecure_place_database`. Set `'block_database' => true` in the configuration to never use it. The WordPress salts are the next place then, reported as `insecure_place_wordpress_salts` and blocked with `'block_salts' => true`.
* `uninstall()` keeps the key in the database (options `{slug}_hash` and `{slug}_sodium_hash`), so the values are still readable if the plugin is installed again. Delete these options if you do not want that.

## Not protected

* **Code running in the same WordPress**: every plugin, every theme and everybody who is able to execute PHP there - through a vulnerability, or as administrator with the plugin editor. All of it runs in the same process with the same permissions as your plugin. It can read the key where it is stored and call `decrypt()` like your plugin does. No PHP library can prevent this, see [Using the package in several plugins](MultiplePlugins.md).
* **Files in the wrong hands**: a vulnerability which allows reading files, or a backup that contains the files and the database. The `wp-config.php` contains the key.
* **Values in use**: what your plugin decrypted is plain text again - in a variable, in a log your plugin writes, in the HTML of a settings page.

## What you can do

* Keep the key out of the database: do not force the place `database`, and react to the error `insecure_place_database`.
* Use a [context](Context.md) for every value.
* Only encrypt what your plugin has to read again. What it only has to compare - like a password - should be hashed instead.
* Do not log decrypted values and do not print them into forms. Show a placeholder instead.
* If other plugins on the same website may use this package as well: [give your plugin its own copy](MultiplePlugins.md).
