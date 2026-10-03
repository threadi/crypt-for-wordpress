# What happens if the key is lost

Every value is encrypted with the key of your plugin or theme. If this key is lost, the values encrypted with it cannot be read anymore. _Crypt for WordPress_ cannot prevent that - but it does not let it happen unnoticed.

## How it works

When a key is used for the first time, a fingerprint of it is saved in the database (option `{slug}_crypt_key_id_{method}`). The fingerprint is not a secret and cannot be used to decrypt anything. It only allows telling two situations apart:

* **There never was a key.** A new one is generated and saved. Nothing is reported.
* **There was a key, but it cannot be found.** A new one is generated and saved as well, so your plugin keeps working without asking anybody. But the error `key_missing` is reported: every value encrypted so far is unreadable.

The same applies if a key is found which is not the one used so far: it is used, and `key_changed` is reported.

Both errors are reported **once** - in the request that notices it. From then on the new key is the known one. If you want to tell your users why their saved values have to be entered again, handle the error where it occurs, e.g. by saving a notice (see [Error Handling](ErrorHandling.md)).

Before a key is treated as missing, the other supported places are searched for it - not only the active one. A key that has been saved in the database or in a custom file is still found after the wp-config.php became writable. It is used from where it is, and the warning `key_in_inactive_place` is reported.

A new key is only used once it has been saved in its place. If saving fails, nothing is encrypted: `encrypt()` returns an empty string and `key_not_saved` is reported. A value encrypted with a key that only exists during one request could never be read again.

On a multisite network the fingerprint is saved where the key lives: for the whole network if the key is in a file or a variable, per site if it is in the database.

`uninstall()` keeps the key in the database, so a later installation can read the existing values again - wherever the key has been saved. If there is no key to keep, the fingerprint is removed as well.

## Errors

| Code                    | Meaning                                                                                                          |
|-------------------------|------------------------------------------------------------------------------------------------------------------|
| `key_missing`           | A key has been used before, but it could not be found. A new key has been generated. Reported once.               |
| `key_changed`           | A key has been found, which is not the one used so far. It is used from now on. Reported once.                    |
| `key_not_saved`         | A new key could not be saved in its place. It is not used.                                                        |
| `no_key_available`      | `encrypt()` or `decrypt()` has been called without a usable key. The cause has been reported before.              |
| `key_in_inactive_place` | Warning: the key has been found in another place than the active one. It is used from there.                      |

## When does this happen?

* The wp-config.php has been replaced: by a migration, a deployment or a restored backup.
* A database has been copied to another installation, e.g. to a staging system with its own wp-config.php.
* The WordPress salts have been changed while the place `wordpress_salts` is used.
* The place the key should be written to is not writable.

## Getting the values back

The values encrypted with the former key are not deleted. If the former key is restored - the original `define( '...-HASH', '...' );` line in the wp-config.php, or the generated Must-Use plugin - they are readable again. `key_changed` is reported once more for this change, and the values encrypted in the meantime with the other key are the ones that cannot be read then.
