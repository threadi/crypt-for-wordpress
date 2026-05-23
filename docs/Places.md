# Locations

The key used for encryption can be stored in various locations. The only important thing is that it is loaded at runtime so that the content can actually be encrypted and decrypted.

## Selection

Without further configuration, when first loaded in a WordPress environment, the Crypt for WordPress library attempts to store the key generated at that moment in the following locations:

1. first in the `wp-config.php` file
2. if this file is not writable, a Must-Use plugin is generated and stored
3. if that also fails, no key is stored

## List of Locations

* the `wp-config.php` file
* a Must-Use plugin
* a custom file
* a server environment variable
* an environment variable
