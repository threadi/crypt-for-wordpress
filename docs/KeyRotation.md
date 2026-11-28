# Rotating the key

Since version 4.3.0 the key of your plugin can be replaced by a new one - e.g. if it may have been disclosed, if someone with access to the server has left, or if your security policy asks for it. The values encrypted so far stay readable during the change.

Nothing of this happens by itself. Your plugin starts the rotation, and only your plugin knows where its encrypted values are saved - so it moves them to the new key as well.

## In short

```
// 1. Replace the key. The former key is kept as previous key.
$result = $crypt->rotate_key();
if ( is_wp_error( $result ) ) {
    // nothing has changed, see "When a rotation is not possible".
    return;
}

// 2. Encrypt every value again with the new key - wherever your plugin saves them.
foreach ( my_plugin_get_encrypted_options() as $option_name ) {
    $value = get_option( $option_name );
    $moved = $crypt->reencrypt( $value, $option_name );
    if ( is_string( $moved ) && $moved !== $value ) {
        update_option( $option_name, $moved );
    }
}

// 3. Discard the previous key - only once every value has been moved.
$crypt->discard_previous_keys();
```

Until step 3, `decrypt()` reads the values of both keys. A plugin, which only does step 1, keeps working - its values just keep depending on the previous key.

## What happens

**`rotate_key()`** generates a new key for the active method and saves it in the active place - in the `wp-config.php`, the Must-Use plugin, as secret or in the database, wherever the key in use is saved. It is read back from there before it is used. The key in use is kept as previous key. Then the fingerprint of the new key is remembered as the one of this installation (option `{slug}_crypt_key_id_{method}`), and the action `{slug}_crypt_key_rotated` is fired. The method returns `true`, or a `WP_Error` - then nothing has changed.

**The previous key** is saved in the option `{slug}_crypt_previous_key_{method}` - encrypted with the new key, and not loaded with every request. On a multisite network it lives where the fingerprint of the key lives: as option of the network for a key of the whole network, as option of the site for a key of one site. It is not saved in the place of the key: not every place can hold a second key. It is as safe there as every other value: nobody can read it without the key in its place.

**`decrypt()`** decrypts a value of the previous key with the previous key:

* A value in the [versioned format](PayloadFormat.md) names its key, so the right key is used right away.
* A value in the format of version 4.2.0 names no key. It is decrypted with the key in use first, and with the previous key only if that fails. The failed attempt is not reported, unless both fail - then the errors of the key in use are reported.

**New values** are always encrypted with the new key.

**`reencrypt( $value, $context )`** returns the value encrypted again with the key in use, if it needs it:

* it has been encrypted with the previous key,
* it is in the format of a version before 4.3.0, which does not name its key,
* it has been encrypted with other settings of the format, e.g. before `'bind_site'` has been set.

Otherwise it returns the value as it is - so a value is only saved again, if `reencrypt()` returns another one. A value, which cannot be decrypted, is returned as `WP_Error` with the code `decrypt_failed`, like with `decrypt_or_error()`. Pass the same context as to `encrypt()`: the new value is bound to it as well. Empty values are returned as they are. You can use `reencrypt()` without a rotation as well, e.g. to move old values into the versioned format.

**`discard_previous_keys()`** removes the previous key of every method. Values, which are still encrypted with it, cannot be decrypted anymore: `decrypt()` reports `key_mismatch` for them. Afterward the key can be rotated again.

**`rollback_key_rotation()`** undoes a rotation: the previous key is used again, and the key in use becomes the previous key. Values encrypted since the rotation stay readable this way. The same conditions as for a rotation apply. A rollback can be rolled back as well.

## Your plugin has to do it

The package does not know where your values are - options, post meta, user meta, custom tables, transients. So it cannot move them, and it never rotates a key on its own: a rotation is only finished, once every value has been moved.

Start the rotation where an administrator can decide on it - e.g. a button in the settings of your plugin, or a WP-CLI command:

```
WP_CLI::add_command(
    'my-plugin rotate-key',
    static function (): void {
        $crypt  = my_plugin_get_crypt();
        $result = $crypt->rotate_key();
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() . ' ' . implode( ', ', $result->get_error_codes() ) );
        }

        my_plugin_reencrypt_all_values( $crypt );

        $crypt->discard_previous_keys();
        WP_CLI::success( 'The key has been rotated.' );
    }
);
```

If your plugin has many values, move them in batches, e.g. with Action Scheduler or a cron event, and discard the previous key after the last batch. Or move them as they are read:

```
$value     = get_post_meta( $post_id, 'my_plugin_iban', true );
$decrypted = $crypt->decrypt( $value, 'my_plugin_iban' );

// move it to the key in use, while it is read anyway.
$moved = $crypt->reencrypt( $value, 'my_plugin_iban' );
if ( is_string( $moved ) && $moved !== $value ) {
    update_post_meta( $post_id, 'my_plugin_iban', $moved );
}
```

