# Verwendung vom Must Use Plugin

Dieses Dokument beschreibt die Verwendung des generischen Must Use Plugins als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit _Crypt for WordPress_.

## Hinweise

Die Datei des "Must Use"-Plugins wird durch _Crypt for WordPress_ automatisch erzeugt. Diese Methode wird automatisch verwendet, wenn die wp-config.php nicht beschreibbar ist. Sie kann aber auch erzwungen werden (siehe unten).

Die Datei heißt `{slug}-hash.php` und liegt direkt im Verzeichnis für Must-Use-Plugins. Enthält der Slug einen Pfad - das ist bei einer Datei der Fall, die zu keinem Plugin gehört, z.B. aus einem Theme -, werden die Trennzeichen durch `-` ersetzt und alles entfernt, was in einem Dateinamen nicht erlaubt ist. Ohne das würde die Datei in Unterverzeichnissen erwartet, die es nicht gibt und aus denen WordPress keine Must-Use-Plugins lädt. Setze für solche Dateien besser einen eigenen Slug mit `set_slug()` (siehe Warnung `slug_depends_on_path`).

## Verwendung

1. Setze in der Konfiguration zum Laden von _Crypt for WordPress_ über `set_config()` die folgenden Angaben:
- "force_place" => "muplugin"
2. Speichere die Angaben. Sie wirken sofort.

## Optionen

* "file_permissions" => setze eine gewünschte Dateiberechtigung für die erzeugte Datei, z.B. 0600 damit sie nur vom aktuellen Server-Nutzer les- und schreibbar ist