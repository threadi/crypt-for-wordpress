# Crypt for WordPress

This repository contains the source code for the Composer package “Crypt for WordPress”. It can be used in plugins or themes to encrypt strings.

## Requirements

* _composer_ to install this package.
* WordPress-plugin or theme to embed them in your project.

## Installation

1. ``composer require threadi/crypt-for-wordpress``
2. Add the following codes in your plugin or theme:

```
$crypt = new \Crypt\Crypt();
$crypt->set_slug( 'your-plugin-slug' ); // your plugin slug.
$crypt->set_plugin_file( __FILE__ ); // your plugin file.
```

## Usage

### Encrypt

To encrypt a plain string use:

```
$encrypted = $crypt->encrypt( 'My string to encrypt.' );
```

### Decrypt

To decrypt an encrypted string use:

```
$decrypted = $crypt->decrypt( 'My encrypted string to encrypt.' );
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