With this approach only values, which are read, are moved - so do not discard the previous key before every value has been read once, or move the rest in a batch.

Use the action `{slug}_crypt_key_rotated` to start the moving, if the rotation is started somewhere else:

```
add_action(
    'my-plugin_crypt_key_rotated',
    static function ( string $old_key_id, string $new_key_id, string $method ): void {
        as_enqueue_async_action( 'my_plugin_reencrypt_batch', array( 0 ) );
    },
    10,
    3
);
```

The fingerprints are no secrets. They cannot be used to decrypt anything.

## When a rotation is not possible

A rotation is refused with a `WP_Error`, whose first code is `key_rotation_failed`. In most cases `key_rotation_not_possible` follows, with the reason in its data (`$result->get_error_data( 'key_rotation_not_possible' )['reason']`):

| Reason                    | Meaning                                                                                                                                                                                  |
|---------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `previous_key_exists`     | The previous key of the last rotation still exists - also if it cannot be read. A new rotation would make it unreadable. Move every value with `reencrypt()`, then call `discard_previous_keys()`. |
| `derived_key`             | The place derives the key itself - from the WordPress salts. Such a key cannot be replaced by the package. Change the salt and move the values yourself, or use another place.             |
| `key_not_in_active_place` | The key in use is not saved in the active place, e.g. because it is given by a variable of the environment or the server, or because it has been found in another place. A new key in the active place would not be the one loaded with the next request. Replace a key in a variable yourself. |
| `key_of_another_site`     | The key in use belongs to another site of the multisite network: the sites have been switched during this request. Rotate it during a request of that site.                             |
| `no_key`, `no_place`      | There is no key, or no place to save a new one in.                                                                                                                                        |
| `no_previous_key`         | `rollback_key_rotation()` has no previous key to use again.                                                                                                                               |
| `key_not_generated`       | No new key could be generated - e.g. by a method of your own, see below.                                                                                                                  |
| `not_supported`           | The method belongs to an older copy of this package, see [Using the package in several plugins](MultiplePlugins.md).                                                                     |

Other errors:

| Code                      | Meaning                                                                                                                                                         |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `key_not_saved`           | The place did not accept the new key. The key in use stays, and no previous key is left behind.                                                                 |
| `previous_key_not_saved`  | The key in use could not be kept as previous key. It has not been replaced.                                                                                     |
| `previous_key_unreadable` | The previous key could not be decrypted with the key in use - e.g. because its option has been changed. Values of the previous key cannot be decrypted then. Reported by `decrypt()`. |

## If the request ends during a rotation

The steps are done in an order, which never loses a key:

1. The key in use is kept as previous key, encrypted with the new key. If the request ends now, the next request finds a previous key, which is the key in use - and removes it quietly. Nothing has changed.
2. The new key is saved in the active place and read back. If this fails, the key in use stays, and the previous key is removed again.
3. The fingerprint of the new key is remembered. If the request ends before, the next request recognizes the new key by its previous key, and remembers its fingerprint - without reporting `key_changed`.

## Other requests at the same time

Every Crypt object of the request, which rotates the key, uses the new key afterward - also one, which has been used before. Other requests, which have loaded the key before the rotation, keep encrypting with the former key until they end. Their values are readable with the previous key, and `reencrypt()` moves them as well. So move the values after the rotation, not at the same time - and check them once more before you discard the previous key.

## Uninstallation

`uninstall()` keeps the previous key next to the key in use: a later installation reads the values of both again. `discard_kept_keys()` removes it with the kept keys, as it cannot be read without them. If the key gets lost (`key_missing`), the previous key is removed as well: it has been encrypted with the lost key.

## What a rotation protects against - and what not

A rotation makes a disclosed key useless for the values saved from then on - and for the values saved so far, once they have been moved and the previous key has been discarded. Until then the previous key can be read by anybody, who has the new key and the database. Discard it as soon as every value has been moved.

It does not help against copies made before: backups of the database and the files from the time of the former key can still be read with it.

## Several plugins and older versions

The previous key belongs to your plugin and its methods only. Versions before 4.3.0 know neither the [versioned format](PayloadFormat.md) nor a previous key: if an older copy of this package may be in use, see [Using the package in several plugins](MultiplePlugins.md).

## Methods of your own

A method of your own, added via the filter `{slug}_crypt_methods`, can be rotated if it overrides `generate_key()`: it returns a new key, as the method saves it in its place. If the method decodes its key in `init()`, it overrides `get_hash_from_stored_key()` as well. Without `generate_key()`, `rotate_key()` reports `key_not_generated`. The previous key is used with every method, which works with `get_hash()`.
