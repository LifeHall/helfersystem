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
