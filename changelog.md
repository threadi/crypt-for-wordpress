# Changelog

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

- Prevent direct access on encrypt and decrypt methods in crypt objects
- Prevent usage of this package outside of WordPress

## [1.0.2] - 15.03.2026

### Added

- Added configurations for OpenSSL and Sodium
- Added configuration for force one method by setting instead of PHP hook

## [1.0.0] - 14.03.2026

### Added

- Initial Release