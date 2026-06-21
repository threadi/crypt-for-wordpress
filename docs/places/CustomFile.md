# Using Custom Files

This document describes how to use a custom file as a storage location for the key used to encrypt and decrypt text with _Crypt for WordPress_.

## Important

Never use a file path derived from database options, user input, or REST requests. This could expose your project to potential security risks.

## Notes

The path to the file must be located within your hosting environment, but it can be outside the directory used by the website. The file must be writable.

When you delete the plugin, the file specified here will _not_ be deleted. If necessary, it must be removed manually afterward.

## Usage

1. In the configuration for loading _Crypt for WordPress_ via `set_config()`, set the following values:
- “force_place” => ‘customfile’
- “custom_file_path” => the absolute path to your custom file within your hosting environment
2. Save the settings. They take effect immediately.
