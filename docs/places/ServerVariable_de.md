# Verwendung von ServerVariable

Dieses Dokument beschreibt die Verwendung einer Server-Umgebungsvariable als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit diesem composer package.

## Hinweise

Bei diesem Weg legst du selbst den Schlüssel fest, der für die Verschlüsselung der Texte in WordPress genutzt wird. Er wird nicht für dich generiert. Empfehlung ist ihn dennoch möglichst kompliziert zu gestalten. Er sollte aus mindestens 12 Zeichen bestehen, die sowohl Buchstaben und Zahlen als auch Sonderzeichen beinhalten.

Die Angabe in den Umgebungsvariablen besteht aus einem Key und einem Value. 

## Voraussetzungen

Ein Hosting in dem du serverseitig Umgebungsvariable setzen kannst. Diese müssen in der PHP-Variable `$_SERVER` bereitgestellt werden. Wende dich bei Fragen dazu an den Support deines Hosters.

Oder die Verwendung von https://github.com/vlucas/phpdotenv um mit .env-Dateien zu arbeiten. Siehe die Anleitung dort zur Einrichtung.

## Verwendung

1. Richte serverseitig eine Variable mit dem von dir gewünschten Schlüssel ein. 
2. Setze in der Konfiguration zum Laden von Crypt über `set_config()` die folgenden Angaben:
- "force_place" => "server_variable"
- "server_variable" => der von dir für die Umgebungsvariable verwendete Key
3. Speichere die Angaben. Sie wirken sofort.
