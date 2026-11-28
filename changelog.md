# Changelog

## [Unreleased]

### Added

- Added `encrypt_or_error()` and `decrypt_or_error()` on the Crypt object: they return the same string as `encrypt()` and `decrypt()` on success, and a `WP_Error` with the reasons otherwise. A failure can be told apart from a value without content this way. `encrypt()` and `decrypt()` are unchanged
- Added the setting `'sodium' => array( 'algorithm' => '...' )` to choose the algorithm of Sodium for new values: `xchacha20poly1305` (default), `aegis256`, `aes256gcm`, `chacha20poly1305` or `auto`
- Added the warning "sodium_algorithm_not_available": the configured algorithm is unknown or not supported by the server, XChaCha20-Poly1305 is used instead
- Added the conformance tests `CryptForWordPress\Testing\PlaceConformance` and `CryptForWordPress\Testing\MethodConformance` for places and methods of your own, see docs/Testing.md. The places and methods of this package are tested with them as well
- Added `get_place_by_name()` on the Crypt object
- Added `discard_kept_keys()` on the Crypt object: it removes the keys an uninstallation has kept, so a later installation can start over with a new key - e.g. if the kept secret cannot be read anymore after the WordPress salts have been changed. The error "secrets_api_unavailable" names such a kept key with `kept_key` in its data
- Added the workflow "Hook documentation", which updates docs/hooks.md on every pull request changing src/. The template bin/wp-documentor-markdown.php sorts the hooks by name, so their order does not depend on the file system
- Added tests with WordPress 6.9 and 7.2 for the key kept during an uninstallation, for the autoload of the place "database" and for the algorithms of Sodium
- Added the versioned payload format: every value encrypted with OpenSSL or Sodium begins with a header, which names the version of its format and the ID of its key - the fingerprint already remembered in `{slug}_crypt_key_id_{method}`. The header is authenticated together with the value and its context, so a value cannot be passed off as one of an older format. See docs/PayloadFormat.md
- Added the error "key_mismatch": a value has been encrypted with another key than the one in use, with both key IDs in its data - instead of a general error of the method
- Added the error "payload_version_unknown": a value has been written by a newer version of this package, in a format this version cannot read
- Added the setting `'bind_site' => true`: new values are bound to the ID of the site they are encrypted on, and are not decrypted on another site of a multisite network
- Added the setting `'legacy_format' => true`: new values are written in the format of version 4.2.0, e.g. as long as other plugins ship an older copy of this package. Together with `'bind_site'` nothing is encrypted, and "bind_site_not_possible" is reported
- Added test vectors of the versioned payload format
- Added tests of Sodium with the sodium_compat of WordPress - in a PHP process without the functions of the extension, and in a test run without the extension on GitHub
- Added the rotation of the key: `rotate_key()` on the Crypt object replaces the key of the active method by a new one, saved in the active place only. The former key is kept as previous key, encrypted with the new one, in the option `{slug}_crypt_previous_key_{method}`, which is not loaded with every request. `decrypt()` reads the values of both keys, until `discard_previous_keys()` is called. Nothing of this happens by itself: your plugin starts the rotation and moves its values. See docs/KeyRotation.md
- Added `reencrypt()` on the Crypt object: it returns a value encrypted again with the key in use and the current settings - if it has been encrypted with the previous key, in the format of a version before 4.3.0 or with other settings like `'bind_site'`. Otherwise it returns the value as it is
- Added `rollback_key_rotation()` on the Crypt object: the previous key is used again, and the key in use becomes the previous key
- Added `discard_previous_keys()` on the Crypt object
- Added the action `{slug}_crypt_key_rotated` with the fingerprints of the former and the new key, and the name of the method
- Added the errors "key_rotation_failed", "key_rotation_not_possible" (with the reason in its data), "previous_key_not_saved" and "previous_key_unreadable"
- Added `generate_key()` and `get_hash_from_stored_key()` to methods: a method of your own supports the rotation, if it overrides `generate_key()`
- Added tests for the rotation, the rollback and interrupted rotations - also across real requests with every place, which can save a key

### Changed

- Optimized loading of methods to be compatible with Mozart or strauss
- Since WordPress 7.2, `uninstall()` keeps the key as secret with the Secrets API of WordPress ("{slug}/uninstalled-openssl", "{slug}/uninstalled-sodium"), encrypted by WordPress, and no longer in plain text in the database. Only if the Secrets API is not available, does not accept new secrets, does not confirm the secret or is blocked with `'block_secrets_api' => true`, the key is kept in the options as before. A later installation reads it from both, moves it into its place and removes the kept copy only afterward. A kept secret, which cannot be read, is reported as "secrets_api_unavailable", and no new key is generated
- The key kept in the database during an uninstallation is not loaded with every request anymore
- The place "database" saves the key in an option, which is not loaded with every request - so it does not end up in a persistent object cache. A key saved by an older version is changed accordingly, as soon as it is loaded (WordPress 6.4 or newer)
- Sodium encrypts new values with XChaCha20-Poly1305, as WordPress does for its secrets - not anymore with the fastest algorithm of the server. Values written with AEGIS-256 or AES-256-GCM could not be decrypted on a server without it. Values of every algorithm stay readable. `'algorithm' => 'auto'` restores the former behavior
- Every hook name starts with the variable `$slug` now, so wp-documentor can read it. The names of the hooks are unchanged
- The method Sodium is usable without the PHP extension "sodium": with the sodium_compat, which WordPress loads then. It writes the very same values, so values stay readable after a move to a server without the extension. `ext-sodium` is no longer required by composer.json, only suggested
- A value in the format of version 4.2.0, which cannot be decrypted with the key in use, is decrypted with the previous key of a rotation. The failed attempt is only reported, if both fail
- A rotation, whose request has ended before the fingerprint of the new key could be remembered, is not reported as "key_changed" by the next request
- If the key is lost ("key_missing") or the kept keys are discarded, the previous key of a rotation is removed as well: it has been encrypted with that key
- New values are written in the versioned payload format. Versions before 4.3.0 cannot read it - see `'legacy_format'`. Values of every older format stay readable and keep their format until they are encrypted again

