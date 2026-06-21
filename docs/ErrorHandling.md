# Handling Errors

_Crypt for WordPress_ offers several ways to capture errors that occur.

## Querying from the Object

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is a WP_Error object, which will contain any errors that occurred during the request.

## Querying via a Hook

Individual errors are passed here.

Example:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Your error handling code.
}
add_action( ‘crypt_for_wordpress_demo_error’, ‘cfwpd_crypt_errors’, 10, 3 ); 
```