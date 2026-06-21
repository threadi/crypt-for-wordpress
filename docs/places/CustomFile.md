# Using Custom Files

This document describes how to use a custom file as a storage location for the key used to encrypt and decrypt text with _Crypt for WordPress_.

## Important

Never use a file path derived from database options, user input, or REST requests. This could expose your project to potential security risks.

## Notes

The path to the file must be located within your hosting environment, but it can be outside the directory used by the website. The file must be writable.

The file at the specified path is created by _Crypt for WordPress_. You don't need to create it yourself.

When you delete the plugin, the file specified here will _not_ be deleted. If necessary, it must be removed manually afterward.

If necessary, set the file permissions so that only WordPress can read them via the server user account. This configuration varies depending on the hosting provider and is not further supported by _Crypt for WordPress_.

## Usage

1. In the configuration for loading _Crypt for WordPress_ via `set_config()`, set the following values:
- “force_place” => ‘customfile’
- “custom_file_path” => the absolute path to your custom file within your hosting environment
2. Save the settings. They take effect immediately.

## Options

* “file_permissions” => Set the desired file permissions for the file, e.g., 0600 so that it is readable and writable only by the current server user
