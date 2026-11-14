# Handling Errors

_Crypt for WordPress_ offers several ways to capture errors that occur.

## Telling a failure apart from an empty value

`encrypt()` and `decrypt()` return an empty string on any error. So you cannot tell from their result alone, whether a value has no content or could not be decrypted - e.g. because the key is missing.

Since version 4.3.0 there are two more methods for this. They return the same string as `encrypt()` and `decrypt()` on success, and a `WP_Error` otherwise:

```
$value = $crypt->decrypt_or_error( $encrypted, 'api_key' );
if ( is_wp_error( $value ) ) {
 // The value could not be decrypted. Do not treat it as empty.
 error_log( $value->get_error_code() . ': ' . implode( ', ', $value->get_error_codes() ) );
 return;
}

// $value is the decrypted string - an empty string, if the value has no content.
```

- The first code of the `WP_Error` is `encrypt_failed` or `decrypt_failed`, with the context of the value in its data.
- Its other codes are the errors reported during this call, e.g. `key_unavailable`, `no_key_available` or `sodium_decrypt_error`. On the first usage in a request this includes what has been reported while the key has been loaded - it is the reason the value could not be handled.
- An empty value is no error: `decrypt_or_error( '' )` returns an empty string. A value, whose content is empty, is no error either.
- Warnings, which do not prevent the encryption - e.g. `insecure_place_database` or `key_in_inactive_place` - do not turn the result into a `WP_Error`. They are collected in `get_errors()` as before.
- Every error is collected in `get_errors()` and passed to the hook below as well - once. The `WP_Error` returned is not passed to the hook again.

`encrypt()` and `decrypt()` work as before. A method you added via the filter `{slug}_crypt_methods`, which reports none of its errors, cannot be told apart from a value without content: `decrypt_or_error()` returns an empty string for it.

## Querying from the Object

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is a WP_Error object, which will contain any errors that occurred during the request.

If you pass a context to `encrypt()` or `decrypt()`, their errors about the value name it in their data as `context` - so you can tell which of your values is affected. It is the context given to this call. Errors about the key or the configuration, like `no_key_available`, do not belong to a single value and have no such entry: read it with `$data['context'] ?? ''`. As it may end up in logs this way: never put anything secret into the context.

## Querying via a Hook

Individual errors are passed here. The name of the hook is `{slug}_crypt_error`, with the slug of your plugin.

Example for a plugin with the slug `crypt-for-wordpress-demo`:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Your error handling code.
}
add_action( 'crypt-for-wordpress-demo_crypt_error', 'cfwpd_crypt_errors', 10, 3 );
```
