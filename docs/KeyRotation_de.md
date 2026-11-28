# Den Schlüssel rotieren

Seit Version 4.3.0 lässt sich der Schlüssel deines Plugins durch einen neuen ersetzen - etwa wenn er bekannt geworden sein könnte, wenn jemand mit Zugang zum Server gegangen ist oder wenn deine Sicherheitsrichtlinie es verlangt. Die bisher verschlüsselten Werte bleiben während des Wechsels lesbar.

Nichts davon passiert von selbst. Dein Plugin startet die Rotation, und nur dein Plugin weiß, wo seine verschlüsselten Werte gespeichert sind - also bringt es sie auch auf den neuen Schlüssel.

## Kurz gesagt

```
// 1. Den Schlüssel ersetzen. Der bisherige Schlüssel wird als vorheriger Schlüssel aufbewahrt.
$result = $crypt->rotate_key();
if ( is_wp_error( $result ) ) {
    // nichts hat sich geändert, siehe "Wenn eine Rotation nicht möglich ist".
    return;
}

// 2. Jeden Wert mit dem neuen Schlüssel neu verschlüsseln - wo auch immer dein Plugin ihn speichert.
foreach ( my_plugin_get_encrypted_options() as $option_name ) {
    $value = get_option( $option_name );
    $moved = $crypt->reencrypt( $value, $option_name );
    if ( is_string( $moved ) && $moved !== $value ) {
        update_option( $option_name, $moved );
    }
}

// 3. Den vorherigen Schlüssel verwerfen - erst, wenn jeder Wert umgezogen ist.
$crypt->discard_previous_keys();
```

Bis Schritt 3 liest `decrypt()` die Werte beider Schlüssel. Ein Plugin, das nur Schritt 1 ausführt, funktioniert weiter - seine Werte hängen dann eben weiter vom vorherigen Schlüssel ab.

## Was passiert

**`rotate_key()`** erzeugt einen neuen Schlüssel für die aktive Methode und speichert ihn am aktiven Speicherort - in der `wp-config.php`, im Must-Use-Plugin, als Secret oder in der Datenbank, eben dort, wo der verwendete Schlüssel gespeichert ist. Von dort wird er zurückgelesen, bevor er verwendet wird. Der verwendete Schlüssel wird als vorheriger Schlüssel aufbewahrt. Danach wird der Fingerabdruck des neuen Schlüssels als der dieser Installation gemerkt (Option `{slug}_crypt_key_id_{method}`), und die Action `{slug}_crypt_key_rotated` wird ausgelöst. Die Methode liefert `true` oder einen `WP_Error` - dann hat sich nichts geändert.

**Der vorherige Schlüssel** liegt in der Option `{slug}_crypt_previous_key_{method}` - verschlüsselt mit dem neuen Schlüssel und nicht bei jedem Request geladen. In einem Multisite-Netzwerk liegt er dort, wo der Fingerabdruck des Schlüssels liegt: als Option des Netzwerks für einen Schlüssel des ganzen Netzwerks, als Option der Site für einen Schlüssel einer Site. Er wird nicht am Speicherort des Schlüssels abgelegt: Nicht jeder Speicherort kann einen zweiten Schlüssel aufnehmen. Er ist dort so sicher wie jeder andere Wert: Ohne den Schlüssel an seinem Speicherort kann ihn niemand lesen.

**`decrypt()`** entschlüsselt einen Wert des vorherigen Schlüssels mit dem vorherigen Schlüssel:

* Ein Wert im [versionierten Format](PayloadFormat_de.md) nennt seinen Schlüssel, also wird sofort der richtige verwendet.
* Ein Wert im Format der Version 4.2.0 nennt keinen Schlüssel. Er wird zuerst mit dem verwendeten Schlüssel entschlüsselt und nur, wenn das scheitert, mit dem vorherigen. Der gescheiterte Versuch wird nicht gemeldet, außer beide scheitern - dann werden die Fehler des verwendeten Schlüssels gemeldet.

**Neue Werte** werden immer mit dem neuen Schlüssel verschlüsselt.

**`reencrypt( $value, $context )`** liefert den Wert mit dem verwendeten Schlüssel neu verschlüsselt, wenn er es braucht:

* er wurde mit dem vorherigen Schlüssel verschlüsselt,
* er liegt im Format einer Version vor 4.3.0 vor, das seinen Schlüssel nicht nennt,
* er wurde mit anderen Einstellungen des Formats verschlüsselt, etwa bevor `'bind_site'` gesetzt wurde.

Sonst liefert sie den Wert unverändert - ein Wert wird also nur dann neu gespeichert, wenn `reencrypt()` einen anderen liefert. Ein Wert, der sich nicht entschlüsseln lässt, wird wie bei `decrypt_or_error()` als `WP_Error` mit dem Code `decrypt_failed` geliefert. Übergib denselben Kontext wie an `encrypt()`: Der neue Wert wird ebenfalls daran gebunden. Leere Werte werden unverändert geliefert. Du kannst `reencrypt()` auch ohne Rotation verwenden, etwa um alte Werte ins versionierte Format zu bringen.

