# Verwendung vom Must Use Plugin

Dieses Dokument beschreibt die Verwendung des generischen Must Use Plugins als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit _Crypt for WordPress_.

## Hinweise

Die Datei des "Must Use"-Plugins wird durch _Crypt for WordPress_ automatisch erzeugt. Diese Methode wird automatisch verwendet, wenn die wp-config.php nicht beschreibbar ist. Sie kann aber auch erzwungen werden (siehe unten).

## Verwendung

1. Setze in der Konfiguration zum Laden von _Crypt for WordPress_ über `set_config()` die folgenden Angaben:
- "force_place" => "muplugin"
2. Speichere die Angaben. Sie wirken sofort.

## Optionen

Weitere Optionen sind:

* "file_permissions" => setze eine gewünschte Dateiberechtigung für die erzeugte Datei, z.B. 0600 damit sie nur vom aktuellen Server-Nutzer les- und schreibbar ist