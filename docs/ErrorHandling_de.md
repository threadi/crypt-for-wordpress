# Handhabung von Fehlern

_Crypt for WordPress_ bietet mehrere Möglichkeiten an, um aufgetretene Fehler zu erfassen.

## Einen Fehler von einem leeren Wert unterscheiden

`encrypt()` und `decrypt()` liefern bei jedem Fehler einen leeren String. An ihrem Ergebnis allein erkennst du also nicht, ob ein Wert keinen Inhalt hat oder nicht entschlüsselt werden konnte - etwa weil der Schlüssel fehlt.

Seit Version 4.3.0 gibt es dafür zwei weitere Methoden. Sie liefern bei Erfolg denselben String wie `encrypt()` und `decrypt()`, sonst einen `WP_Error`:

```
$value = $crypt->decrypt_or_error( $encrypted, 'api_key' );
if ( is_wp_error( $value ) ) {
 // Der Wert konnte nicht entschlüsselt werden. Behandle ihn nicht als leer.
 error_log( $value->get_error_code() . ': ' . implode( ', ', $value->get_error_codes() ) );
 return;
}

// $value ist der entschlüsselte String - ein leerer String, wenn der Wert keinen Inhalt hat.
```

- Der erste Code des `WP_Error` ist `encrypt_failed` bzw. `decrypt_failed`, mit dem Kontext des Werts in seinen Daten.
- Seine weiteren Codes sind die Fehler, die während dieses Aufrufs gemeldet wurden, etwa `key_unavailable`, `no_key_available` oder `sodium_decrypt_error`. Bei der ersten Verwendung in einem Request gehört dazu, was beim Laden des Schlüssels gemeldet wurde - es ist der Grund, warum der Wert nicht verarbeitet werden konnte.
- Ein leerer Wert ist kein Fehler: `decrypt_or_error( '' )` liefert einen leeren String. Ein Wert, dessen Inhalt leer ist, ist ebenfalls kein Fehler.
- Warnungen, die das Verschlüsseln nicht verhindern - etwa `insecure_place_database` oder `key_in_inactive_place` - machen aus dem Ergebnis keinen `WP_Error`. Sie werden wie bisher in `get_errors()` gesammelt.
- Jeder Fehler wird auch in `get_errors()` gesammelt und an den Hook unten übergeben - einmal. Der zurückgegebene `WP_Error` wird nicht noch einmal an den Hook übergeben.

`encrypt()` und `decrypt()` arbeiten wie bisher. Eine Methode, die du über den Filter `{slug}_crypt_methods` ergänzt hast und die keinen ihrer Fehler meldet, lässt sich nicht von einem Wert ohne Inhalt unterscheiden: `decrypt_or_error()` liefert für sie einen leeren String.

## Vom Object abfragen

```
if( $crypt->has_errors() ) {
 var_dump( $crypt->get_errors() );
} 
```

Das ist ein WP_Error-Objekt, das alle Fehler enthält, die während des Requests aufgetreten sind.

Übergibst du `encrypt()` oder `decrypt()` einen Kontext, nennen deren Fehler zum Wert ihn in ihren Daten als `context` - so erkennst du, welcher deiner Werte betroffen ist. Es ist der Kontext, der bei diesem Aufruf angegeben wurde. Fehler zum Schlüssel oder zur Konfiguration, etwa `no_key_available`, betreffen keinen einzelnen Wert und haben keinen solchen Eintrag: Lies ihn mit `$data['context'] ?? ''`. Da er auf diesem Weg in Logs landen kann: Nimm nie etwas Geheimes in den Kontext auf.

## Per Hook abfragen

Hier werden einzelne Fehler übergeben. Der Name des Hooks ist `{slug}_crypt_error`, mit dem Slug deines Plugins.

Beispiel für ein Plugin mit dem Slug `crypt-for-wordpress-demo`:

```
function cfwpd_crypt_errors( string $code, string $message, array $data ): void {
 // Dein Handling für Fehler.
}
add_action( 'crypt-for-wordpress-demo_crypt_error', 'cfwpd_crypt_errors', 10, 3 );
```
