# Binding a value to its context

All values of your plugin or theme are encrypted with the same key. Without further measures an encrypted value is valid wherever this key is used. Whoever is able to write to the database - through another vulnerability, or with limited access to it - could copy the encrypted API secret into a field whose content is displayed, and have it decrypted there.

A context prevents this. It states what the value belongs to:

```
$encrypted = $crypt->encrypt( $api_secret, 'api_secret' );
$decrypted = $crypt->decrypt( $encrypted, 'api_secret' );
```

The context is not encrypted and not part of the result. But the value can only be decrypted with the very same context. With any other one `decrypt()` returns an empty string and reports an error.

The context is optional. Without it everything works as before.

## What to use as context

Use something that identifies the purpose of the value and **never changes** for it:

* the name of the option or field: `'api_secret'`
* for several values of the same kind an additional, stable identifier: `'account:' . $account_uuid . ':token'`

Be careful with IDs WordPress assigns, like the post ID. They change when content is exported and imported, and a duplicated post gets a new one - the copied value could not be decrypted anymore. Only use them if your plugin controls how these values are copied.

A context that changes is like a lost key for this one value.

## Existing values

Values encrypted without a context cannot be decrypted with one. Migrate them when they are read:

```
$plain = $crypt->decrypt( $stored, 'api_secret' );

if ( '' === $plain ) {
    // not bound to its context yet: read it the old way and save it again.
    $plain = $crypt->decrypt( $stored );

    if ( '' !== $plain ) {
        update_option( 'my_api_secret', $crypt->encrypt( $plain, 'api_secret' ) );
    }
}
```

The first `decrypt()` reports an error for every value that has not been migrated yet. Remove the fallback once all values are migrated: as long as it exists, a value without context is still accepted everywhere.

## Errors

An error of `encrypt()` or `decrypt()` about the value names the context in its data, so you can tell which of your values is affected:

```
function my_plugin_crypt_errors( string $code, string $message, array $data ): void {
    error_log( $code . ' for ' . ( $data['context'] ?? '' ) );
}
add_action( 'my-plugin_crypt_error', 'my_plugin_crypt_errors', 10, 3 );
```

It is the context given to this call. If a value has been encrypted with another one, that one cannot be known. Without a context the entry is an empty string. Errors about the key or the configuration, like `key_missing`, do not belong to a single value and carry no context.

As it may end up in logs this way: never put anything secret into the context.

## Custom methods

A method of your own, added via the `{slug}_crypt_methods` filter, keeps working unchanged. It simply does not support a context: called with one, `encrypt()` and `decrypt()` of the Crypt object return an empty string and report the error `context_not_supported` - a context is never ignored silently.

To support it, override these two methods of `Method_Base` in your class:

```
public function encrypt_with_context( string $plain_text, string $context ): string
public function decrypt_with_context( string $encrypted_text, string $context ): string
```

The method `plain` ignores the context, as it does not encrypt anything.

Only if you extended the `Crypt` object itself and overrode `encrypt()` or `decrypt()` there, you have to add the second, optional parameter `string $context = ''` to your signature.
