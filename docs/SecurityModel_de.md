# Wovor dieses Package schützt - und wovor nicht

_Crypt for WordPress_ verschlüsselt Werte, bevor sie in der Datenbank gespeichert werden. Der Schlüssel liegt an einem anderen Ort, z.B. in der `wp-config.php`. Wer nur die Datenbank in die Hände bekommt, kann die Werte nicht lesen.

## Geschützt

* **Die Datenbank in falschen Händen**: ein Dump, ein Backup, ein Export, der Zugriff über phpMyAdmin oder eine Sicherheitslücke, über die sich die Datenbank auslesen lässt (SQL-Injection) - in deinem Plugin oder in irgendeinem anderen. Ohne den Schlüssel sind die Werte nicht lesbar.
* **Veränderte Werte**: Ein Wert, der in der Datenbank verändert wurde, wird nicht entschlüsselt. `decrypt()` liefert einen leeren String und meldet einen Fehler. Für Werte, die eine ältere Version mit einem Cipher ohne AEAD (nicht der Standard) geschrieben hat, gilt das, sobald sie neu verschlüsselt wurden.
* **Verschobene Werte**: Mit einem [Kontext](Context_de.md) wird ein verschlüsselter Wert, der in ein anderes Feld kopiert wurde, dort nicht entschlüsselt: `$crypt->encrypt( $value, 'api_secret' )`.
* **Stack-Traces**: PHP ersetzt den zu verschlüsselnden Text und den Schlüssel in Stack-Traces, überall wo sie Argumente einer Funktion dieses Packages sind. Auf diesem Weg landen sie also nicht im Fehlerlog oder in einem Error-Tracker. Funktionen von WordPress, deines eigenen Codes und eigener Methoden und Speicherorte sind davon nicht abgedeckt.

All das gilt nur, solange der Schlüssel nicht ebenfalls in der Datenbank liegt:

* Der Speicherort `database` wird verwendet, wenn weder die `wp-config.php` noch ein Must-Use-Plugin geschrieben werden kann. Er wird als `insecure_place_database` gemeldet. Setze `'block_database' => true` in der Konfiguration, um ihn nie zu verwenden. Der nächste Speicherort sind dann die WordPress-Salts, gemeldet als `insecure_place_wordpress_salts` und blockiert mit `'block_salts' => true`.
* `uninstall()` bewahrt den Schlüssel in der Datenbank auf (Optionen `{slug}_hash` und `{slug}_sodium_hash`), damit die Werte lesbar bleiben, falls das Plugin erneut installiert wird. Lösche diese Optionen, wenn du das nicht möchtest.

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
