# Das Package in mehreren Plugins

Beliebig viele Plugins und Themes einer Website können _Crypt for WordPress_ nutzen. Jedes verschlüsselt mit einem eigenen Schlüssel, der zu seinem Slug gehört. Ein Wert des einen Plugins lässt sich mit dem Schlüssel eines anderen nicht entschlüsseln.

Der Slug ist der Name des Verzeichnisses des Plugins. Setze ihn selbst mit `set_slug()`, wo es kein solches Verzeichnis gibt:

* bei einem Plugin, das aus einer einzelnen Datei direkt im Plugin-Verzeichnis besteht, und bei einem Must-Use-Plugin: Sie alle bekämen denselben Slug und damit denselben Schlüssel. Das wird als Fehler `slug_not_unique` gemeldet
* bei einem Theme: Sein Slug enthielte den Pfad der Installation und würde sich ändern, wenn die Website umzieht - der Schlüssel würde nicht mehr gefunden. Das wird als Fehler `slug_depends_on_path` gemeldet

Setze den Slug, bevor der erste Wert verschlüsselt wird. Der Schlüssel wird unter dem Slug gespeichert: Ihn später zu ändern bedeutet einen neuen Schlüssel, und die bisher verschlüsselten Werte lassen sich nicht mehr entschlüsseln. Deshalb meldet das Package einen solchen Slug nur und ändert ihn nicht.

## Was sich nicht verhindern lässt

Alle Plugins einer Website laufen im selben PHP-Prozess mit denselben Rechten. Ein Plugin, das die Werte eines anderen lesen will, kann das: Es kann den Schlüssel an seinem Speicherort lesen - in der `wp-config.php`, der Datenbank, der Konstante - oder ein `Crypt`-Objekt mit dem Slug des anderen Plugins erzeugen.

Das liegt nicht an diesem Package. WordPress trennt Plugins nicht voneinander, und PHP bietet dafür keine Mittel. Die getrennten Schlüssel schützen vor Versehen und vor Sicherheitslücken in anderen Plugins, über die sich die Datenbank lesen oder beschreiben lässt. Vor einem Plugin mit böser Absicht schützen sie nicht. Siehe [Wovor dieses Package schützt](SecurityModel_de.md).

## Eine Kopie für alle Plugins

PHP lädt eine Klasse nur einmal. Liefern zwei Plugins dieses Package mit, nutzen beide dieselbe `Crypt`-Klasse: die der Kopie, deren Autoloader sie zuerst findet. Welche das ist, hängt von der Reihenfolge ab, in der die Plugins geladen werden, und davon, welches die Klasse zuerst verwendet. Dein Plugin hat darauf keinen Einfluss.

Die `Crypt`-Klasse nimmt die übrigen Klassen dieses Packages aus ihrer eigenen Kopie. Es ist also in der Regel eine Version für alle Plugins im Einsatz - aber nicht unbedingt die, die du auslieferst:

* Dein Plugin läuft möglicherweise mit einer älteren Version dieses Packages, ohne eine Korrektur, auf die du dich verlässt.
* PHP beschwert sich nicht über ein zusätzliches Argument, das eine Funktion nicht kennt: Ein an `encrypt()` der Version 3.1.0 übergebener Kontext wird dort ignoriert. Der Wert ist nicht an ihn gebunden - und eine neuere Version wird sich später weigern, ihn mit diesem Kontext zu entschlüsseln.
* Wird das andere Plugin entfernt oder aktualisiert, ändert sich die verwendete Version. Eine ältere Version kann nicht jeden Wert einer neueren lesen: Version 3.1.0 kann nicht lesen, was diese Version mit einem Kontext schreibt, mit einem OpenSSL-Cipher ohne AEAD, oder mit OpenSSL und einem selbst gewählten Schlüssel aus einer Umgebungs- oder Servervariable.

In zwei Fällen können Klassen zweier Versionen dennoch aufeinandertreffen:

* Die verwendete `Crypt`-Klasse ist die der Version 3.1.0. Sie hat die übrigen Klassen noch nicht selbst geladen und nimmt sie deshalb aus der Kopie, die der Autoloader anbietet - auch aus einer neueren. Sie arbeiten zusammen. Diese `Crypt`-Klasse ignoriert einen Kontext, und ein Schlüssel außerhalb eines konfigurierten `force_place` wird nicht gefunden.
* Ein anderes Plugin hat eine Klasse seiner Kopie geladen, bevor das erste `Crypt`-Objekt verwendet wird, z.B. eine eigene Methode, die `Method_Base` erweitert. Über die restlichen Klassen entscheidet dann der Autoloader. Das kann in einem fatalen Fehler enden.

Du kannst prüfen, welche Kopie verwendet wird - direkt dort, wo du das `Crypt`-Objekt erzeugst:

```
$file = (string) ( new \ReflectionClass( \CryptForWordPress\Crypt::class ) )->getFileName();

if ( ! str_starts_with( wp_normalize_path( $file ), wp_normalize_path( plugin_dir_path( __FILE__ ) ) ) ) {
    // die Kopie eines anderen Plugins oder Themes wird verwendet.
}
```

`__FILE__` muss dabei eine Datei im Hauptverzeichnis deines Plugins sein.

## Gib deinem Plugin eine eigene Kopie

Die verlässliche Lösung ist, den Namespace des Packages beim Bauen deines Plugins zu ändern. Dein Plugin nutzt dann `MyPlugin\Dependencies\CryptForWordPress\Crypt`, und das hat kein anderes Plugin.

[Strauss](https://github.com/BrianHenryIE/strauss) erledigt das für WordPress-Plugins. Installiere es wie dort beschrieben und ergänze in deiner `composer.json`:

```
"extra": {
    "strauss": {
        "target_directory": "vendor-prefixed",
        "namespace_prefix": "MyPlugin\\Dependencies\\",
        "classmap_prefix": "MyPlugin_",
        "delete_vendor_packages": true
    }
}
```

Lade nach dem Lauf von Strauss dessen Autoloader in deinem Plugin und verwende die Klasse mit ihrem neuen Namespace:

```
require_once __DIR__ . '/vendor-prefixed/autoload.php';

use MyPlugin\Dependencies\CryptForWordPress\Crypt;
```

Gut zu wissen:

* Nichts, was dieses Package speichert, hängt vom Namespace ab. Bestehende Schlüssel und verschlüsselte Werte bleiben nach der Umstellung lesbar.
* Eigene Methoden und Speicherorte, die du über die Filter `{slug}_crypt_methods` und `{slug}_crypt_places` ergänzt, müssen die Klassen mit dem neuen Namespace erweitern.
* Falls dein Plugin zusätzlich `vendor/autoload.php` lädt: In einer Entwicklungs-Installation stellt Strauss dort die ursprünglichen Klassennamen als Alias bereit - damit würde die Kopie wieder geteilt. Das passiert nicht, wenn die Abhängigkeiten mit `composer install --no-dev` installiert wurden. Baue die Version, die du veröffentlichst, auf diese Weise.
* [PHP-Scoper](https://github.com/humbug/php-scoper) ist eine Alternative zu Strauss. Es muss so konfiguriert werden, dass es die Klassen und Funktionen von WordPress unangetastet lässt.
