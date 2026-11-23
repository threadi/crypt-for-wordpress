# Eigene Speicherorte und Methoden testen

Eigene Speicherorte und Methoden ergänzt du über die Filter `{slug}_crypt_places` und `{slug}_crypt_methods`. Sie müssen einige Regeln einhalten, auf die sich das Crypt-Objekt verlässt. Verletzt einer sie, kann das den Schlüssel aller bisher verschlüsselten Werte kosten - etwa ein Speicherort, der mit `is_saved()` einen Schlüssel bestätigt, bevor er wirklich geschrieben wurde.

Dieses Package liefert zwei PHPUnit-Traits mit, die diese Regeln prüfen:

* `CryptForWordPress\Testing\PlaceConformance` für Speicherorte,
* `CryptForWordPress\Testing\MethodConformance` für Methoden.

Die Speicherorte und Methoden dieses Packages werden ebenfalls damit getestet.

## Voraussetzungen

* PHPUnit mit geladenem WordPress - etwa eine Testklasse, die `WP_UnitTestCase` der WordPress-Testsuite erweitert.
* Die Klasse deines Speicherorts bzw. deiner Methode wird von deinem Autoloader geladen.

## Speicherorte

```
class My_Place_Test extends WP_UnitTestCase {
	use \CryptForWordPress\Testing\PlaceConformance;

	protected function get_place_class(): string {
		return My_Place::class;
	}
}
```

Die Tests prüfen:

* `get_stored_key()`, `is_key_unavailable()` und `get_derived_key()` sind reine Abfragen: Sie definieren keine Konstante und melden keinen Fehler.
* `is_saved()` bestätigt einen Schlüssel direkt nach `save()` - und nur diesen, nie einen leeren.
* Der Speicherort gibt den Schlüssel genau so heraus, wie er gespeichert wurde - auch mit Anführungszeichen und Backslashes.
* Das Speichern des Schlüssels einer Konstante erhält den Schlüssel einer anderen: Ein Plugin kann zwei Methoden verwenden.
* `uninstall()` entfernt den Schlüssel und richtet bei einer Konstante ohne Schlüssel keinen Schaden an.
* Ein Schlüssel, den der Speicherort selbst ableitet, ist jedes Mal derselbe und mindestens 32 Bytes lang.
* Der Round-Trip über das Crypt-Objekt mit Kontext, mit deinem Speicherort als einzigem.

Überschreibe diese geschützten Methoden, um die Tests anzupassen:

| Methode                                         | Standard                                    | Verwende sie, wenn ...                                                                          |
|-------------------------------------------------|---------------------------------------------|-------------------------------------------------------------------------------------------------|
| `get_place_conformance_config()`                | leeres Array                                | dein Speicherort Einstellungen braucht, z. B. einen Pfad. Wird einmal pro Test aufgerufen.     |
| `place_conformance_can_save()`                  | `true`                                      | dein Speicherort nur einen Schlüssel von woanders herausgibt, z. B. aus einer Variable des Hostings. |
| `place_conformance_removes_key_on_uninstall()`  | `true`                                      | `uninstall()` den Schlüssel absichtlich liegen lässt, z. B. in einer Datei, die der Nutzer verwaltet. |
| `get_place_conformance_saved_key()`             | `get_stored_key()`, sonst die Konstante, die `load()` definiert | WordPress deinen Speicherort mit dem nächsten Request selbst lädt, wie die `wp-config.php`. Lies den Schlüssel dort. |
| `get_place_conformance_plugin_file()`           | die Datei der Testklasse                    | dein Speicherort Daten des Plugins aus seiner Datei liest.                                     |

## Methoden

```
class My_Method_Test extends WP_UnitTestCase {
	use \CryptForWordPress\Testing\MethodConformance;

	protected function get_method_class(): string {
		return My_Method::class;
	}
}
```

Die Methode wird über ein Crypt-Objekt verwendet, das nur diese Methode kennt, mit dem Schlüssel im Speicherort `database`. Die Tests prüfen:

* Die Methode hat einen Namen und eine Konstante für ihren Schlüssel.
* Den Round-Trip: Der Text ist im verschlüsselten Wert nicht lesbar, und ein anderes Crypt-Objekt mit demselben Slug entschlüsselt ihn.
* Derselbe Text ergibt jedes Mal einen anderen Wert.
* Ein veränderter Wert wird nicht entschlüsselt, und das wird gemeldet.
* Ein Wert ist an seinen Kontext gebunden - oder die Methode meldet `context_not_supported`.
* Keine Eingabe endet in einer Exception, und aus einem Wert, den die Methode nicht geschrieben hat, wird nichts entschlüsselt.

Überschreibe `get_method_conformance_config()`, um die Konfiguration deiner Methode zu setzen, und `get_method_conformance_plugin_file()` wie bei den Speicherorten.

Jeder Test wird übersprungen, wenn deine Methode in der Testumgebung nicht nutzbar ist.

## Hinweise

* Jeder Test verwendet einen eigenen Slug und eine eigene Konstante: Eine PHP-Konstante lässt sich während eines Testlaufs nicht entfernen.
* Die Traits sind Teil des Packages, werden von ihm aber nie geladen. Sie landen nicht in den Requests deines Plugins.
