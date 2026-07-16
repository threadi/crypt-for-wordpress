# Changelog

## [Unreleased]

### Added

- Added database as last fallback if neither wp-config.php nor mu-plugin are writable
- Added error if no usable place could be found

### Changed

- Set configuration for places like methods
- Optimized the generation of the "must-use"-plugin header
- Configuration option to block the usage of the database for the key
- Check for IV length in OpenSSL in one identical way
- Configured preset for file permissions for the places "custom file" and "must-use"-plugin
- Check for given a file permissions and converting them to octal

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