**`discard_previous_keys()`** entfernt den vorherigen Schlüssel jeder Methode. Werte, die noch mit ihm verschlüsselt sind, lassen sich danach nicht mehr entschlüsseln: `decrypt()` meldet für sie `key_mismatch`. Danach lässt sich der Schlüssel wieder rotieren.

**`rollback_key_rotation()`** macht eine Rotation rückgängig: Der vorherige Schlüssel wird wieder verwendet, und der verwendete Schlüssel wird zum vorherigen. Werte, die seit der Rotation verschlüsselt wurden, bleiben so lesbar. Es gelten dieselben Bedingungen wie für eine Rotation. Auch ein Rollback lässt sich zurückrollen.

## Dein Plugin muss es tun

Das Package weiß nicht, wo deine Werte liegen - Optionen, Post-Meta, User-Meta, eigene Tabellen, Transients. Darum kann es sie nicht umziehen, und es rotiert nie von sich aus einen Schlüssel: Eine Rotation ist erst abgeschlossen, wenn jeder Wert umgezogen ist.

Starte die Rotation dort, wo ein Administrator darüber entscheiden kann - etwa mit einem Button in den Einstellungen deines Plugins oder einem WP-CLI-Befehl:

```
WP_CLI::add_command(
    'my-plugin rotate-key',
    static function (): void {
        $crypt  = my_plugin_get_crypt();
        $result = $crypt->rotate_key();
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() . ' ' . implode( ', ', $result->get_error_codes() ) );
        }

        my_plugin_reencrypt_all_values( $crypt );

        $crypt->discard_previous_keys();
        WP_CLI::success( 'The key has been rotated.' );
    }
);
```

Hat dein Plugin viele Werte, ziehe sie in Paketen um, etwa mit dem Action Scheduler oder einem Cron-Event, und verwirf den vorherigen Schlüssel nach dem letzten Paket. Oder ziehe sie um, während sie gelesen werden:

```
$value     = get_post_meta( $post_id, 'my_plugin_iban', true );
$decrypted = $crypt->decrypt( $value, 'my_plugin_iban' );

// auf den verwendeten Schlüssel umziehen, wenn er ohnehin gelesen wird.
$moved = $crypt->reencrypt( $value, 'my_plugin_iban' );
if ( is_string( $moved ) && $moved !== $value ) {
    update_post_meta( $post_id, 'my_plugin_iban', $moved );
}
```

So werden nur Werte umgezogen, die gelesen werden - verwirf den vorherigen Schlüssel also nicht, bevor jeder Wert einmal gelesen wurde, oder ziehe den Rest in einem Paket um.

Mit der Action `{slug}_crypt_key_rotated` startest du den Umzug, wenn die Rotation an anderer Stelle gestartet wird:

```
add_action(
    'my-plugin_crypt_key_rotated',
    static function ( string $old_key_id, string $new_key_id, string $method ): void {
        as_enqueue_async_action( 'my_plugin_reencrypt_batch', array( 0 ) );
    },
    10,
    3
);
```

Die Fingerabdrücke sind keine Geheimnisse. Mit ihnen lässt sich nichts entschlüsseln.

## Wenn eine Rotation nicht möglich ist

Eine Rotation wird mit einem `WP_Error` abgelehnt, dessen erster Code `key_rotation_failed` ist. Meist folgt `key_rotation_not_possible` mit dem Grund in seinen Daten (`$result->get_error_data( 'key_rotation_not_possible' )['reason']`):

| Grund                     | Bedeutung                                                                                                                                                                               |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `previous_key_exists`     | Der vorherige Schlüssel der letzten Rotation existiert noch - auch wenn er sich nicht lesen lässt. Eine neue Rotation würde ihn unlesbar machen. Ziehe jeden Wert mit `reencrypt()` um und rufe dann `discard_previous_keys()` auf. |
| `derived_key`             | Der Speicherort leitet den Schlüssel selbst ab - aus den WordPress-Salts. So ein Schlüssel lässt sich vom Package nicht ersetzen. Ändere den Salt und ziehe die Werte selbst um, oder verwende einen anderen Speicherort. |
| `key_not_in_active_place` | Der verwendete Schlüssel ist nicht am aktiven Speicherort gespeichert, etwa weil er aus einer Variablen der Umgebung oder des Servers kommt oder an einem anderen Speicherort gefunden wurde. Ein neuer Schlüssel am aktiven Speicherort wäre nicht der, den der nächste Request lädt. Ersetze einen Schlüssel in einer Variablen selbst. |
| `key_of_another_site`     | Der verwendete Schlüssel gehört zu einer anderen Site des Multisite-Netzwerks: Während dieses Requests wurde die Site gewechselt. Rotiere ihn während eines Requests dieser Site.          |
| `no_key`, `no_place`      | Es gibt keinen Schlüssel oder keinen Speicherort, an dem ein neuer gespeichert werden könnte.                                                                                             |
| `no_previous_key`         | `rollback_key_rotation()` hat keinen vorherigen Schlüssel, der wieder verwendet werden könnte.                                                                                           |
| `key_not_generated`       | Es konnte kein neuer Schlüssel erzeugt werden - etwa von einer eigenen Methode, siehe unten.                                                                                             |
| `not_supported`           | Die Methode gehört zu einer älteren Kopie dieses Packages, siehe [Das Package in mehreren Plugins](MultiplePlugins_de.md).                                                               |

