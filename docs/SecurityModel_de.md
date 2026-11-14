# Wovor dieses Package schützt - und wovor nicht

_Crypt for WordPress_ verschlüsselt Werte, bevor sie in der Datenbank gespeichert werden. Der Schlüssel liegt an einem anderen Ort, z.B. in der `wp-config.php`. Wer nur die Datenbank in die Hände bekommt, kann die Werte nicht lesen.

## Geschützt

* **Die Datenbank in falschen Händen**: ein Dump, ein Backup, ein Export, der Zugriff über phpMyAdmin oder eine Sicherheitslücke, über die sich die Datenbank auslesen lässt (SQL-Injection) - in deinem Plugin oder in irgendeinem anderen. Ohne den Schlüssel sind die Werte nicht lesbar.
* **Veränderte Werte**: Ein Wert, der in der Datenbank verändert wurde, wird nicht entschlüsselt. `decrypt()` liefert einen leeren String und meldet einen Fehler. Für Werte, die eine ältere Version mit einem Cipher ohne AEAD (nicht der Standard) geschrieben hat, gilt das, sobald sie neu verschlüsselt wurden.
* **Verschobene Werte**: Mit einem [Kontext](Context_de.md) wird ein verschlüsselter Wert, der in ein anderes Feld kopiert wurde, dort nicht entschlüsselt: `$crypt->encrypt( $value, 'api_secret' )`.
* **Stack-Traces**: PHP ersetzt den zu verschlüsselnden Text und den Schlüssel in Stack-Traces, überall wo sie Argumente einer Funktion dieses Packages sind. Auf diesem Weg landen sie also nicht im Fehlerlog oder in einem Error-Tracker. Funktionen von WordPress, deines eigenen Codes und eigener Methoden und Speicherorte sind davon nicht abgedeckt.

All das gilt nur, solange der Schlüssel nicht ebenfalls in der Datenbank liegt:

* Seit WordPress 7.2 wird der Schlüssel über die [Secrets API von WordPress](places/SecretsApi_de.md) gespeichert, wenn die `wp-config.php` nicht geschrieben werden kann. Er liegt dann in der Datenbank, aber von WordPress verschlüsselt mit einem Schlüssel aus der `wp-config.php` (`WP_SECRETS_KEY` oder die Salts) oder des Hosters. Die Datenbank allein reicht nicht, um ihn zu lesen.
* Der Speicherort `database` wird verwendet, wenn weder die `wp-config.php`, ein Must-Use-Plugin noch die Secrets API genutzt werden kann. Er wird als `insecure_place_database` gemeldet. Setze `'block_database' => true` in der Konfiguration, um ihn nie zu verwenden. Der nächste Speicherort sind dann die WordPress-Salts, gemeldet als `insecure_place_wordpress_salts` und blockiert mit `'block_salts' => true`. Ein Salt, der noch der Platzhalter aus der wp-config-sample.php ist, wird nie verwendet: Der daraus abgeleitete Schlüssel wäre für jeden bekannt (`wordpress_salts_placeholder`).
* Der Speicherort `database` speichert den Schlüssel in einer Option, die nicht bei jedem Request geladen wird. So landet er nicht in einem persistenten Object Cache wie Redis oder Memcached. Ein Schlüssel, den eine ältere Version gespeichert hat, wird entsprechend umgestellt, sobald er geladen wird (WordPress 6.4 oder neuer).
* `uninstall()` bewahrt den Schlüssel auf, damit die Werte lesbar bleiben, falls das Plugin erneut installiert wird. Ab WordPress 7.2 geschieht das als Secret über die Secrets API (`{slug}/uninstalled-openssl` und `{slug}/uninstalled-sodium`), verschlüsselt von WordPress. Nur wenn die Secrets API nicht verfügbar ist, keine neuen Secrets annimmt oder mit `'block_secrets_api' => true` gesperrt ist, landet er in der Datenbank - im Klartext, in den Optionen `{slug}_hash` und `{slug}_sodium_hash`, die nicht bei jedem Request geladen werden. Ein Schlüssel, den eine ältere Version dort aufbewahrt hat, wird bei der nächsten Deinstallation in das Secret verschoben. Lösche die Secrets bzw. Optionen - oder rufe `$crypt->discard_kept_keys()` auf -, wenn der Schlüssel nicht aufbewahrt werden soll.

Enthält ein Speicherort den Schlüssel, kann ihn aber nicht herausgeben - z.B. erreicht die Secrets API den Dienst des Hosters nicht -, wird kein neuer Schlüssel erzeugt. Es wird `key_unavailable` gemeldet und nichts verschlüsselt, bis der Schlüssel wieder verfügbar ist. Ein vorübergehender Ausfall ersetzt nie den Schlüssel deiner Werte.

