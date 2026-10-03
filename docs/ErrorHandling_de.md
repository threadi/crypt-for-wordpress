# Handhabung von Fehlern

_Crypt for WordPress_ bietet mehrere Möglichkeiten an, um aufgetretene Fehler zu erfassen.

## Vom Object abfragen

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

This is an WP_Error object, which will contain any error happened during the request.

Übergibst du `encrypt()` oder `decrypt()` einen Kontext, nennen deren Fehler zum Wert ihn in ihren Daten als `context` - so erkennst du, welcher deiner Werte betroffen ist. Es ist der Kontext, der bei diesem Aufruf angegeben wurde. Fehler zum Schlüssel oder zur Konfiguration, etwa `no_key_available`, betreffen keinen einzelnen Wert und haben keinen solchen Eintrag: Lies ihn mit `$data['context'] ?? ''`. Da er auf diesem Weg in Logs landen kann: Nimm nie etwas Geheimes in den Kontext auf.

## Per Hook abfragen

Hier werden einzelne Fehler übergeben.

Beispiel:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Dein Handling für Fehler.
}
add_action( 'crypt_for_wordpress_demo_error', 'cfwpd_crypt_errors', 10, 3 ); 
```