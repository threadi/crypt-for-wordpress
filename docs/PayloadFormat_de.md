# Das Format verschlüsselter Werte

Seit Version 4.3.0 beginnt jeder Wert, der mit OpenSSL oder Sodium verschlüsselt wird, mit einem Header. Er nennt die Version des Formats und den Schlüssel, mit dem der Wert verschlüsselt wurde:

| Bytes | Inhalt                                                                                         |
|-------|------------------------------------------------------------------------------------------------|
| 3     | Kennzeichen des Formats: `0xF3`, `C`, `W`                                                      |
| 1     | Version des Formats: `1`                                                                       |
| 1     | Flags: `1` = an seine Site gebunden, siehe unten                                               |
| 8     | ID des Schlüssels: sein Fingerabdruck, wie in der Option `{slug}_crypt_key_id_{method}`        |

Danach folgt die Methode:

* OpenSSL mit AEAD (das voreingestellte AES-256-GCM, ChaCha20-Poly1305): IV, Tag, Chiffretext
* OpenSSL ohne AEAD (z.B. AES-256-CBC): IV, HMAC, Chiffretext - mit Schlüsseln, die nur für dieses Format abgeleitet werden
* Sodium: der Algorithmus (ein Byte), Nonce, Chiffretext

Der ganze Wert ist wie bisher base64-kodiert. Er ist 13 Byte länger als im Format der Version 4.2.0 - etwa 18 Zeichen.

Die ID des Schlüssels ist kein Geheimnis. Mit ihr lässt sich nichts entschlüsseln, und sie liegt ohnehin in der Datenbank.

## Wozu das gut ist

**Ein Wert eines anderen Schlüssels wird als solcher gemeldet.** Nennt der Wert einen anderen Schlüssel als den verwendeten, meldet `decrypt()` den Fehler `key_mismatch` statt eines allgemeinen Fehlers der Methode. Seine Daten enthalten `key_id` (der Schlüssel des Werts) und `current_key_id` (der verwendete Schlüssel). Um den Schlüssel wiederzufinden, vergleiche `key_id` mit der Option `{slug}_crypt_key_id_{method}` in deinen Backups der Datenbank: Das Backup, in dem sie übereinstimmt, ist entstanden, während dieser Schlüssel verwendet wurde. Der Schlüssel selbst steckt im Backup der Dateien aus dieser Zeit - in der `wp-config.php` oder dem Must-Use-Plugin, siehe [Was passiert, wenn der Schlüssel verloren geht](KeyProtection_de.md).

**Das Format wird zusammen mit dem Wert authentifiziert.** Der Header, der Algorithmus von Sodium, der [Kontext](Context_de.md) und - wenn gewünscht - die Site sind zusätzliche Daten der Verschlüsselung. Keins davon lässt sich ändern, ohne dass der Wert nicht mehr entschlüsselt wird. Ein Wert lässt sich nicht als einer im Format der Version 4.2.0 ausgeben, indem man seinen Header entfernt, und seine Schlüssel-ID lässt sich nicht austauschen.

**Ein Wert einer neueren Version wird erkannt.** Eine spätere Version dieses Packages kann das Format ändern. Ein Wert mit einer Version oder Flags, die diese Version nicht kennt, wird als `payload_version_unknown` gemeldet - er wurde von einer neueren Kopie dieses Packages geschrieben, siehe [Das Package in mehreren Plugins](MultiplePlugins_de.md).

## Bestehende Werte

Werte der Version 4.2.0 und älter werden wie bisher gelesen, in jedem Format. Sie werden nicht verändert: Das neue Format bekommen sie, sobald dein Plugin sie neu verschlüsselt und speichert. Solange sie keinen Header haben, wird ein Wert eines anderen Schlüssels mit dem Fehler der Methode gemeldet, z.B. `sodium_decrypt_error`.

## Einstellungen

Beide Einstellungen gehören zur Konfiguration des Crypt-Objekts, nicht zu der einer Methode:

