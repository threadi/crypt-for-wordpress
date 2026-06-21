# Verwendung von individuellen Dateien

Dieses Dokument beschreibt die Verwendung einer individuellen Datei als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit _Crypt for WordPress_.

## Wichtig

Niemals einen Pfad für die Datei aus Datenbankoptionen, Benutzereingaben oder REST-Requests verwenden. Das eröffnet mögliche Angriffsszenarien auf dein Projekt.

## Hinweise

Der Pfad zur Datei muss innerhalb deines Hostings liegen, kann in diesem aber auch außerhalb des von der Website genutzten Verzeichnisses liegen. Die Datei muss beschreibbar sein.

Beim Löschen des Plugins wird die hier angegebene Datei _nicht_ gelöscht. Sie muss ggfs. nachträglich manuell entfernt werden.

## Verwendung

1. Setze in der Konfiguration zum Laden von _Crypt for WordPress_ über `set_config()` die folgenden Angaben:
- "force_place" => "customfile"
- "custom_file_path" => der absolute Pfad zu deiner individuellen Datei innerhalb deines Hostings
2. Speichere die Angaben. Sie wirken sofort.
