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
Überlappende Schichten am selben Ort bekommen eigene Zeilen. Berechtigung: `user_shifts`.
Reihenfolge der Orte unter **Admin → Einstellungen → Tagesplan** (ein Ort pro Zeile);
die Farbe ergibt sich aus der Position in dieser Liste.
Klick auf ein Feld öffnet ein Popup mit Orts- und Schichtinfos zum Ein-/Austragen
(gleiche Rechteprüfung wie im Kern: `Shift_signup_allowed()`, `Shift_signout_allowed()`).

Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

## Geänderte Original-Dateien

Diese Dateien des Originals sind angepasst (Stellen mit `Helfersystem` markiert).
Bei Merge-Konflikten nach einem upstream-Update hier zuerst nachsehen:

| Datei | Änderung |
|---|---|
| `includes/pages/user_shifts.php` | Knopf „Eventzeitraum“: Template-Variablen + Funktion `shifts_event_range()` am Dateiende |
| `resources/views/pages/user-shifts.html` | Knopf „Eventzeitraum“ im Schichtfilter |
| `resources/assets/js/forms.js` | Klick-Handler `.set-range` |
