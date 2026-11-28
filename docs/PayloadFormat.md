# The format of encrypted values

Since version 4.3.0, every value encrypted with OpenSSL or Sodium begins with a header. It names the version of the format and the key the value has been encrypted with:

| Bytes | Content                                                                                       |
|-------|-----------------------------------------------------------------------------------------------|
| 3     | Marker of the format: `0xF3`, `C`, `W`                                                        |
| 1     | Version of the format: `1`                                                                    |
| 1     | Flags: `1` = bound to its site, see below                                                     |
| 8     | ID of the key: its fingerprint, as in the option `{slug}_crypt_key_id_{method}`               |

The method follows:

* OpenSSL with AEAD (the default AES-256-GCM, ChaCha20-Poly1305): IV, tag, ciphertext
* OpenSSL without AEAD (e.g. AES-256-CBC): IV, HMAC, ciphertext - with keys derived for this format only
* Sodium: the algorithm (one byte), nonce, ciphertext

The whole value is base64 encoded, as before. It is 13 bytes longer than in the format of version 4.2.0 - about 18 characters.

The ID of the key is no secret. It cannot be used to decrypt anything, and it is in the database anyway.

## What it is good for

**A value of another key is reported as such.** If the value names another key than the one in use, `decrypt()` reports `key_mismatch` instead of a general error of the method. Its data holds `key_id` (the key of the value) and `current_key_id` (the key in use). To find the key again, compare `key_id` with the option `{slug}_crypt_key_id_{method}` in your backups of the database: the backup, in which it matches, has been made while that key was in use. The key itself is in the backup of the files from that time - in the `wp-config.php` or the Must-Use plugin, see [What happens if the key is lost](KeyProtection.md).

**The format is authenticated together with the value.** The header, the algorithm of Sodium, the [context](Context.md) and - if requested - the site are additional data of the encryption. None of them can be changed without the value failing to decrypt. A value cannot be passed off as one in the format of version 4.2.0 by removing its header, and its key ID cannot be replaced.

**A value of a newer version is recognized.** A later version of this package can change the format. A value with a version or flags this version does not know is reported as `payload_version_unknown` - it has been written by a newer copy of this package, see [Using the package in several plugins](MultiplePlugins.md).

## Existing values

Values of version 4.2.0 and older are read as before, in every format. They are not changed: they get the new format once your plugin encrypts and saves them again. As long as they have no header, a value of another key is reported with the error of the method, e.g. `sodium_decrypt_error`. `reencrypt()` moves a value into the current format, see [Rotating the key](KeyRotation.md).

After a [rotation](KeyRotation.md) a value, which names the previous key, is decrypted with it - and not reported as `key_mismatch`, until the previous key is discarded.

## Settings

Both settings belong to the configuration of the Crypt object, not to the one of a method:

```
$crypt->set_config(
    array(
        'bind_site'     => true,  // bind new values to the site they are encrypted on.
        'legacy_format' => false, // true to write new values in the format of version 4.2.0.
    )
);
```

### Binding a value to its site

With `'bind_site' => true`, new values are bound to the ID of the site they are encrypted on (`get_current_blog_id()`). On another site they are not decrypted, even with the same key and the same context.

This matters on a multisite network, if the key belongs to the whole network - in the `wp-config.php`, the Must-Use plugin or a variable of the environment or the server. A value copied from the database table of one site into the one of another site is not decrypted there then. With a key per site - in the places `database` and `secrets_api` - this is the case anyway. On a single site it changes nothing.

Use it only if values never move to another site on purpose. They cannot be decrypted after:

* a site has been duplicated, or exported and imported into another site,
* a site of a network has been moved into a single installation or another network - it gets another ID there.

Whether a value is bound is part of the value itself:

* Values encrypted before the setting has been enabled are not bound. They are decrypted on every site, until your plugin encrypts and saves them again.
* Values encrypted with the setting stay bound, also after the setting has been removed.

### The format of version 4.2.0

With `'legacy_format' => true`, new values are written without the header, in the format of version 4.2.0. Versions before 4.3.0 cannot read the new format. Use the setting for a transition, if they may have to read your values:

* other plugins of the website ship an older copy of this package, and your plugin cannot have a copy of its own, see [Using the package in several plugins](MultiplePlugins.md),
* your plugin may be downgraded to a version, which ships an older copy of this package.

Values of both formats are read in any case. Remove the setting once the reason is gone: the values written in the meantime keep their format until they are saved again.

`'bind_site'` needs the new format. With both settings nothing is encrypted, and `bind_site_not_possible` is reported - a value is never written without a binding requested for it.

## Errors

| Code                      | Meaning                                                                                                                         |
|---------------------------|---------------------------------------------------------------------------------------------------------------------------------|
| `key_mismatch`            | The value has been encrypted with another key than the one in use. The data holds `key_id`, `current_key_id`, `method` and `context`. |
| `payload_version_unknown` | The value has been written by a newer version of this package, in a format this version cannot read. The data holds `version` and `flags`. |
| `bind_site_not_possible`  | `'bind_site'` and `'legacy_format'` are both set. Nothing is encrypted.                                                          |

## Methods of your own

A method of your own, added via the filter `{slug}_crypt_methods`, writes the format it has always written. Nothing changes for it.
