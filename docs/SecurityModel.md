# What this package protects against - and what not

_Crypt for WordPress_ encrypts values before they are saved in the database. The key is kept somewhere else, e.g. in the `wp-config.php`. Whoever gets the database alone cannot read the values.

## Protected

* **The database in the wrong hands**: a dump, a backup, an export, access via phpMyAdmin or a vulnerability which allows reading the database (SQL injection) - in your plugin or in any other one. The values are unreadable without the key.
* **Changed values**: a value that has been changed in the database is not decrypted. `decrypt()` returns an empty string and reports an error. For values an older version wrote with a cipher without AEAD (not the default) this holds once they have been encrypted again.
* **Moved values**: with a [context](Context.md), an encrypted value copied into another field is not decrypted there: `$crypt->encrypt( $value, 'api_secret' )`.
* **Stack traces**: PHP replaces the text to encrypt and the key in stack traces, wherever they are arguments of a function of this package. So they do not end up in the error log or in an error tracker this way. Functions of WordPress, of your own code and of methods and places of your own are not covered by this.

All of this only holds as long as the key is not in the database as well:

* Since WordPress 7.2, the key is saved with the [Secrets API of WordPress](places/SecretsApi.md) if the `wp-config.php` cannot be written. It is in the database then, but encrypted by WordPress with a key from the `wp-config.php` (`WP_SECRETS_KEY`, or the salts) or of the hosting. The database alone is not enough to read it.
* The place `database` is used if neither the `wp-config.php`, a Must-Use plugin nor the Secrets API can be used. It is reported as `insecure_place_database`. Set `'block_database' => true` in the configuration to never use it. The WordPress salts are the next place then, reported as `insecure_place_wordpress_salts` and blocked with `'block_salts' => true`. A salt, which is still the placeholder of wp-config-sample.php, is never used: the key derived from it would be known to anybody (`wordpress_salts_placeholder`).
* The place `database` saves the key in an option, which is not loaded with every request. So it does not end up in a persistent object cache like Redis or Memcached. A key saved by an older version is changed accordingly, as soon as it is loaded (WordPress 6.4 or newer).
* `uninstall()` keeps the key, so the values are still readable if the plugin is installed again. Since WordPress 7.2 it is kept as secret with the Secrets API (`{slug}/uninstalled-openssl` and `{slug}/uninstalled-sodium`), encrypted by WordPress. Only if the Secrets API is not available, does not accept new secrets or is blocked with `'block_secrets_api' => true`, it is kept in the database - in plain text, in the options `{slug}_hash` and `{slug}_sodium_hash`, which are not loaded with every request. A key, which an older version kept there, is moved into the secret with the next uninstallation. Delete the secrets or options - or call `$crypt->discard_kept_keys()` - if you do not want the key to be kept.

If a place holds the key, but cannot hand it over - e.g., the Secrets API cannot reach the service of the hosting -, no new key is generated. `key_unavailable` is reported and nothing is encrypted until the key is available again. A temporary outage never replaces the key of your values.

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
* On WordPress 7.2 or newer: recommend your users to define `WP_SECRETS_KEY` in the `wp-config.php`. Without it, the Secrets API depends on the WordPress salts.

## The Secrets API of WordPress or Crypt for WordPress?

Since version 7.2, WordPress has a [Secrets API](https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/) of its own. It protects against the same threats as this package - the database in the wrong hands, and not code in the same WordPress. Both have their purpose:

**Use the Secrets API directly** for single credentials of your plugin, if it requires WordPress 7.2: an API key, a token, the password for an external service.

```
wp_set_secret( 'my-plugin/api-key', $api_key );

$secret  = wp_get_secret( 'my-plugin/api-key' ); // WP_Secret, null (none) or WP_Error (unavailable).
$api_key = $secret instanceof WP_Secret ? $secret->reveal() : null;
if ( ! is_string( $api_key ) ) {
    // none saved yet, or not available right now - never save a new one in the latter case.
}
```

The hosting can keep these secrets in a key management service, old values remain available during a rotation, `WP_Secret` masks the value in logs and dumps, and the Site Health of WordPress reports secrets which cannot be decrypted.

**Use Crypt for WordPress** for anything else:

* many values or records, e.g. personal data of applicants or customers - the Secrets API saves every secret as its own option and is meant for credentials,
* values in post meta, user meta, term meta or custom tables of your plugin,
* values which are bound to what they belong to, with a [context](Context.md),
* plugins which support WordPress versions before 7.2.

**Both together**: since WordPress 7.2, this package saves its own key with the Secrets API, if the `wp-config.php` cannot be written - see [Places](Places.md).

Do not encrypt twice: never hand a value encrypted by this package to `wp_set_secret()` or `wp_import_option_as_secret()`. The secret would be the encrypted value, readable only with the key of this package. Decrypt it first, save the plain value as secret, and delete the option afterward.
