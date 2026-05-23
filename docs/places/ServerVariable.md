# Using a Server Environment Variable

This document describes how to use a server environment variable to store the key used to encrypt and decrypt text with this Composer package.

## Notes

With this method, you define the key used to encrypt text in WordPress yourself. It is not generated for you. We still recommend making it as complex as possible. It should consist of at least 12 characters, including letters, numbers, and special characters.

The entry in the environment variables consists of a key and a value.

## Prerequisites

Hosting that allows you to set server-side environment variables. These must be available in the PHP variable `$_SERVER`. If you have questions about this, contact your host’s support team.

Or use https://github.com/vlucas/phpdotenv to work with .env files. See the instructions there for setup.

## Usage

1. Set up a server-side variable with the key of your choice.
2. In the configuration for loading Crypt via `set_config()`, set the following values:
- “force_place” => ‘server_variable’
- “server_variable” => the key you used for the environment variable
3. Save the settings. They take effect immediately.
