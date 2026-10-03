# Speicherorte

Der für die Verschlüsselung verwendete Schlüssel kann an unterschiedlichen Orten hinterlegt werden. Wichtig hierbei ist lediglich, dass er zur Laufzeit geladen wird, damit die Inhalte wirklich ver- und entschlüsselt werden können.

## Auswahl

Ohne weitere Konfiguration, versucht die _Crypt for WordPress_-Bibliothek beim ersten Laden in einer WordPress-Umgebung den in diesem moment erzeugten Schlüssel an folgenden Orten zu hinterlegen:

1. zuerst in der Datei `wp-config.php`
2. ist diese nicht beschreibbar, wird ein Must-Use-Plugin erzeugt und dieses gespeichert
3. wenn auch das nicht geht, wird der Schlüssel in der Datenbank gespeichert. Da dort auch die verschlüsselten Daten liegen, wird das als Fehler `insecure_place_database` gemeldet. Setze `'block_database' => true` in der Konfiguration, um das zu verhindern
4. ist die Datenbank blockiert, wird der Schlüssel aus den WordPress-Salts abgeleitet. Das wird als Fehler `insecure_place_wordpress_salts` gemeldet. Setze `'block_salts' => true` in der Konfiguration, um das zu verhindern
5. bleibt kein Speicherort übrig, wird kein Schlüssel gespeichert und nichts verschlüsselt: `encrypt()` liefert einen leeren String

### Hinweis:

Standardmäßig wird der Schlüssel in der Datei **wp-config.php** gespeichert – dies funktioniert ohne zusätzliche Konfiguration und reicht für die meisten Zwecke aus.

Wenn du den Schlüssel getrennt von den Datenbankzugangsdaten aufbewahren möchtest, kannst du ihn alternativ in einer Server- oder Umgebungsvariablen speichern. Dies erfordert einige Anpassungen an deiner Hosting-Konfiguration – wende dich bezüglich der Konfiguration bitte an deinen Hosting-Support.

## Liste mit unterstützten Speicherorte

* die Datei `wp-config.php`
* [ein Must-Use-Plugin](places/MuPlugin_de.md)
* [eine individuelle Datei](places/CustomFile_de.md)
* [eine Serverumgebungsvariable](places/ServerVariable_de.md)
* [eine Umgebungsvariable](places/EnvironmentVariable_de.md)
* die Datenbank (nicht empfohlen, siehe oben)
* die WordPress-Salts (nicht empfohlen, siehe oben)

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
