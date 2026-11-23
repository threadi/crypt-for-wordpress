# Speicherorte

Der für die Verschlüsselung verwendete Schlüssel kann an unterschiedlichen Orten hinterlegt werden. Wichtig hierbei ist lediglich, dass er zur Laufzeit geladen wird, damit die Inhalte wirklich ver- und entschlüsselt werden können.

## Auswahl

Ohne weitere Konfiguration, versucht die _Crypt for WordPress_-Bibliothek beim ersten Laden in einer WordPress-Umgebung den in diesem moment erzeugten Schlüssel an folgenden Orten zu hinterlegen:

1. zuerst in der Datei `wp-config.php`
2. ist diese nicht beschreibbar, wird ein Must-Use-Plugin erzeugt und dieses gespeichert
3. wenn auch das nicht geht, wird der Schlüssel als Secret über die [Secrets API von WordPress](places/SecretsApi_de.md) gespeichert (ab WordPress 7.2). Ist `WP_SECRETS_KEY` definiert oder wird ein Drop-in des Hosters genutzt, kommt das vor dem Must-Use-Plugin
4. wenn auch das nicht geht, wird der Schlüssel in der Datenbank gespeichert. Da dort auch die verschlüsselten Daten liegen, wird das als Fehler `insecure_place_database` gemeldet. Setze `'block_database' => true` in der Konfiguration, um das zu verhindern
5. ist die Datenbank blockiert, wird der Schlüssel aus den WordPress-Salts abgeleitet. Das wird als Fehler `insecure_place_wordpress_salts` gemeldet. Setze `'block_salts' => true` in der Konfiguration, um das zu verhindern. Ein Salt, der noch der Platzhalter aus der wp-config-sample.php ist, wird nie verwendet, gemeldet als `wordpress_salts_placeholder`
6. bleibt kein Speicherort übrig, wird kein Schlüssel gespeichert und nichts verschlüsselt: `encrypt()` liefert einen leeren String

Ob ein Speicherort nutzbar ist, wird vorab nur abgeschätzt - etwa daran, ob ein Verzeichnis beschreibbar ist. Scheitert das Speichern trotzdem (volle Festplatte, `open_basedir`, Dateisystemzugriff per FTP), wird der Schlüssel am nächsten nutzbaren Ort dieser Reihenfolge gespeichert. Das wird als Warnung `key_saved_in_fallback_place` gemeldet, landet er in der Datenbank zusätzlich als `insecure_place_database`. Mit den nächsten Requests wird er dort gefunden (Warnung `key_in_inactive_place`). Speicherorte, in denen nichts gespeichert werden kann (Umgebungs- und Servervariable, WordPress-Salts), kommen dafür nicht in Frage. Ist ein Speicherort mit `force_place` erzwungen, wird nicht ausgewichen.

### Hinweis:

Standardmäßig wird der Schlüssel in der Datei **wp-config.php** gespeichert – dies funktioniert ohne zusätzliche Konfiguration und reicht für die meisten Zwecke aus.

Wenn du den Schlüssel getrennt von den Datenbankzugangsdaten aufbewahren möchtest, kannst du ihn alternativ in einer Server- oder Umgebungsvariablen speichern. Dies erfordert einige Anpassungen an deiner Hosting-Konfiguration – wende dich bezüglich der Konfiguration bitte an deinen Hosting-Support.

## Liste mit unterstützten Speicherorte

* die Datei `wp-config.php`
* [ein Must-Use-Plugin](places/MuPlugin_de.md)
* [die Secrets API von WordPress](places/SecretsApi_de.md)
* [eine individuelle Datei](places/CustomFile_de.md)
* [eine Serverumgebungsvariable](places/ServerVariable_de.md)
* [eine Umgebungsvariable](places/EnvironmentVariable_de.md)
* die Datenbank (nicht empfohlen, siehe oben)
* die WordPress-Salts (nicht empfohlen, siehe oben)

## Individuelle Speicherort

Mit einem Hook ist es möglich eigene Speicherorte zu ergänzen. Der Hook setzt sich aus dem Slug deines Plugins und "_crypt_places" zusammen.

Beispiel für ein Plugin mit dem Slug `crypt-for-wordpress-demo`:
```
function cfwpd_place( array $places ): array {
 $places[] = '\YourNameSpace\MyPlace';
 return $places;
}
add_filter( 'crypt-for-wordpress-demo_crypt_places', 'cfwpd_place' );
```

Die so ergänzte Klasse, muss \CryptForWordPress\Place_Base erweitern. Schau dir zum Aufbau die anderen mitgelieferten Klassen diesbezüglich an.

Teste sie mit den Conformance-Tests dieses Packages: [Eigene Speicherorte und Methoden testen](Testing_de.md).
