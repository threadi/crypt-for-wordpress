# Speicherorte

Der für die Verschlüsselung verwendete Schlüssel kann an unterschiedlichen Orten hinterlegt werden. Wichtig hierbei ist lediglich, dass er zur Laufzeit geladen wird, damit die Inhalte wirklich ver- und entschlüsselt werden können.

## Auswahl

Ohne weitere Konfiguration, versucht die _Crypt for WordPress_-Bibliothek beim ersten Laden in einer WordPress-Umgebung den in diesem moment erzeugten Schlüssel an folgenden Orten zu hinterlegen:

1. zuerst in der Datei `wp-config.php`
2. ist diese nicht beschreibbar, wird ein Must-Use-Plugin erzeugt und dieses gespeichert
3. wenn auch das nicht geht, wird kein Schlüssel gespeichert und keine Verschlüsselung genutzt

### Hinweis:

Standardmäßig wird der Schlüssel in der Datei **wp-config.php** gespeichert – dies funktioniert ohne zusätzliche Konfiguration und reicht für die meisten Zwecke aus.

Wenn du den Schlüssel getrennt von den Datenbankzugangsdaten aufbewahren möchtest, kannst du ihn alternativ in einer Server- oder Umgebungsvariablen speichern. Dies erfordert einige Anpassungen an deiner Hosting-Konfiguration – wende dich bezüglich der Konfiguration bitte an deinen Hosting-Support.

## Liste mit unterstützten Speicherorte

* die Datei `wp-config.php`
* ein Must-Use-Plugin
* eine individuelle Datei
* eine Serverumgebungsvariable
* eine Umgebungsvariable

## Individuelle Speicherort

Mit einem Hook ist es möglich eigene Speicherorte zu ergänzen. Der Hook setzt sich auf dem Slug deines Plugins und "_places" zusammen.

Beispiel:
```
function cfwpd_place( array $places ): array {
 $places[] = '\YourNameSpace\MyPlace';
 return $places;
}
add_filter( 'crypt_for_wordpress_demo_places', 'cfwpd_place' )
```

Die so ergänzte Klasse, muss \CryptForWordPress\Place_Base erweitern. Schau dir zum Aufbau die anderen mitgelieferten Klassen diesbezüglich an.
