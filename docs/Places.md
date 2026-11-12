# Places

The key used for encryption can be stored in various locations. The only important thing is that it is loaded at runtime so that the content can actually be encrypted and decrypted.

## Selection

Without further configuration, when first loaded in a WordPress environment, the _Crypt for WordPress_ library attempts to store the key generated at that moment in the following locations:

1. first in the **wp-config.php** file
2. if this file is not writable, a Must-Use plugin is generated and stored
3. if that also fails, the key is saved as secret with the [Secrets API of WordPress](places/SecretsApi.md) (WordPress 7.2 or newer). If `WP_SECRETS_KEY` is defined or a drop-in of the hosting is used, this comes before the Must-Use plugin
4. if that also fails, the key is stored in the database. As the encrypted data resides there as well, this is reported as error `insecure_place_database`. Set `'block_database' => true` in the configuration to prevent it
5. if the database is blocked, the key is derived from the WordPress salts. This is reported as error `insecure_place_wordpress_salts`. Set `'block_salts' => true` in the configuration to prevent it. A salt which is still the placeholder of wp-config-sample.php is never used, reported as `wordpress_salts_placeholder`
6. if no place is left, no key is stored and nothing is encrypted: `encrypt()` returns an empty string

Whether a place is usable is only estimated beforehand - e.g. by whether a directory is writable. If saving fails all the same (a full disk, `open_basedir`, a filesystem accessed via FTP), the key is saved in the next usable place of this order. This is reported as warning `key_saved_in_fallback_place` - and, if it ends up in the database, as `insecure_place_database` as well. With the next requests it is found there (warning `key_in_inactive_place`). Places which cannot save anything (environment and server variable, WordPress salts) are never used for this. If a place is forced with `force_place`, there is no fallback.

### Hint

By default, the key is stored in **wp-config.php**—this works without any additional configuration and is enough for most purposes.

If you would like to keep the key separate from your database credentials, you can alternatively store it in a server or environment variable. This requires some hosting configuration - ask you hosting support about the configuration.

## List of supported places

* the **wp-config.php** file
* [a Must-Use plugin](places/MuPlugin.md)
* [the Secrets API of WordPress](places/SecretsApi.md)
* [a custom file](places/CustomFile.md)
* [a server environment variable](places/ServerVariable.md)
* [an environment variable](places/EnvironmentVariable.md)
* the database (not recommended, see above)
* the WordPress salts (not recommended, see above)

## Custom Locations

You can use a hook to add your own locations. The hook consists of your plugins slug followed by "_places".

Example:
```
function cfwpd_place( array $places ): array {
 $places[] = ‘\YourNameSpace\MyPlace’;
 return $places;
}
add_filter( ‘crypt_for_wordpress_demo_places’, ‘cfwpd_place’ )
```

The class you create this way must extend \CryptForWordPress\Place_Base. Take a look at the other included classes for guidance on the structure.
