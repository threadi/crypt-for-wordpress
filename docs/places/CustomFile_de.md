# Verwendung von individuellen Dateien

Dieses Dokument beschreibt die Verwendung einer individuellen Datei als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit _Crypt for WordPress_.

## Wichtig

Niemals einen Pfad für die Datei aus Datenbankoptionen, Benutzereingaben oder REST-Requests verwenden. Das eröffnet mögliche Angriffsszenarien auf dein Projekt.

## Hinweise

Der Pfad zur Datei muss innerhalb deines Hostings liegen, kann in diesem aber auch außerhalb des von der Website genutzten Verzeichnisses liegen. Der Pfad für die Datei muss beschreibbar sein.

Die Datei unter dem angegebenen Pfad wird durch _Crypt for WordPress_ erzeugt. Du musst sie nicht selbst anlegen.

Beim Löschen des Plugins wird die hier angegebene Datei _nicht_ gelöscht. Sie muss ggfs. nachträglich manuell entfernt werden.

Setze ggfs. die Dateiberechtigungen bewusst, so dass nur WordPress über den verwendeten Server-Nutzer diese lesen kann. Diese Konfiguration ist individuell je Hosting und wird von _Crypt for WordPress_ nicht weitergehend unterstützt.

## Verwendung

1. Setze in der Konfiguration zum Laden von _Crypt for WordPress_ über `set_config()` die folgenden Angaben:
- "force_place" => "customfile"
- "custom_file_path" => der absolute Pfad zu deiner individuellen Datei innerhalb deines Hostings
2. Speichere die Angaben. Sie wirken sofort.

## Optionen

* "file_permissions" => setze eine gewünschte Dateiberechtigung für die Datei, z.B. 0600 damit sie nur vom aktuellen Server-Nutzer les- und schreibbar ist