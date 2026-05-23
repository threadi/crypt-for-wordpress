# Using EnvironmentVariables

This document describes how to use an environment variable to store the key for encrypting and decrypting text with this Composer package.

## Notes

With this method, you define the key used to encrypt text in WordPress yourself. It is not generated for you. We still recommend making it as complex as possible. It should consist of at least 12 characters, including letters, numbers, and special characters.

The entry in the environment variables consists of a key and a value.

## Prerequisites

Use of https://github.com/vlucas/phpdotenv to work with .env files in WordPress. See the instructions there for setup.

## Usage

1. In the configuration for loading Crypt via `set_config()`, set the following values:
- “force_place” => ‘environment_variable’
- “environment_variable” => the key you used for the environment variable
2. Save the settings. They take effect immediately.