### Fixed

- Fixed the names of three hooks in docs/hooks.md, which did not match the code: `{slug}_wp_config_written` and `{slug}_wp_config_name` were documented with "_crypt" in their name, the link targets and line numbers were outdated
- Fixed the name of the hook in the examples of the documentation for errors and places: `{slug}_crypt_error` and `{slug}_crypt_places`

## [4.2.0] - 12.11.2026

### Added

- Added the warning "key_saved_in_fallback_place": a new key, which could not be saved in the active place, has been saved in the next usable one
- Added `get_saved_place()` on the Crypt object
- Added tests for the fallback to the next place and for the file name of the Must-Use plugin

### Changed

- If saving a new key in the active place fails, it is saved in the next usable place of the order, instead of nothing being encrypted. Places, which cannot save anything, are never used for this, and a configured "force_place" leaves no fallback. If the key ends up in the database, `insecure_place_database` is reported. On a multisite network its fingerprint follows it, e.g. from the network to the site, if it ends up in the database

### Fixed

- Fixed the Must-Use plugin being written into subdirectories of the "must-use"-plugin-directory, if the slug contains a path - as for a file, which is not part of a plugin. The write failed, and WordPress would not have loaded the file from there anyway. The separators are replaced now. Every other slug keeps its file name

## [4.1.2] - 09.11.2026

### Fixed

- Fixed version number in release

## [4.1.1] - 09.11.2026

### Fixed

- Fixed Sodium detection of algorithm 

## [4.1.0] - 09.11.2026

### Added

- Added the place "secrets_api", which saves the key with the Secrets API of WordPress 7.2 or newer. It comes after the Must-Use plugin and before the database - or before the Must-Use plugin, if `WP_SECRETS_KEY` is defined or a drop-in of the hosting is used. It can be blocked with `'block_secrets_api' => true`
- Added the filter `{slug}_crypt_secrets_api_name` for the name of the secret
- Added `is_key_unavailable()` on places and `is_stored_key_unavailable()` on the Crypt object: a place can report that it holds a key, which it cannot hand over right now
- Added the errors "key_unavailable", "secrets_api_unavailable", "secrets_api_withheld", "secrets_api_read_only", "secrets_api_not_saved" and "wordpress_salts_placeholder"
- Added a section about the Secrets API of WordPress to the documentation of the security model
- Added test vectors: values of every format with fixed keys, which every later version has to decrypt
- Added tests across two real requests, for robustness against arbitrary input, and for many error cases
- Added the Composer scripts "test-multisite", "test-all", "test-coverage", "test-coverage-multisite" and "phpstan"
- Added WordPress 6.9, latest and nightly, and a multisite run of every combination to the tests on GitHub

### Changed

- No new key is generated as long as a place holds a key, which it cannot hand over right now - in no place. `key_unavailable` is reported instead of `key_missing`, and the fingerprint of the known key is kept

### Fixed

- Fixed the Must-Use plugin never being used, if the directory for "must-use"-plugins did not exist yet - as on most new installations. The key was saved in the next place instead. The directory is created now, if its parent directory is writable. A key saved in another place before is still found there
- Fixed the fallback ChaCha20-Poly1305 of Sodium, used on servers without XChaCha20-Poly1305: it used a nonce of 8 instead of 12 bytes, so nothing could be encrypted or decrypted with it. No value can have been written with it
- Fixed the WordPress salts being used as key, while the configured salt is still the placeholder of wp-config-sample.php ("put your unique phrase here"). The key derived from it is known to anybody. The place is not usable then, and `wordpress_salts_placeholder` is reported. Values written with such a key cannot be decrypted anymore - they never were protected

## [4.0.0] - 03.10.2026

### Added

- Added the warnings "slug_not_unique" and "slug_depends_on_path": a slug derived from a file without a plugin directory of its own - a single-file plugin, a Must-Use plugin, a theme - is shared with others or changes if the website is moved. Nothing else changes for these installations, the slug stays as it is
- Added an optional context as second parameter of `encrypt()` and `decrypt()`: a value bound to a context cannot be decrypted in another one, e.g. after its encrypted form has been copied into another field
- Added the errors "key_missing" and "key_changed": if the key of an installation is lost or replaced, this is reported once instead of happening silently. A new key is still generated automatically
- Added the errors "key_not_saved", "no_key_available", "openssl_unprotected_value", "context_not_supported" and the warning "key_in_inactive_place"
- Added the context to the data of the errors `encrypt()` and `decrypt()` report about a value - an empty string, if none is used
- Added `encrypt_with_context()` and `decrypt_with_context()` on methods
- Added documentation about what the package protects against, and about using it in several plugins of one website
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
- The Crypt class loads the other classes of this package from its own directory. If several plugins ship this package in different versions, the plugin whose Crypt class is in use gets the methods and places of the same version - a mix ended in a fatal error. Only if a class of another copy has been loaded before, the autoloader decides as it did
- The methods work with the Crypt class of version 3.1.0, if PHP has loaded that one from the copy of another plugin which has not been updated yet. They look for a missing key and check a written key themselves then - within the places a configured 'force_place' leaves, as that Crypt class does not hand out the others
- Plain texts and keys are marked as sensitive parameters of the functions of this package, so PHP replaces them in stack traces - in the error log, in debugging plugins and in error trackers. Such a stack trace cannot be serialized, like every one containing a password function of PHP itself

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