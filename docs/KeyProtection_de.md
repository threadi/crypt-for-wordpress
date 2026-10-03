# Was passiert, wenn der Schlüssel verloren geht

Jeder Wert wird mit dem Schlüssel deines Plugins oder Themes verschlüsselt. Geht dieser Schlüssel verloren, sind die damit verschlüsselten Werte nicht mehr lesbar. _Crypt for WordPress_ kann das nicht verhindern - lässt es aber nicht unbemerkt geschehen.

## So funktioniert es

Wird ein Schlüssel zum ersten Mal verwendet, wird ein Fingerabdruck davon in der Datenbank gespeichert (Option `{slug}_crypt_key_id_{method}`). Der Fingerabdruck ist kein Geheimnis und kann nichts entschlüsseln. Er erlaubt nur, zwei Situationen zu unterscheiden:

* **Es gab noch nie einen Schlüssel.** Ein neuer wird erzeugt und gespeichert. Es wird nichts gemeldet.
* **Es gab einen Schlüssel, aber er ist nicht auffindbar.** Auch dann wird ein neuer erzeugt und gespeichert, damit dein Plugin weiterarbeitet, ohne dass jemand gefragt wird. Es wird aber der Fehler `key_missing` gemeldet: Alle bisher verschlüsselten Werte sind unlesbar.

Dasselbe gilt, wenn ein Schlüssel gefunden wird, der nicht der bisher verwendete ist: Er wird benutzt, und `key_changed` wird gemeldet.

Beide Fehler werden **einmal** gemeldet - in dem Request, der es bemerkt. Danach ist der neue Schlüssel der bekannte. Wenn du deinen Nutzern mitteilen willst, warum sie gespeicherte Werte neu eingeben müssen, verarbeite den Fehler dort, wo er auftritt, z. B. indem du einen Hinweis speicherst (siehe [Handhabung von Fehlern](ErrorHandling_de.md)).

Bevor ein Schlüssel als fehlend behandelt wird, werden die anderen unterstützten Speicherorte danach durchsucht - nicht nur der aktive. Ein in der Datenbank oder in einer individuellen Datei gespeicherter Schlüssel wird also auch dann noch gefunden, wenn die wp-config.php inzwischen beschreibbar ist. Er wird von dort genutzt, wo er liegt, und die Warnung `key_in_inactive_place` wird gemeldet.

Ein neuer Schlüssel wird erst verwendet, wenn er an seinem Speicherort angekommen ist. Schlägt das Speichern fehl, wird nichts verschlüsselt: `encrypt()` liefert einen leeren String und `key_not_saved` wird gemeldet. Ein Wert, der mit einem nur für einen Request existierenden Schlüssel verschlüsselt wurde, wäre nie wieder lesbar.

In einem Multisite-Netzwerk wird der Fingerabdruck dort gespeichert, wo der Schlüssel liegt: netzwerkweit, wenn der Schlüssel in einer Datei oder Variablen liegt, pro Website, wenn er in der Datenbank liegt.

`uninstall()` behält den Schlüssel in der Datenbank, damit eine spätere Installation die vorhandenen Werte wieder lesen kann - egal, wo der Schlüssel gespeichert war. Gibt es keinen Schlüssel zum Aufbewahren, wird auch der Fingerabdruck entfernt.

## Fehler

| Code                    | Bedeutung                                                                                                                  |
|-------------------------|----------------------------------------------------------------------------------------------------------------------------|
| `key_missing`           | Es wurde bereits ein Schlüssel verwendet, er war aber nicht auffindbar. Ein neuer wurde erzeugt. Wird einmal gemeldet.       |
| `key_changed`           | Es wurde ein Schlüssel gefunden, der nicht der bisher verwendete ist. Er wird ab jetzt benutzt. Wird einmal gemeldet.        |
| `key_not_saved`         | Ein neuer Schlüssel konnte nicht an seinem Speicherort gespeichert werden. Er wird nicht benutzt.                            |
| `no_key_available`      | `encrypt()` oder `decrypt()` wurde ohne nutzbaren Schlüssel aufgerufen. Die Ursache wurde zuvor gemeldet.                    |
| `key_in_inactive_place` | Warnung: Der Schlüssel wurde an einem anderen als dem aktiven Speicherort gefunden. Er wird von dort genutzt.                |

## Wann passiert das?

* Die wp-config.php wurde ersetzt: durch einen Umzug, ein Deployment oder ein eingespieltes Backup.
* Eine Datenbank wurde in eine andere Installation kopiert, z. B. in ein Staging-System mit eigener wp-config.php.
* Die WordPress-Salts wurden geändert, während der Speicherort `wordpress_salts` genutzt wird.
* Der Speicherort, in den der Schlüssel geschrieben werden soll, ist nicht beschreibbar.

## Die Werte zurückbekommen

Die mit dem früheren Schlüssel verschlüsselten Werte werden nicht gelöscht. Wird der frühere Schlüssel wiederhergestellt - die ursprüngliche Zeile `define( '...-HASH', '...' );` in der wp-config.php oder das erzeugte Must-Use-Plugin -, sind sie wieder lesbar. Für diesen Wechsel wird noch einmal `key_changed` gemeldet, und unlesbar sind dann die Werte, die zwischenzeitlich mit dem anderen Schlüssel verschlüsselt wurden.
