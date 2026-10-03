# Einen Wert an seinen Kontext binden

Alle Werte deines Plugins oder Themes werden mit demselben Schlüssel verschlüsselt. Ohne weitere Maßnahmen ist ein verschlüsselter Wert überall gültig, wo dieser Schlüssel verwendet wird. Wer in die Datenbank schreiben kann - über eine andere Sicherheitslücke oder mit eingeschränktem Zugriff - könnte das verschlüsselte API-Secret in ein Feld kopieren, dessen Inhalt angezeigt wird, und es dort entschlüsseln lassen.

Ein Kontext verhindert das. Er gibt an, wozu der Wert gehört:

```
$encrypted = $crypt->encrypt( $api_secret, 'api_secret' );
$decrypted = $crypt->decrypt( $encrypted, 'api_secret' );
```

Der Kontext wird nicht verschlüsselt und ist nicht Teil des Ergebnisses. Der Wert lässt sich aber nur mit exakt demselben Kontext entschlüsseln. Mit jedem anderen liefert `decrypt()` einen leeren String und meldet einen Fehler.

Der Kontext ist optional. Ohne ihn funktioniert alles wie bisher.

## Was als Kontext verwenden?

Verwende etwas, das den Zweck des Werts bezeichnet und sich für ihn **nie ändert**:

* den Namen der Option oder des Feldes: `'api_secret'`
* bei mehreren Werten derselben Art zusätzlich eine stabile Kennung: `'account:' . $account_uuid . ':token'`

Vorsicht bei IDs, die WordPress vergibt, etwa der Post-ID. Sie ändern sich beim Export und Import von Inhalten, und ein duplizierter Beitrag bekommt eine neue - der mitkopierte Wert ließe sich nicht mehr entschlüsseln. Verwende sie nur, wenn dein Plugin kontrolliert, wie diese Werte kopiert werden.

Ein Kontext, der sich ändert, ist für diesen einen Wert wie ein verlorener Schlüssel.

## Bestehende Werte

Ohne Kontext verschlüsselte Werte lassen sich mit Kontext nicht entschlüsseln. Migriere sie beim Lesen:

```
$plain = $crypt->decrypt( $stored, 'api_secret' );

if ( '' === $plain ) {
    // noch nicht an den Kontext gebunden: auf dem alten Weg lesen und neu speichern.
    $plain = $crypt->decrypt( $stored );

    if ( '' !== $plain ) {
        update_option( 'my_api_secret', $crypt->encrypt( $plain, 'api_secret' ) );
    }
}
```

Das erste `decrypt()` meldet für jeden noch nicht migrierten Wert einen Fehler. Entferne den Rückfall, sobald alle Werte migriert sind: Solange er existiert, wird ein Wert ohne Kontext weiterhin überall akzeptiert.

## Fehler

Ein Fehler von `encrypt()` oder `decrypt()` zum Wert nennt in seinen Daten den Kontext. So erkennst du, welcher deiner Werte betroffen ist:

```
function my_plugin_crypt_errors( string $code, string $message, array $data ): void {
    error_log( $code . ' für ' . ( $data['context'] ?? '' ) );
}
add_action( 'my-plugin_crypt_error', 'my_plugin_crypt_errors', 10, 3 );
```

Es ist der Kontext, der bei diesem Aufruf angegeben wurde. Wurde ein Wert mit einem anderen verschlüsselt, lässt sich dieser nicht ermitteln. Ohne Kontext ist der Eintrag ein leerer String. Fehler zum Schlüssel oder zur Konfiguration, etwa `key_missing`, betreffen keinen einzelnen Wert und enthalten keinen Kontext.

Da er auf diesem Weg in Logs landen kann: Nimm nie etwas Geheimes in den Kontext auf.

## Eigene Methoden

Eine eigene Methode, die du über den Filter `{slug}_crypt_methods` ergänzt hast, funktioniert unverändert weiter. Sie unterstützt nur keinen Kontext: Mit einem Kontext aufgerufen, liefern `encrypt()` und `decrypt()` des Crypt-Objekts einen leeren String und melden den Fehler `context_not_supported` - ein Kontext wird nie stillschweigend ignoriert.

Um ihn zu unterstützen, überschreibe in deiner Klasse diese beiden Methoden von `Method_Base`:

```
public function encrypt_with_context( string $plain_text, string $context ): string
public function decrypt_with_context( string $encrypted_text, string $context ): string
```

Die Methode `plain` ignoriert den Kontext, da sie nichts verschlüsselt.

Nur wenn du das `Crypt`-Objekt selbst erweitert und dort `encrypt()` oder `decrypt()` überschrieben hast, musst du deiner Signatur den zweiten, optionalen Parameter `string $context = ''` hinzufügen.