Weitere Fehler:

| Code                      | Bedeutung                                                                                                                                                         |
|---------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `key_not_saved`           | Der Speicherort hat den neuen Schlüssel nicht angenommen. Der verwendete Schlüssel bleibt, und es bleibt kein vorheriger Schlüssel zurück.                        |
| `previous_key_not_saved`  | Der verwendete Schlüssel konnte nicht als vorheriger Schlüssel aufbewahrt werden. Er wurde nicht ersetzt.                                                         |
| `previous_key_unreadable` | Der vorherige Schlüssel ließ sich mit dem verwendeten nicht entschlüsseln - etwa weil seine Option verändert wurde. Werte des vorherigen Schlüssels lassen sich dann nicht entschlüsseln. Wird von `decrypt()` gemeldet. |

## Wenn der Request während einer Rotation endet

Die Schritte laufen in einer Reihenfolge ab, die nie einen Schlüssel verliert:

1. Der verwendete Schlüssel wird als vorheriger Schlüssel aufbewahrt, verschlüsselt mit dem neuen. Endet der Request jetzt, findet der nächste Request einen vorherigen Schlüssel, der der verwendete ist - und entfernt ihn still. Nichts hat sich geändert.
2. Der neue Schlüssel wird am aktiven Speicherort gespeichert und zurückgelesen. Scheitert das, bleibt der verwendete Schlüssel, und der vorherige Schlüssel wird wieder entfernt.
3. Der Fingerabdruck des neuen Schlüssels wird gemerkt. Endet der Request vorher, erkennt der nächste Request den neuen Schlüssel an seinem vorherigen Schlüssel und merkt sich seinen Fingerabdruck - ohne `key_changed` zu melden.

## Andere Requests zur selben Zeit

Jedes Crypt-Objekt des Requests, der den Schlüssel rotiert, verwendet danach den neuen Schlüssel - auch eines, das vorher schon verwendet wurde. Andere Requests, die den Schlüssel vor der Rotation geladen haben, verschlüsseln bis zu ihrem Ende weiter mit dem bisherigen Schlüssel. Ihre Werte lassen sich mit dem vorherigen Schlüssel lesen, und `reencrypt()` zieht auch sie um. Ziehe die Werte also nach der Rotation um, nicht gleichzeitig - und prüfe sie noch einmal, bevor du den vorherigen Schlüssel verwirfst.

## Deinstallation

`uninstall()` bewahrt den vorherigen Schlüssel neben dem verwendeten auf: Eine spätere Installation liest die Werte beider wieder. `discard_kept_keys()` entfernt ihn zusammen mit den aufbewahrten Schlüsseln, denn ohne sie lässt er sich nicht lesen. Geht der Schlüssel verloren (`key_missing`), wird auch der vorherige Schlüssel entfernt: Er wurde mit dem verlorenen Schlüssel verschlüsselt.

## Wogegen eine Rotation schützt - und wogegen nicht

Eine Rotation macht einen bekannt gewordenen Schlüssel für die ab dann gespeicherten Werte nutzlos - und für die bisher gespeicherten, sobald sie umgezogen sind und der vorherige Schlüssel verworfen ist. Bis dahin kann jeder, der den neuen Schlüssel und die Datenbank hat, auch den vorherigen lesen. Verwirf ihn, sobald jeder Wert umgezogen ist.

Gegen Kopien von vorher hilft sie nicht: Backups der Datenbank und der Dateien aus der Zeit des bisherigen Schlüssels lassen sich mit ihm weiterhin lesen.

## Mehrere Plugins und ältere Versionen

Der vorherige Schlüssel gehört nur zu deinem Plugin und seinen Methoden. Versionen vor 4.3.0 kennen weder das [versionierte Format](PayloadFormat_de.md) noch einen vorherigen Schlüssel: Wenn eine ältere Kopie dieses Packages im Einsatz sein kann, siehe [Das Package in mehreren Plugins](MultiplePlugins_de.md).

## Eigene Methoden

Eine eigene Methode, die über den Filter `{slug}_crypt_methods` hinzugefügt wurde, lässt sich rotieren, wenn sie `generate_key()` überschreibt: Die Methode liefert einen neuen Schlüssel, so wie sie ihn an ihrem Speicherort speichert. Dekodiert die Methode ihren Schlüssel in `init()`, überschreibt sie auch `get_hash_from_stored_key()`. Ohne `generate_key()` meldet `rotate_key()` den Grund `key_not_generated`. Der vorherige Schlüssel wird bei jeder Methode verwendet, die mit `get_hash()` arbeitet.
