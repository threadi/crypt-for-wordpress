# Crypt for WordPress

This repository contains the source code for the Composer package “Crypt for WordPress”. It can be used in plugins or themes to encrypt strings.

## How it works

A hash key is generated for each plugin or theme, that is used for all encryption and decryption. This key is stored in the **wp-config.php** file or, if using a MU plugin, within the WordPress installation itself. As a result, it is not stored in the database where the encrypted data resides, keeping the key and the data separate. This makes it more difficult for attackers to decrypt the data, as they would need both to be successful.

The **wp-config.php** file is primarily used for this purpose. If this file is not writable (that is the case with some hosting providers), a Must-Use plugin is generated and stored. Optionally, you can force the usage of a Must-Use plugin for each plugin or theme (see Settings below).

### Hint

Data encryption is not a silver bullet for protecting data. Projects that involve sensitive data should be secured through additional measures in addition to encryption. These include, for example, security plugins. This Composer package is not the only solution for this, but it can help.

## Demo

[This demo plugin](https://github.com/threadi/crypt-for-wordpress-demo) demonstrates how the encryption could be used.

## Use cases

* You allow users of your plugin to enter API credentials and want to store them securely in the database.
* You allow users to enter FTP credentials and want to store them securely in the database.
* You want users to enter JSON authentication data and store it securely.
* You collect personal data - for example, from job applicants or customers - on your website, and this data must be stored securely.

## Requirements

* _composer_ to install this package.
* WordPress-plugin or theme to embed them in your project.

## Installation

1. ``composer require threadi/crypt-for-wordpress``
2. Add the following codes in your plugin or theme:

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
    'openssl' => array(
        'hash_type' => 'hash_pbkdf2', // hash_pbkdf2 or hash.
        'hash_algorithm' => 'sha256' // see hints below.
        'force_mu_plugin => false, // true to force the usage of an MU-plugin to save the hashed key.
    )
    'sodium' => array(
        'hash_type' => 'sodium_crypto_aead_xchacha20poly1305_ietf_keygen' // one of: sodium_crypto_aead_xchacha20poly1305_ietf_keygen, sodium_crypto_secretbox_keygen, sodium_crypto_auth_keygen, sodium_crypto_generichash_keygen, sodium_crypto_kdf_keygen, random_bytes 
    )
)
```

##### Hint about usage of ciphers

In March 2026 you should only use one of these ciphers:

* aes-256-gcm
* aes-256-cbc
* chacha20-poly1305

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

## Uninstall

Use these code to remove the settings during uninstallation of your theme or plugin: 

```
$crypt = new \CryptForWordPress\Crypt( __FILE__ );
$crypt->uninstall();
```

## Check for WordPress Coding Standards

### Initialize

`composer install`

### Run

`vendor/bin/phpcs --standard=ruleset.xml vendor/threadi/easy-directory-listing-for-wordpress/`

### Repair

`vendor/bin/phpcbf --standard=ruleset.xml vendor/threadi/easy-directory-listing-for-wordpress/`

## Analyse with PHPStan

`vendor/bin/phpstan analyse`