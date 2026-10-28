# Helfersystem-Anpassungen

Dieser Fork basiert auf [engelsystem/engelsystem](https://github.com/engelsystem/engelsystem).
Eigene Anpassungen liegen möglichst ausserhalb der Original-Dateien, damit Updates
von upstream ohne Merge-Konflikte übernommen werden können.

## Engel → Helfer

Alle sichtbaren Texte werden über `config/lang/<locale>/custom.po` überschrieben.
Diese Dateien lädt das System nach den Original-Übersetzungen.

- `helfer/build-translations.py` erzeugt die Dateien automatisch aus `resources/lang/`
  (Engel → Helfer, angel → helper). Nach jedem upstream-Merge neu ausführen.
- Eigene, zusätzliche Textänderungen in `helfer/lang/de_DE.po` bzw. `helfer/lang/en_US.po`
  eintragen; sie werden angehängt und haben Vorrang.
- `config/lang/` steht in der `.gitignore` des Originals, darum mit `git add -f` committen.

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
