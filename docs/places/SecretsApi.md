# Using the Secrets API of WordPress

This document describes how to use the Secrets API of WordPress (available since WordPress 7.2) as the place to store the key used to encrypt and decrypt text with _Crypt for WordPress_.

## Notes

The key is saved as a secret of WordPress. WordPress encrypts it with its own root key, which is protected by the constant `WP_SECRETS_KEY` in the `wp-config.php` - or, if it is not set, by the WordPress salts `LOGGED_IN_KEY` and `LOGGED_IN_SALT`. A hosting can replace this with a drop-in (`wp-content/secrets.php`), which keeps the secrets in a key management service, a vault or the control panel of the hosting.

The key is stored in the database - but encrypted. Whoever only gets the database cannot read it. Unlike the place `database`, which stores the key in plain text next to the encrypted values.

The name of the secret is `{slug}/{constant}`, reduced to lowercase letters, numbers, hyphens and underscores, e.g. `my-plugin/my-plugin-sodium-hash`. It can be changed with the filter `{slug}_crypt_secrets_api_name` - changing it later is like a lost key.

On a multisite network, every site has its own secret.

## When it is used

Without configuration, the place is used automatically, if the wp-config.php cannot be written:

* **before the Must-Use plugin**, if `WP_SECRETS_KEY` is defined or a drop-in is used,
* **after the Must-Use plugin, before the database**, if the Secrets API depends on the WordPress salts. Some security plugins change the salts on their own - the key would be unreadable afterward.

If the hosting manages the secrets itself and does not accept new ones from WordPress, the place is only used if it holds a key of your plugin already. The hosting has to store it with its own tools then.

## Fail closed

If the Secrets API holds the key, but cannot hand it over - the service behind it is unreachable, or `WP_SECRETS_KEY` or the salts have been changed - nothing is replaced:

* the error `secrets_api_unavailable` is reported,
* **no new key is generated**, also not in another place - instead `key_unavailable` is reported,
* `encrypt()` and `decrypt()` return an empty string until the key is available again.

Check the Site Health of WordPress in this case: it reports secrets which cannot be decrypted.

## Uninstallation

`uninstall()` keeps the key of every method as secret `{slug}/uninstalled-{method}`, e.g. `my-plugin/uninstalled-sodium` - whatever place has been used, also if another place is forced. A later installation moves it into its place and removes the secret afterward. Only `"block_secrets_api" => true`, a provider which does not accept new secrets or a secret, which cannot be read back, keep it in the database instead.

If the kept secret cannot be read on a later installation, this place fails closed as well: `secrets_api_unavailable` (with `kept_key` in its data) and `key_unavailable` are reported, and no new key is generated. This happens e.g. if the WordPress salts have been changed in the meantime, while `WP_SECRETS_KEY` is not used. Restoring the former salts or `WP_SECRETS_KEY` makes the key readable again.

If that is not possible, your plugin can offer to start over with a new key:

```
// only after the user has confirmed: the values encrypted so far stay unreadable.
$crypt->discard_kept_keys();
```

It removes the kept keys - as secret and in the database. The next usage generates a new key and reports `key_missing`.

## Usage

1. In the configuration for loading _Crypt for WordPress_ via `set_config()`, set the following parameter:
- "force_place" => "secrets_api"
2. Save the settings. They take effect immediately.

A key, which has been saved in another place before, is still found there and used (warning `key_in_inactive_place`). It is not moved.

## Options

* "block_secrets_api" => true, to never use this place

## Errors

| Code                      | Meaning                                                                                       |
|---------------------------|-----------------------------------------------------------------------------------------------|
| `secrets_api_unavailable` | The secret exists, but could not be read. Nothing is replaced. The reason is in the data.     |
| `secrets_api_withheld`    | The provider of the hosting holds the key, but does not hand it over to PHP.                  |
| `secrets_api_read_only`   | The hosting does not accept new secrets. Store the key with the tools of your hosting.         |
| `secrets_api_not_saved`   | The key could not be saved. The reason is in the data.                                        |