```
$crypt->set_config(
    array(
        'bind_site'     => true,  // neue Werte an die Site binden, auf der sie verschlüsselt werden.
        'legacy_format' => false, // true, um neue Werte im Format der Version 4.2.0 zu schreiben.
    )
);
```

### Einen Wert an seine Site binden

Mit `'bind_site' => true` werden neue Werte an die ID der Site gebunden, auf der sie verschlüsselt werden (`get_current_blog_id()`). Auf einer anderen Site werden sie nicht entschlüsselt, auch nicht mit demselben Schlüssel und demselben Kontext.

Das ist in einem Multisite-Netzwerk von Bedeutung, wenn der Schlüssel dem ganzen Netzwerk gehört - in der `wp-config.php`, dem Must-Use-Plugin oder einer Umgebungs- oder Servervariable. Ein Wert, der aus der Datenbanktabelle einer Site in die einer anderen Site kopiert wurde, wird dort dann nicht entschlüsselt. Mit einem Schlüssel pro Site - in den Speicherorten `database` und `secrets_api` - ist das ohnehin so. Auf einer einzelnen Website ändert es nichts.

Verwende es nur, wenn Werte nie absichtlich auf eine andere Site wandern. Sie lassen sich nicht mehr entschlüsseln, nachdem:

* eine Site dupliziert oder exportiert und in eine andere Site importiert wurde,
* eine Site eines Netzwerks in eine einzelne Installation oder ein anderes Netzwerk umgezogen ist - sie bekommt dort eine andere ID.

Ob ein Wert gebunden ist, steht im Wert selbst:

* Werte, die vor dem Aktivieren der Einstellung verschlüsselt wurden, sind nicht gebunden. Sie werden auf jeder Site entschlüsselt, bis dein Plugin sie neu verschlüsselt und speichert.
* Werte, die mit der Einstellung verschlüsselt wurden, bleiben gebunden, auch nachdem die Einstellung entfernt wurde.

### Das Format der Version 4.2.0

Mit `'legacy_format' => true` werden neue Werte ohne den Header geschrieben, im Format der Version 4.2.0. Versionen vor 4.3.0 können das neue Format nicht lesen. Nutze die Einstellung für einen Übergang, wenn sie deine Werte lesen müssen könnten:

* andere Plugins der Website liefern eine ältere Kopie dieses Packages mit, und dein Plugin kann keine eigene Kopie haben, siehe [Das Package in mehreren Plugins](MultiplePlugins_de.md),
* dein Plugin könnte auf eine Version zurückgesetzt werden, die eine ältere Kopie dieses Packages mitliefert.

Werte beider Formate werden in jedem Fall gelesen. Entferne die Einstellung, sobald der Grund entfallen ist: Die in der Zwischenzeit geschriebenen Werte behalten ihr Format, bis sie neu gespeichert werden.

`'bind_site'` braucht das neue Format. Mit beiden Einstellungen wird nichts verschlüsselt, und `bind_site_not_possible` wird gemeldet - ein Wert wird nie ohne eine für ihn gewünschte Bindung geschrieben.

## Fehler

| Code                      | Bedeutung                                                                                                                         |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `key_mismatch`            | Der Wert wurde mit einem anderen Schlüssel als dem verwendeten verschlüsselt. Die Daten enthalten `key_id`, `current_key_id`, `method` und `context`. |
| `payload_version_unknown` | Der Wert wurde von einer neueren Version dieses Packages geschrieben, in einem Format, das diese Version nicht lesen kann. Die Daten enthalten `version` und `flags`. |
| `bind_site_not_possible`  | `'bind_site'` und `'legacy_format'` sind beide gesetzt. Es wird nichts verschlüsselt.                                              |

## Eigene Methoden

Eine eigene Methode, hinzugefügt über den Filter `{slug}_crypt_methods`, schreibt das Format, das sie immer geschrieben hat. Für sie ändert sich nichts.
