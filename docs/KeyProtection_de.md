# Was passiert, wenn der Schlüssel verloren geht

Jeder Wert wird mit dem Schlüssel deines Plugins oder Themes verschlüsselt. Geht dieser Schlüssel verloren, sind die damit verschlüsselten Werte nicht mehr lesbar. _Crypt for WordPress_ kann das nicht verhindern - lässt es aber nicht unbemerkt geschehen.

## So funktioniert es

Wird ein Schlüssel zum ersten Mal verwendet, wird ein Fingerabdruck davon in der Datenbank gespeichert (Option `{slug}_crypt_key_id_{method}`). Der Fingerabdruck ist kein Geheimnis und kann nichts entschlüsseln. Er erlaubt nur, zwei Situationen zu unterscheiden:

* **Es gab noch nie einen Schlüssel.** Ein neuer wird erzeugt und gespeichert. Es wird nichts gemeldet.
* **Es gab einen Schlüssel, aber er ist nicht auffindbar.** Auch dann wird ein neuer erzeugt und gespeichert, damit dein Plugin weiterarbeitet, ohne dass jemand gefragt wird. Es wird aber der Fehler `key_missing` gemeldet: Alle bisher verschlüsselten Werte sind unlesbar.
* **Es gibt einen Schlüssel, aber sein Speicherort kann ihn gerade nicht herausgeben** - z.B. erreicht die [Secrets API](places/SecretsApi_de.md) von WordPress den Dienst des Hosters nicht. Dann wird kein neuer Schlüssel erzeugt, an keinem Speicherort: Er würde den Schlüssel aller bisher verschlüsselten Werte ersetzen. Es wird der Fehler `key_unavailable` gemeldet, und es wird nichts ver- oder entschlüsselt, bis der Schlüssel wieder verfügbar ist.

Dasselbe gilt, wenn ein Schlüssel gefunden wird, der nicht der bisher verwendete ist: Er wird benutzt, und `key_changed` wird gemeldet.

Beide Fehler werden **einmal** gemeldet - in dem Request, der es bemerkt. Danach ist der neue Schlüssel der bekannte. Wenn du deinen Nutzern mitteilen willst, warum sie gespeicherte Werte neu eingeben müssen, verarbeite den Fehler dort, wo er auftritt, z. B. indem du einen Hinweis speicherst (siehe [Handhabung von Fehlern](ErrorHandling_de.md)).

Bevor ein Schlüssel als fehlend behandelt wird, werden die anderen unterstützten Speicherorte danach durchsucht - nicht nur der aktive. Ein in der Datenbank oder in einer individuellen Datei gespeicherter Schlüssel wird also auch dann noch gefunden, wenn die wp-config.php inzwischen beschreibbar ist. Er wird von dort genutzt, wo er liegt, und die Warnung `key_in_inactive_place` wird gemeldet.

Ein neuer Schlüssel wird erst verwendet, wenn er an seinem Speicherort angekommen ist. Schlägt das Speichern am aktiven Speicherort fehl, wird der nächste nutzbare versucht (Warnung `key_saved_in_fallback_place`, siehe [Speicherorte](Places_de.md)). Schlägt es überall fehl, wird nichts verschlüsselt: `encrypt()` liefert einen leeren String und `key_not_saved` wird gemeldet. Ein Wert, der mit einem nur für einen Request existierenden Schlüssel verschlüsselt wurde, wäre nie wieder lesbar.

In einem Multisite-Netzwerk wird der Fingerabdruck dort gespeichert, wo der Schlüssel liegt: netzwerkweit, wenn der Schlüssel in einer Datei oder Variablen liegt, pro Website, wenn er in der Datenbank liegt. Das gilt auch für einen Schlüssel, der an einem ausweichenden Speicherort gelandet ist.

`uninstall()` bewahrt den Schlüssel auf, damit eine spätere Installation die vorhandenen Werte wieder lesen kann - egal, wo der Schlüssel gespeichert war. Ab WordPress 7.2 geschieht das als Secret über die Secrets API von WordPress, sonst in einer Option in der Datenbank, die nicht bei jedem Request geladen wird (siehe [Sicherheitsmodell](SecurityModel_de.md)). Eine spätere Installation verschiebt ihn an seinen Speicherort und entfernt erst danach die aufbewahrte Kopie. Gibt es das aufbewahrte Secret, lässt es sich aber nicht lesen, wird kein neuer Schlüssel erzeugt: `secrets_api_unavailable` und `key_unavailable` werden gemeldet. Nach Bestätigung durch den Nutzer deines Plugins beginnt `$crypt->discard_kept_keys()` mit einem neuen Schlüssel (siehe [Secrets API](places/SecretsApi_de.md#deinstallation)). Gibt es keinen Schlüssel zum Aufbewahren, wird auch der Fingerabdruck entfernt.

## Fehler

| Code                    | Bedeutung                                                                                                                  |
|-------------------------|----------------------------------------------------------------------------------------------------------------------------|
| `key_missing`           | Es wurde bereits ein Schlüssel verwendet, er war aber nicht auffindbar. Ein neuer wurde erzeugt. Wird einmal gemeldet.       |
| `key_changed`           | Es wurde ein Schlüssel gefunden, der nicht der bisher verwendete ist. Er wird ab jetzt benutzt. Wird einmal gemeldet.        |
| `key_not_saved`         | Ein neuer Schlüssel konnte nicht an seinem Speicherort gespeichert werden. Er wird nicht benutzt.                            |
| `no_key_available`      | `encrypt()` oder `decrypt()` wurde ohne nutzbaren Schlüssel aufgerufen. Die Ursache wurde zuvor gemeldet.                    |
| `key_unavailable`       | Ein Speicherort enthält den Schlüssel, kann ihn aber gerade nicht herausgeben (z.B. die [Secrets API](places/SecretsApi_de.md)). Es wird kein neuer Schlüssel erzeugt, nichts verschlüsselt. |
| `key_in_inactive_place` | Warnung: Der Schlüssel wurde an einem anderen als dem aktiven Speicherort gefunden. Er wird von dort genutzt.                |
| `key_saved_in_fallback_place` | Warnung: Ein neuer Schlüssel konnte nicht am aktiven Speicherort gespeichert werden und liegt stattdessen am nächsten nutzbaren. Die Daten nennen `failed_places` und `saved_in`. |

## Wann passiert das?

* Die wp-config.php wurde ersetzt: durch einen Umzug, ein Deployment oder ein eingespieltes Backup.
* Eine Datenbank wurde in eine andere Installation kopiert, z. B. in ein Staging-System mit eigener wp-config.php.
* Die WordPress-Salts wurden geändert, während der Speicherort `wordpress_salts` genutzt wird.
* Der Speicherort, in den der Schlüssel geschrieben werden soll, ist nicht beschreibbar.

## Die Werte zurückbekommen

Die mit dem früheren Schlüssel verschlüsselten Werte werden nicht gelöscht. Wird der frühere Schlüssel wiederhergestellt - die ursprüngliche Zeile `define( '...-HASH', '...' );` in der wp-config.php oder das erzeugte Must-Use-Plugin -, sind sie wieder lesbar. Für diesen Wechsel wird noch einmal `key_changed` gemeldet, und unlesbar sind dann die Werte, die zwischenzeitlich mit dem anderen Schlüssel verschlüsselt wurden.
