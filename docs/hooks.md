# Hooks

- [Actions](#actions)
- [Filters](#filters)

## Actions

### `{$slug}_crypt_error`

*Run tasks if an error is added to the list.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$code` | `string` | The error code.
`$message` | `string` | The message.
`$data` | `array` | Data for the error.

**Changelog**

Version | Description
------- | -----------
`2.0.0` | Available since 2.0.0.

Source: [src/Crypt.php](../src/Crypt.php), [line 1456](../src/Crypt.php#L1456-L1465)

### `{$slug}_crypt_key_rotated`

*Run tasks after the key has been replaced with rotate_key() or
rollback_key_rotation().*

The fingerprints are no secrets: they cannot be used to decrypt
anything. Use this e.g. to start encrypting your values again with
reencrypt().

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$old_key_id` | `string` | The fingerprint of the former key.
`$new_key_id` | `string` | The fingerprint of the new key.
`$method` | `string` | The name of the method.

**Changelog**

Version | Description
------- | -----------
`4.3.0` | Available since 4.3.0.

Source: [src/Method_Base.php](../src/Method_Base.php), [line 2143](../src/Method_Base.php#L2143-L2157)

### `{$slug}_wp_config_written`

*Run tasks after wp-config.php has been written.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$path` | `string` | The path to the wp-config.php.

**Changelog**

Version | Description
------- | -----------
`3.0.0` | Available since 3.0.0.

Source: [src/Places/WpConfig.php](../src/Places/WpConfig.php), [line 309](../src/Places/WpConfig.php#L309-L315)

## Filters

### `{$slug}_crypt_constant`

*Filter the name of the constant.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$constant` | `string` | The name of the constant.

**Changelog**

Version | Description
------- | -----------
`1.1.2` | Available since 1.1.2.

Source: [src/Method_Base.php](../src/Method_Base.php), [line 394](../src/Method_Base.php#L394-L400)

### `{$slug}_crypt_constant`

*Filter the name of the constant.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$constant` | `string` | The constant name.

**Changelog**

Version | Description
------- | -----------
`1.1.2` | Available since 1.1.2.

Source: [src/Methods/Sodium.php](../src/Methods/Sodium.php), [line 283](../src/Methods/Sodium.php#L283-L289)

### `{$slug}_crypt_database_option_name`

*Filter the name of the option in the database.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$option_name` | `string` | The option name.

**Changelog**

Version | Description
------- | -----------
`2.1.0` | Available since 2.1.0.

Source: [src/Places/Database.php](../src/Places/Database.php), [line 65](../src/Places/Database.php#L65-L71)

### `{$slug}_crypt_methods`

*Filter the available crypt-methods.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$methods` | `array<int,string>` | List of methods.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [src/Crypt.php](../src/Crypt.php), [line 831](../src/Crypt.php#L831-L837)

### `{$slug}_crypt_places`

*Filter the available places.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$places` | `array<int,string>` | List of methods.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [src/Crypt.php](../src/Crypt.php), [line 1140](../src/Crypt.php#L1140-L1146)

### `{$slug}_crypt_secrets_api_name`

*Filter the name of the secret holding the key.*

Changing it later is like a lost key: the secret saved under the
former name is not found anymore.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$secret_name` | `string` | The name of the secret ("namespace/key").
`$this->get_constant()` |  | 

**Changelog**

Version | Description
------- | -----------
`4.1.0` | Available since 4.1.0.

Source: [src/Places/SecretsApi.php](../src/Places/SecretsApi.php), [line 240](../src/Places/SecretsApi.php#L240-L250)

### `{$slug}_wp_config_name`

*Filter to change the filename of the used wp-config.php without its extension .php.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$wp_config_php` | `string` | The filename.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [src/Places/WpConfig.php](../src/Places/WpConfig.php), [line 330](../src/Places/WpConfig.php#L330-L336)

### `{$slug}_wp_config_path`

*Filter the path for the wp-config.php before we return it.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$wp_config_php_path` | `string` | The path.

**Changelog**

Version | Description
------- | -----------
`1.0.0` | Available since 1.0.0.

Source: [src/Places/WpConfig.php](../src/Places/WpConfig.php), [line 349](../src/Places/WpConfig.php#L349-L355)


<p align="center"><em>Generated by <a href="https://github.com/pronamic/wp-documentor">Pronamic WordPress Documentor</a></em></p>

