# Speicherorte

Der für die Verschlüsselung verwendete Schlüssel kann an unterschiedlichen Orten hinterlegt werden. Wichtig hierbei ist lediglich, dass er zur Laufzeit geladen wird, damit die Inhalte wirklich ver- und entschlüsselt werden können.

## Auswahl

Ohne weitere Konfiguration, versucht die Crypt for WordPress-Bibliothek beim ersten Laden in einer WordPress-Umgebung den in diesem moment erzeugten Schlüssel an folgenden Orten zu hinterlegen:

1. zuerst in der Datei `wp-config.php`
2. ist diese nicht beschreibbar, wird ein Must-Use-Plugin erzeugt und dieses gespeichert
3. wenn auch das nicht geht, wird kein Schlüssel gespeichert

## Liste mit Orten

* die Datei `wp-config.php`
* ein Must-Use-Plugin
* eine individuelle Datei
* eine Serverumgebungsvariable
* eine Umgebungsvariable
