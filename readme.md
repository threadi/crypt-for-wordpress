# Crypt for WordPress

This repository contains the source code for the Composer package “Crypt for WordPress”. It can be used in plugins or themes to encrypt strings.

## How it works

A hash key is generated for each plugin or theme, that is used for any encryption and decryption this plugin requests. This key is stored one of the supported places (like the file **wp-config.php**). As a result, it is not stored in the database where the encrypted data resides, keeping the key, and the data separate. This makes it more difficult for attackers to decrypt the data, as they would need both to be successful.

The **wp-config.php** file is primarily used for this purpose. If this file is not writable (that is the case with some hosting providers), a Must-Use plugin is generated and stored. Optionally, you can force the usage of a Must-Use plugin for each plugin or theme (see Settings below).

### Hint

Data encryption is not a silver bullet for protecting data. Projects that involve sensitive data should be secured through additional measures encryption. These include, for example, security plugins. This composer package is not the only solution for this, but it can help.

See: [What this package protects against - and what not](docs/SecurityModel.md)

## Demo

[This demo plugin](https://github.com/threadi/crypt-for-wordpress-demo) demonstrates how the encryption could be used.

## Use cases

* You allow users of your plugin to enter API credentials and want to store them securely in the database.
* You allow users to enter FTP credentials and want to store them securely in the database.
* You want users to enter JSON authentication data and store it securely.
* You collect personal data - for example, from job applicants or customers - on your website, and this data must be stored securely.

## Requirements

* [_composer_]([composer](https://getcomposer.org/)) to install this package.
* WordPress-plugin or theme to embed them in your project.

## Installation

1. `composer require threadi/crypt-for-wordpress`
2. Add the following code in your plugin or theme:

```
$crypt = new \CryptForWordPress\Crypt( __FILE__ );
```

### Parameters

#### set_config()

Set your custom configuration for the supported methods as array. This is optional, all options are optional.

Format:

```
array(
    'force_method' => 'openssl', // openssl or sodium.
    'force_place' => 'wpconfig', // one of the supported places.
    'openssl' => array(
        'hash_type' => 'hash_pbkdf2', // hash_pbkdf2 or hash.
        'hash_algorithm' => 'sha256' // see hints below.
        'force_mu_plugin => false, // true to force the usage of an MU-plugin to save the hashed key.
    )
    'sodium' => array(
        'hash_type' => 'sodium_crypto_aead_xchacha20poly1305_ietf_keygen', // one of: sodium_crypto_aead_xchacha20poly1305_ietf_keygen, sodium_crypto_secretbox_keygen, sodium_crypto_auth_keygen, sodium_crypto_generichash_keygen, sodium_crypto_kdf_keygen, random_bytes 
        'algorithm' => 'xchacha20poly1305', // see hints below.
    )
)
```

##### Hint about usage of ciphers

In March 2026 you should only use one of these ciphers:

* aes-256-gcm
* aes-256-cbc
* chacha20-poly1305

##### Hint about the algorithm of Sodium

New values are encrypted with XChaCha20-Poly1305, as WordPress does for its secrets. It needs neither special hardware nor a recent version of libsodium, so the values stay readable after a move to another server.

Other values: `aegis256` (libsodium 1.0.19 or newer), `aes256gcm` (only with hardware support for AES), `chacha20poly1305`, or `auto` for the fastest one the server supports - as versions up to 4.2.0 did. Values encrypted with `aegis256` or `aes256gcm` cannot be decrypted on a server without it. If the configured algorithm is not available, XChaCha20-Poly1305 is used and `sodium_algorithm_not_available` is reported.

Every value names the algorithm it has been encrypted with. Values of older versions stay readable, whatever is configured.

##### Hint about changes

If you change any of these settings, the changes will apply to newly encrypted strings. Strings that have already been encrypted will not be altered. Depending on the change, this could result in strings that were encrypted before the change no longer being decryptable.

## Usage

### Encrypt

To encrypt a plain string use:

```
$encrypted = $crypt->encrypt( 'My string to encrypt.' );
```

### Decrypt

To decrypt an encrypted string use:

```
$decrypted = $crypt->decrypt( 'My encrypted string to decrypt.' );
```

### Telling errors apart from empty values

`encrypt()` and `decrypt()` return an empty string on any error. To tell a failure apart from a value without content use:

```
$decrypted = $crypt->decrypt_or_error( 'My encrypted string to decrypt.' );
if ( is_wp_error( $decrypted ) ) {
 // could not be decrypted.
}
```

`encrypt_or_error()` works the same way for encrypting.

### Errors

Check and get errors:

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is an WP_Error object, which will contain any error happened during the request.

See also: [Error Handling](docs/ErrorHandling.md)

## Several plugins on one website

If other plugins or themes of a website ship this package as well, all of them share one version of it - not necessarily the one you ship. Give your plugin its own copy to prevent this.

See: [Using the package in several plugins](docs/MultiplePlugins.md)

## Uninstall

Use these code to remove the settings during uninstallation of your theme or plugin: 

```
$crypt = new \CryptForWordPress\Crypt( __FILE__ );
$crypt->uninstall();
```

The key is kept, so the values are readable again if the plugin is installed again: since WordPress 7.2 as secret with the Secrets API, otherwise in the database. See [Security model](docs/SecurityModel.md).

## Places

The key, used to encrypt and decrypt strings, are saved in one place.

This package support the following places:

| Place               | Default Order | Description                                                  |
|---------------------|---------------|--------------------------------------------------------------|
| CustomFile          |               | Use a custom file to store the key.                          |
| Database            | 4             | Save the key in the WordPress-own database - not recommended |
| EnvironmentVariable |               | Use an $_ENV variable in your hosting for the key.           |
| MuPlugin            | 2 (or 3)      | Let us create an custom "must-use"-plugin to save the key.   |
| SecretsApi          | 3 (or 2)      | Save the key as secret with the Secrets API of WordPress 7.2+. Before MuPlugin if `WP_SECRETS_KEY` or a drop-in is used. |
| ServerVariable      |               | Use an $_SERVER variable in your hosting for the key.        |
| WordPressSalts      | 5             | Use a WordPress Salts as key - not recommended. Never a salt which is still the placeholder. |
| WpConfig            | 1             | Let us save the key in your wp-config.php                    |

You can add more places as described [here](docs/Places.md). Test places and methods of your own with the [conformance tests](docs/Testing.md) of this package.

## Hooks

Several hooks are provided. These always have the slug of the plugin or theme that uses the package as a prefix, mostly followed by the abbreviation "crypt" to distinguish the hook from other hooks.

**Example:**

Plugin: `crypt-for-wordpress-demo`
Action: `crypt-for-wordpress-demo_crypt_error`

The list of hooks is available in the [Hooks](docs/hooks.md) documentation.

## Check for WordPress Coding Standards

## For package developers

### Initialize

`composer install`

### Run

`vendor/bin/phpcs .`

### Repair

`vendor/bin/phpcbf .`

## Analyse with PHPStan

`vendor/bin/phpstan analyse`

## Check for WordPress VIP Coding Standards

Hint: this check runs against the VIP-GO-platform which is not our target for this plugin. Many warnings can be ignored.

### Run

`vendor/bin/phpcs --extensions=php --ignore=*/vendor/*,*/tests/* --standard=WordPress-VIP-Go .`

## Generate documentation

`vendor/bin/wp-documentor parse src --format=markdown --template=bin/wp-documentor-markdown.php --relative=docs --output=docs/hooks.md`

This runs automatically on every pull request, which changes `src/`: the workflow "Hook documentation" commits the updated file. Every hook name has to start with a variable like `$slug` - wp-documentor cannot read a method call there. The template sorts the hooks by name, so their order does not depend on the file system.