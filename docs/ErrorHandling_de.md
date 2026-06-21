# Handhabung von Fehlern

_Crypt for WordPress_ bietet mehrere Möglichkeiten an, um aufgetretene Fehler zu erfassen.

## Vom Object abfragen

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is an WP_Error object, which will contain any error happened during the request.

## Per Hook abfragen

Hier werden einzelne Fehler übergeben.

Beispiel:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Dein Handling für Fehler.
}
add_action( 'crypt_for_wordpress_demo_error', 'cfwpd_crypt_errors', 10, 3 ); 
```