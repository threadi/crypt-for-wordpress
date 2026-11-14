# What happens if the key is lost

Every value is encrypted with the key of your plugin or theme. If this key is lost, the values encrypted with it cannot be read anymore. _Crypt for WordPress_ cannot prevent that - but it does not let it happen unnoticed.

## How it works

When a key is used for the first time, a fingerprint of it is saved in the database (option `{slug}_crypt_key_id_{method}`). The fingerprint is not a secret and cannot be used to decrypt anything. It only allows telling two situations apart:

* **There never was a key.** A new one is generated and saved. Nothing is reported.
* **There was a key, but it cannot be found.** A new one is generated and saved as well, so your plugin keeps working without asking anybody. But the error `key_missing` is reported: every value encrypted so far is unreadable.
* **There is a key, but its place cannot hand it over right now** - e.g., the [Secrets API](places/SecretsApi.md) of WordPress cannot reach the service of the hosting. Then no new key is generated, in no place: it would replace the key of every value encrypted so far. The error `key_unavailable` is reported, and nothing is encrypted or decrypted until the key is available again.

The same applies if a key is found which is not the one used so far: it is used, and `key_changed` is reported.

Both errors are reported **once** - in the request that notices it. From then on the new key is the known one. If you want to tell your users why their saved values have to be entered again, handle the error where it occurs, e.g. by saving a notice (see [Error Handling](ErrorHandling.md)).

Before a key is treated as missing, the other supported places are searched for it - not only the active one. A key that has been saved in the database or in a custom file is still found after the wp-config.php became writable. It is used from where it is, and the warning `key_in_inactive_place` is reported.

A new key is only used once it has been saved in its place. If saving in the active place fails, the next usable one is tried (warning `key_saved_in_fallback_place`, see [Places](Places.md)). If it fails everywhere, nothing is encrypted: `encrypt()` returns an empty string and `key_not_saved` is reported. A value encrypted with a key that only exists during one request could never be read again.

On a multisite network the fingerprint is saved where the key lives: for the whole network if the key is in a file or a variable, per site if it is in the database. This also applies to a key, which has ended up in a fallback place.

`uninstall()` keeps the key, so a later installation can read the existing values again - wherever the key has been saved. Since WordPress 7.2 it is kept as secret with the Secrets API of WordPress, otherwise in an option in the database, which is not loaded with every request (see [Security model](SecurityModel.md)). A later installation moves it into its place, and only then removes the kept copy. If the kept secret exists, but cannot be read, no new key is generated: `secrets_api_unavailable` and `key_unavailable` are reported. After the user of your plugin has confirmed it, `$crypt->discard_kept_keys()` starts over with a new key (see [Secrets API](places/SecretsApi.md#uninstallation)). If there is no key to keep, the fingerprint is removed as well.

## Errors

| Code                    | Meaning                                                                                                          |
|-------------------------|------------------------------------------------------------------------------------------------------------------|
| `key_missing`           | A key has been used before, but it could not be found. A new key has been generated. Reported once.               |
| `key_changed`           | A key has been found, which is not the one used so far. It is used from now on. Reported once.                    |
| `key_not_saved`         | A new key could not be saved in its place. It is not used.                                                        |
| `no_key_available`      | `encrypt()` or `decrypt()` has been called without a usable key. The cause has been reported before.              |
| `key_unavailable`       | A place holds the key, but cannot hand it over right now (e.g., the [Secrets API](places/SecretsApi.md)). No new key is generated, nothing is encrypted. |
| `key_in_inactive_place` | Warning: the key has been found in another place than the active one. It is used from there.                      |
| `key_saved_in_fallback_place` | Warning: a new key could not be saved in the active place and has been saved in the next usable one instead. The data holds `failed_places` and `saved_in`. |

## When does this happen?

* The wp-config.php has been replaced: by a migration, a deployment or a restored backup.
* A database has been copied to another installation, e.g. to a staging system with its own wp-config.php.
* The WordPress salts have been changed while the place `wordpress_salts` is used.
* The place the key should be written to is not writable.

## Getting the values back

The values encrypted with the former key are not deleted. If the former key is restored - the original `define( '...-HASH', '...' );` line in the wp-config.php, or the generated Must-Use plugin - they are readable again. `key_changed` is reported once more for this change, and the values encrypted in the meantime with the other key are the ones that cannot be read then.
