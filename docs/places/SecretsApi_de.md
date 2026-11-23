# Verwendung der Secrets API von WordPress

Dieses Dokument beschreibt die Verwendung der Secrets API von WordPress (verfügbar seit WordPress 7.2) als Aufbewahrungsort für den Schlüssel zum Ver- und Entschlüsseln von Texten mit _Crypt for WordPress_.

## Hinweise

Der Schlüssel wird als Secret von WordPress gespeichert. WordPress verschlüsselt ihn mit seinem eigenen Root-Key, der durch die Konstante `WP_SECRETS_KEY` in der `wp-config.php` geschützt ist - oder, wenn sie nicht gesetzt ist, durch die WordPress-Salts `LOGGED_IN_KEY` und `LOGGED_IN_SALT`. Ein Hoster kann das durch ein Drop-in (`wp-content/secrets.php`) ersetzen, das die Secrets in einem Key-Management-Service, einem Vault oder seinem Control Panel aufbewahrt.

Der Schlüssel liegt damit in der Datenbank - aber verschlüsselt. Wer nur die Datenbank in die Hände bekommt, kann ihn nicht lesen. Anders als beim Speicherort `database`, der den Schlüssel im Klartext neben die verschlüsselten Werte legt.

Der Name des Secrets ist `{slug}/{konstante}`, reduziert auf Kleinbuchstaben, Ziffern, Bindestriche und Unterstriche, z.B. `my-plugin/my-plugin-sodium-hash`. Er lässt sich mit dem Filter `{slug}_crypt_secrets_api_name` ändern - ihn später zu ändern, ist wie ein verlorener Schlüssel.

In einem Multisite-Netzwerk hat jede Site ihr eigenes Secret.

## Wann er verwendet wird

Ohne Konfiguration wird der Speicherort automatisch verwendet, wenn die wp-config.php nicht geschrieben werden kann:

* **vor dem Must-Use-Plugin**, wenn `WP_SECRETS_KEY` definiert ist oder ein Drop-in genutzt wird,
* **nach dem Must-Use-Plugin, vor der Datenbank**, wenn die Secrets API von den WordPress-Salts abhängt. Manche Sicherheits-Plugins ändern die Salts von sich aus - der Schlüssel wäre danach unlesbar.

Verwaltet der Hoster die Secrets selbst und nimmt keine neuen von WordPress an, wird der Speicherort nur verwendet, wenn er bereits einen Schlüssel deines Plugins enthält. Der Hoster muss ihn dann mit seinen eigenen Werkzeugen hinterlegen.

## Im Zweifel geschlossen

Enthält die Secrets API den Schlüssel, kann ihn aber nicht herausgeben - der Dienst dahinter ist nicht erreichbar, oder `WP_SECRETS_KEY` bzw. die Salts wurden geändert -, wird nichts ersetzt:

* der Fehler `secrets_api_unavailable` wird gemeldet,
* **es wird kein neuer Schlüssel erzeugt**, auch nicht an einem anderen Speicherort - stattdessen wird `key_unavailable` gemeldet,
* `encrypt()` und `decrypt()` liefern einen leeren String, bis der Schlüssel wieder verfügbar ist.

Prüfe in diesem Fall den Website-Zustand (Site Health) von WordPress: Er meldet Secrets, die sich nicht entschlüsseln lassen.

## Deinstallation

`uninstall()` bewahrt den Schlüssel jeder Methode als Secret `{slug}/uninstalled-{methode}` auf, z. B. `my-plugin/uninstalled-sodium` - egal, welcher Speicherort verwendet wurde, auch wenn ein anderer erzwungen ist. Eine spätere Installation verschiebt ihn an seinen Speicherort und entfernt das Secret danach. Nur `"block_secrets_api" => true`, ein Provider, der keine neuen Secrets annimmt, oder ein Secret, das sich nicht zurücklesen lässt, führen dazu, dass er stattdessen in der Datenbank aufbewahrt wird.

Lässt sich das aufbewahrte Secret bei einer späteren Installation nicht lesen, bleibt auch hier alles geschlossen: `secrets_api_unavailable` (mit `kept_key` in seinen Daten) und `key_unavailable` werden gemeldet, und es wird kein neuer Schlüssel erzeugt. Das passiert z. B., wenn die WordPress-Salts zwischenzeitlich geändert wurden und `WP_SECRETS_KEY` nicht verwendet wird. Werden die früheren Salts bzw. `WP_SECRETS_KEY` wiederhergestellt, ist der Schlüssel wieder lesbar.

Ist das nicht möglich, kann dein Plugin einen Neustart mit einem neuen Schlüssel anbieten:

```
// nur nach Bestätigung durch den Nutzer: die bisher verschlüsselten Werte bleiben unlesbar.
$crypt->discard_kept_keys();
```

Das entfernt die aufbewahrten Schlüssel - als Secret und in der Datenbank. Die nächste Verwendung erzeugt einen neuen Schlüssel und meldet `key_missing`.

## Verwendung

1. Setze in der Konfiguration zum Laden von _Crypt for WordPress_ über `set_config()` die folgende Angabe:
- "force_place" => "secrets_api"
2. Speichere die Angaben. Sie wirken sofort.

Ein Schlüssel, der vorher an einem anderen Speicherort gespeichert wurde, wird dort weiterhin gefunden und verwendet (Warnung `key_in_inactive_place`). Er wird nicht verschoben.

## Optionen

* "block_secrets_api" => true, um diesen Speicherort nie zu verwenden

## Fehler

| Code                      | Bedeutung                                                                                              |
|---------------------------|--------------------------------------------------------------------------------------------------------|
| `secrets_api_unavailable` | Das Secret existiert, konnte aber nicht gelesen werden. Es wird nichts ersetzt. Der Grund steht in den Daten. |
| `secrets_api_withheld`    | Der Provider des Hosters enthält den Schlüssel, gibt ihn aber nicht an PHP heraus.                     |
| `secrets_api_read_only`   | Der Hoster nimmt keine neuen Secrets an. Hinterlege den Schlüssel mit den Werkzeugen deines Hosters.   |
| `secrets_api_not_saved`   | Der Schlüssel konnte nicht gespeichert werden. Der Grund steht in den Daten.                           |
