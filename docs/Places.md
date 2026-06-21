# Places

The key used for encryption can be stored in various locations. The only important thing is that it is loaded at runtime so that the content can actually be encrypted and decrypted.

## Selection

Without further configuration, when first loaded in a WordPress environment, the _Crypt for WordPress_ library attempts to store the key generated at that moment in the following locations:

1. first in the **wp-config.php** file
2. if this file is not writable, a Must-Use plugin is generated and stored
3. if that also fails, no key is stored and no encryption will be used

### Hint

By default, the key is stored in **wp-config.php**—this works without any additional configuration and is enough for most purposes.

If you would like to keep the key separate from your database credentials, you can alternatively store it in a server or environment variable. This requires some hosting configuration - ask you hosting support about the configuration.

## List of supported places

* the **wp-config.php** file
* a Must-Use plugin
* a custom file
* a server environment variable
* an environment variable

## Custom Locations

You can use a hook to add your own locations. The hook consists of your plugins slug followed by "_places".

Example:
```
function cfwpd_place( array $places ): array {
 $places[] = ‘\YourNameSpace\MyPlace’;
 return $places;
}
add_filter( ‘crypt_for_wordpress_demo_places’, ‘cfwpd_place’ )
```

The class you create this way must extend \CryptForWordPress\Place_Base. Take a look at the other included classes for guidance on the structure.
