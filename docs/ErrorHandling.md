# Handling Errors

_Crypt for WordPress_ offers several ways to capture errors that occur.

## Querying from the Object

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is a WP_Error object, which will contain any errors that occurred during the request.

If you pass a context to `encrypt()` or `decrypt()`, their errors about the value name it in their data as `context` - so you can tell which of your values is affected. It is the context given to this call. Errors about the key or the configuration, like `no_key_available`, do not belong to a single value and have no such entry: read it with `$data['context'] ?? ''`. As it may end up in logs this way: never put anything secret into the context.

## Querying via a Hook

Individual errors are passed here.

Example:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Your error handling code.
}
add_action( ‘crypt_for_wordpress_demo_error’, ‘cfwpd_crypt_errors’, 10, 3 ); 
```