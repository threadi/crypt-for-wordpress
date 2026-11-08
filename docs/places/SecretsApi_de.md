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
