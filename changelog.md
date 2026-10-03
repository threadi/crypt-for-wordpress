# Changelog

## [Unreleased]

### Added

- Added an optional context as second parameter of `encrypt()` and `decrypt()`: a value bound to a context cannot be decrypted in another one, e.g. after its encrypted form has been copied into another field
- Added the errors "key_missing" and "key_changed": if the key of an installation is lost or replaced, this is reported once instead of happening silently. A new key is still generated automatically
- Added the errors "key_not_saved", "no_key_available", "openssl_unprotected_value", "context_not_supported" and the warning "key_in_inactive_place"
- Added `encrypt_with_context()` and `decrypt_with_context()` on methods
- Added `is_saved()`, `get_stored_key()` and `is_network_wide()` on places, and `is_saved_in_place()` on the Crypt object

### Changed

- Breaking only for classes extending `Crypt` and overriding `encrypt()` or `decrypt()`: both got the optional parameter `$context`, which has to be added to the signature. Custom methods - extending `Method_Base` or one of the included methods - keep working unchanged
- A key that already exists is used even if no place is usable anymore, e.g. if the wp-config.php holding it cannot be written to
- OpenSSL generates a key with the default settings if 'hash_algorithm' or 'hash_type' are unknown, instead of working without a key. The error is still reported
- A key the place derives itself (WordPress salts) wins over a key an uninstallation left in the database
- The Must-Use plugin and the custom file keep the keys of other methods when the key of one method is saved
- OpenSSL uses a key, which is not in the format this package generates, as passphrase. This is the case for a self-chosen key from an environment or server variable. No error is reported for it anymore
- OpenSSL only accepts the full AEAD tag of 16 bytes when decrypting, instead of any length from 4 to 16 bytes. Every value this package has written uses the full tag
- A missing key is searched in the other supported places before a new one is generated, not only in the active one
- A new key is only used once it really has been saved in its place. Otherwise, nothing is encrypted
- The key an uninstallation left in the database is only removed there once the place really holds it
- The wp-config.php is only used as place if its directory is writable as well, otherwise the next place is used

### Fixed

- Fixed OpenSSL encrypting with an empty key - readable by anybody - whenever the stored key was not in the format this package generates: with a self-chosen key from an environment or server variable, or after changing 'hash_type' from "hash_pbkdf2" to "hash". Values written in this state stay readable and are reported as "openssl_unprotected_value" when decrypted. They are protected once they are encrypted again
- Fixed OpenSSL encrypting with an empty key if no key could be generated, e.g. with an unknown hash algorithm. Values written in this state stay readable and are reported as "openssl_unprotected_value" as well
- Fixed the key from an environment or server variable only being used if the variable was named like the constant of the method. With any other name a new key was generated with every request, and nothing could be decrypted afterward
- Fixed the silent generation of a new key although this installation already had one in another place, e.g. after the active place changed from the database to wp-config.php - every value encrypted before was unreadable afterward
- Fixed the generation of a new key with every request if it could not be saved
- Fixed `uninstall()` deleting the key of the database place instead of keeping it, if nothing had been encrypted or decrypted during the same request, or if another method has been uninstalled first
- Fixed a second Crypt object of the same plugin failing with Sodium during the request in which the key has been generated
- Fixed an unauthenticated IV in OpenSSL with ciphers without AEAD (e.g. AES-256-CBC): the first block of a value could be changed without being noticed. New values use a format which covers the IV, existing values stay readable and are protected once they are saved again
- Fixed OpenSSL decrypting values of ciphers without AEAD before verifying them, which reported invalid padding differently from an invalid HMAC
- Fixed the file lock for wp-config.php, which has never been taken as its lock file was expected to exist beforehand
- Fixed a moment without any wp-config.php while it is rewritten: it is now replaced atomically on local filesystems
- Fixed wrong exclusion of changelog.md from composer package

## [3.1.0] - 09.09.2026

### Added

- Added Plain as third method, which does not encrypt or decrypt anything

### Changed

- Log another possible Sodium error

## [3.0.2] - 17.08.2026

### Fixed

- Fixed a wrong version number in composer.json for release of this package

## [3.0.1] - 17.08.2026

### Fixed

- Fixed the missing check for non-aead decryption in OpenSsl to prevent warnings in PHP-log

## [3.0.0] - 02.08.2026

### Added

- Added "database" as the last fallback if neither wp-config.php nor mu-plugin are writable
- Added support for "WordPress Salts" as the key for the encryption
- Added error if no usable place could be found
- Added support for wp-config.php in the parent directory of the WordPress root (as wp-load.php does)
- Added multiple more PHP Unit Test to ensure the functionality of this package
- Added a documentation for the hooks this package provides

### Changed

- Set configuration for places like methods
- Optimized the generation of the "must-use"-plugin header
- Configuration option to block the usage of the database for the key
- Check for IV length in OpenSSL in one identical way
- Configured preset for file permissions for the places "custom file" and "must-use"-plugin
- Check for the given file permission and convert them to octal
- Check for available places with the given custom configuration
- Renamed some hooks
- Now required PHP 8.2 or newer

### Fixed

- Fixed check if the "must-use"-plugin-directory is writable
- Fixed a potential exception in the Sodium method if a falsy key is given
- Fixed a faulty file permission format for the "custom file" and the "must-use"-plugin methods

## [2.0.1] - 26.06.2026

### Fixed

- Fixed a potential error if the OpenSSL hash is empty

## [2.0.0] - 26.06.2026

### Added

- Added two new places for the key
-> an environment variable from .env-file
-> a server variable in the hosting
- Added the function debug() to return the actual configuration for debugging purposes
- Added documentation for each place and method 
- Added error handling

### Changed

- Load a given custom file before the method is loaded to embed its constants
- Replaced usage of wp_rand() with the more secure random_bytes()
- Hardening usage of OpenSSL and Sodium
- Lock wp-config.php if the file is changed to prevent errors through other PHP processes
- Move the entry in wp-config.php just above the ABSPATH-entry, with a fallback to the head of the file if it does not exist
- Sanitize plugin variables for the generated "must-use"-plugin

### Fixed

- Fixed the check if the parent "must-use"-directory is writable

## [1.2.1] - 07.04.2026

### Fixed

- Fixed a bug with the multiple usage of this library in one project

## [1.2.0] - 01.04.2026

### Added

- Added PHP Unit Tests
- Added code of conduct and contributing info

### Change

- Some new hooks

### Fixed

- Fixed check for a not existing "must-use"-plugin-directory, which will now create it

## [1.1.2] - 01.04.2026

### Change

- Change the default hash-algorithm from argon2 to sha256
- Optimized release build
- Some new hooks

## [1.1.1] - 29.03.2026

### Fixed

- Missing usage of hash-constant during uninstallation

## [1.1.0] - 29.03.2026

### Added

- Added new places handling where the token will be saved
- Added places for wp-config.php, mu-plugin and custom file

### Changed

- Optimized calling for Crypt-object with fewer options as possible
- set_method_config() is now set_config()
- Updated documentation

## [1.0.3] - 15.03.2026

### Changed

- Prevent direct access to encrypt and decrypt methods in crypt objects
- Prevent usage of this package outside of WordPress

## [1.0.2] - 15.03.2026

### Added

- Added configurations for OpenSSL and Sodium
- Added configuration for force one method by setting instead of PHP hook

## [1.0.0] - 14.03.2026

### Added

- Initial Release