## Nicht geschützt

* **Code, der im selben WordPress läuft**: jedes Plugin, jedes Theme und jeder, der dort PHP ausführen kann - über eine Sicherheitslücke oder als Administrator mit dem Plugin-Editor. All das läuft im selben Prozess mit denselben Rechten wie dein Plugin. Es kann den Schlüssel an seinem Speicherort lesen und `decrypt()` aufrufen wie dein Plugin auch. Keine PHP-Bibliothek kann das verhindern, siehe [Das Package in mehreren Plugins](MultiplePlugins_de.md).
* **Dateien in falschen Händen**: eine Sicherheitslücke, über die sich Dateien auslesen lassen, oder ein Backup, das Dateien und Datenbank enthält. Die `wp-config.php` enthält den Schlüssel.
* **Werte in Benutzung**: Was dein Plugin entschlüsselt hat, ist wieder Klartext - in einer Variable, in einem Log, das dein Plugin schreibt, im HTML einer Einstellungsseite.

## Was du tun kannst

* Halte den Schlüssel aus der Datenbank heraus: Erzwinge nicht den Speicherort `database`, und reagiere auf den Fehler `insecure_place_database`.
* Nutze für jeden Wert einen [Kontext](Context_de.md).
* Verschlüssele nur, was dein Plugin wieder lesen muss. Was es nur vergleichen muss - etwa ein Passwort - sollte stattdessen gehasht werden.
* Schreibe entschlüsselte Werte nicht in Logs und gib sie nicht in Formularen aus. Zeige stattdessen einen Platzhalter.
* Wenn andere Plugins derselben Website dieses Package ebenfalls nutzen könnten: [Gib deinem Plugin eine eigene Kopie](MultiplePlugins_de.md).
* Ab WordPress 7.2: Empfiehl deinen Nutzern, `WP_SECRETS_KEY` in der `wp-config.php` zu definieren. Ohne ihn hängt die Secrets API von den WordPress-Salts ab.

## Die Secrets API von WordPress oder Crypt for WordPress?

Seit Version 7.2 hat WordPress eine eigene [Secrets API](https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/). Sie schützt vor denselben Bedrohungen wie dieses Package - der Datenbank in falschen Händen, und nicht vor Code im selben WordPress. Beide haben ihren Zweck:

**Nutze die Secrets API direkt** für einzelne Zugangsdaten deines Plugins, wenn es WordPress 7.2 voraussetzt: einen API-Schlüssel, ein Token, das Passwort für einen externen Dienst.

```
wp_set_secret( 'my-plugin/api-key', $api_key );

$secret  = wp_get_secret( 'my-plugin/api-key' ); // WP_Secret, null (keins) oder WP_Error (nicht verfügbar).
$api_key = $secret instanceof WP_Secret ? $secret->reveal() : null;
if ( ! is_string( $api_key ) ) {
    // noch keiner gespeichert, oder gerade nicht verfügbar - im zweiten Fall nie einen neuen speichern.
}
```

Der Hoster kann diese Secrets in einem Key-Management-Service aufbewahren, alte Werte bleiben während einer Rotation verfügbar, `WP_Secret` maskiert den Wert in Logs und Dumps, und der Website-Zustand (Site Health) von WordPress meldet Secrets, die sich nicht entschlüsseln lassen.

**Nutze Crypt for WordPress** für alles andere:

* viele Werte oder Datensätze, z.B. personenbezogene Daten von Bewerbern oder Kunden - die Secrets API speichert jedes Secret als eigene Option und ist für Zugangsdaten gedacht,
* Werte in Post-Meta, User-Meta, Term-Meta oder eigenen Tabellen deines Plugins,
* Werte, die mit einem [Kontext](Context_de.md) an das gebunden sind, wozu sie gehören,
* Plugins, die WordPress-Versionen vor 7.2 unterstützen.

**Beides zusammen**: Seit WordPress 7.2 speichert dieses Package seinen eigenen Schlüssel über die Secrets API, wenn die `wp-config.php` nicht geschrieben werden kann - siehe [Speicherorte](Places_de.md).

Verschlüssele nicht doppelt: Übergib nie einen von diesem Package verschlüsselten Wert an `wp_set_secret()` oder `wp_import_option_as_secret()`. Das Secret wäre dann der verschlüsselte Wert, lesbar nur mit dem Schlüssel dieses Packages. Entschlüssele ihn zuerst, speichere den Klartext als Secret und lösche die Option danach.
