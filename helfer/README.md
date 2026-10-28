# Helfersystem-Anpassungen

Dieser Fork basiert auf [engelsystem/engelsystem](https://github.com/engelsystem/engelsystem).
Eigene Anpassungen liegen möglichst ausserhalb der Original-Dateien, damit Updates
von upstream ohne Merge-Konflikte übernommen werden können.

## Plugin „Helfertexte“ (Engel → Helfer)

`resources/plugins/Helfertexte/` – überschreibt alle sichtbaren Texte über die Übersetzungen
des Plugins (`lang/<locale>.po`), die nach denen des Originals geladen werden.

- `build-translations.py` erzeugt `lang/*.po` automatisch aus `resources/lang/`
  (Engel → Helfer, angel → helper). Nach jedem upstream-Merge neu ausführen
  (macht `update-from-upstream.sh` automatisch).
- Eigene Formulierungen in `manual/de_DE.po` bzw. `manual/en_US.po`; sie haben Vorrang.
- `resources/plugins/*` steht in der `.gitignore` des Originals, darum mit `git add -f` committen.
- Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

Nicht umbenannt: Code, Datenbank, URLs (z. B. `/angeltypes`) und die Credits-Seite.

Den Anwendungsnamen setzt `APP_NAME` in der Server-`.env`.

## Plugin „Tagesplan“

`resources/plugins/Tagesplan/` – neuer Menüpunkt „Tagesplan“ (`/tagesplan`): Tagesansicht der
Schichten, gruppiert nach Ort, eine Zeile pro benötigtem Platz, eine Spalte pro Zeitfenster.
Überlappende Schichten am selben Ort bekommen eigene Zeilen; kurze Überlappungen (bis 60 Min.,
nicht gleiche Zeiten) erscheinen als schmale „Übergabe“-Spalte in derselben Zeile (oben geht, unten kommt).
Pro Ort eine Kopfzeile, darunter die Helfertypen (je eigene Zeilen). Reihenfolge der Orte und
Helfertypen unter **Admin → Einstellungen → Tagesplan** (ein Name pro Zeile, Einrückung egal);
die Farbe ergibt sich aus der Position in dieser Liste.
Eigene Berechtigung `tagesplan.view` (Plugin-Migration, bekommt alle Gruppen mit `user_shifts`).
Klick auf ein Feld öffnet ein Popup mit Orts- und Schichtinfos zum Ein-/Austragen
(gleiche Rechteprüfung wie im Kern: `Shift_signup_allowed()`, `Shift_signout_allowed()`).
Abweichung: Überlappende Schichten am gleichen Ort mit gleichem Helfertyp dürfen beide belegt
werden, solange Start- und Endzeit nicht identisch sind (`ShiftPopupController::collidingShifts()`).
Schichteinteiler (und Supporter des Helfertyps) dürfen sich und andere auch in parallele Schichten eintragen.
Kategorien: Schichten mit einem anderen Schichttyp als dem meistverwendeten (z. B. „Raclette-Plausch“, „Private Events“)
bekommen im Tagesplan ein farbiges Etikett, im Popup steht die Kategorie.

Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

## Rechte (Datenbank, nicht im Repo)

Helfer (Gruppe „Angel“) sehen im Menü nur News, Frag den Admin und Tagesplan. Dafür wurden
`user_shifts`, `user_meetings`, `angeltypes.view` und `locations.view` der Gruppe „Angel“ entzogen
und den Gruppen „Shift Coordinator“ und „Bureaucrat“ gegeben (Admin → Gruppen).
Hinweis: `/angeltypes` und `/locations` sind im Original „freie Seiten“ und per direkter URL
weiterhin erreichbar; nur der Menüpunkt ist ausgeblendet.

## Geänderte Original-Dateien

Diese Dateien des Originals sind angepasst (Stellen mit `Helfersystem` markiert).
Bei Merge-Konflikten nach einem upstream-Update hier zuerst nachsehen:

| Datei | Änderung |
|---|---|
| `includes/pages/user_shifts.php` | Knopf „Eventzeitraum“: Template-Variablen + Funktion `shifts_event_range()` am Dateiende |
| `resources/views/pages/user-shifts.html` | Knopf „Eventzeitraum“ im Schichtfilter |
| `resources/assets/js/forms.js` | Klick-Handler `.set-range` |

## Orte und Helfertypen wie im Live-System

`helfer/setup/orte-mit-helfertypen.php` baut die früheren Einzel-Orte (ein Ort pro Helfertyp) um
auf 8 Orte mit mehreren Helfertypen (Atelier, Aussenbereich, Bistro, Kasse, Kerzenziehen,
Parkplatz, Wachsküche, Wachsschalen). Gleichzeitige Schichten eines Ortes werden zu einer Schicht
mit mehreren Helfertypen zusammengelegt; setzt auch die Reihenfolge im Tagesplan.
Aufruf siehe Kopf des Skripts (`--dry-run` für einen Testlauf).
