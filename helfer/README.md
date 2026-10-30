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
Unter **Admin → Konfiguration → Tagesplan** lässt er sich mit „Tagesplan für Helfer sichtbar“ ausblenden; dann sehen ihn
nur noch Benutzer mit `tagesplan.manage` (Plugin-Migration, alle Gruppen mit `user_shifts_admin`: Admins, Schichteinteiler).
Helfer (ohne `tagesplan.manage`) sehen nur die Tage und Plätze ihrer eigenen Einträge, ohne offene Plätze; eingetragen
wird über das geführte Eintragen der Startseite. Mit „Helfer sehen Personen am gleichen Ort zur gleichen Zeit“ (gleiche
Konfigurationsseite) sehen sie zusätzlich alle Belegten in Schichten am selben Ort, die sich zeitlich mit einer eigenen
überschneiden (`OwnShifts`, gilt auch für das Popup: fremde Schichten liefern 404).
Klick auf ein Feld öffnet ein Popup mit Orts- und Schichtinfos zum Ein-/Austragen
(gleiche Rechteprüfung wie im Kern: `Shift_signup_allowed()`, `Shift_signout_allowed()`).
Abweichung: Überlappende Schichten am gleichen Ort mit gleichem Helfertyp dürfen beide belegt
werden, solange Start- und Endzeit nicht identisch sind (`ShiftPopupController::collidingShifts()`).
Schichteinteiler (und Supporter des Helfertyps) dürfen sich und andere auch in parallele Schichten eintragen.
Kategorien: Schichten mit einem anderen Schichttyp als dem meistverwendeten (z. B. „Raclette-Plausch“, „Private Events“)
bekommen im Tagesplan ein farbiges Etikett, im Popup steht die Kategorie.
- **Excel-Export** (Knopf oben rechts, nur mit `tagesplan.manage`, `/tagesplan/einsatzplan.xlsx`): alle Tage im Aufbau des
  früheren Einsatzplans (`import/einsatzplan.xlsx`): ein Blatt pro Tag, Titel „Einsatzplan <Event-Name>“, Datum,
  Zeitfenster als Spalten, eine Zeile pro Platz mit den Namen, Farben der Orte, nicht benötigte Felder grau.
  Eigener kleiner XLSX-Schreiber (`Xlsx.php`), weil im Container die Zip-Erweiterung fehlt.
- **PDF-Export** (`/tagesplan/einsatzplan.pdf`, gleiche Berechtigung): gleicher Aufbau, ein Tag pro A4-Seite (quer),
  Zeilenhöhe und Schrift werden an die Anzahl Plätze angepasst. `Pdf.php` ist eine Kopie des PDF-Schreibers aus
  „Startseite“ (Querformat, farbige Felder), damit die Plugins unabhängig bleiben.
- **Gleiche Person, gleiche Zeile:** Innerhalb eines Helfertyps bleibt eine Person in der Zeile ihrer vorherigen Schicht
  (`Controller::arrange()`), z. B. bei aufeinanderfolgenden Diensten. Gilt im Tagesplan, im Excel und im PDF.
- **Dienstplan an eine Person senden** (oben rechts, nur mit `startseite.send_shifts` aus dem Plugin „Startseite“):
  Auswahl unter allen Personen mit Schichteinträgen, schickt dieselbe Mail mit PDF wie „Dienstpläne versenden“,
  aber nur an diese Person (Route `/startseite/dienste/versenden/person`).

Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

## Plugin „Startseite“

`resources/plugins/Startseite/` – ersetzt die News-Übersicht (`/news`, Standard-Startseite) über eine
Plugin-Middleware, die die Route `NewsController@index` auf `HomeController@index` umbiegt (Kern unverändert).

- **News:** Es werden nur angepinnte News angezeigt (`news.is_pinned`), in der festgelegten Reihenfolge
  (Tabelle `startseite_news_order`). Admins (`admin_news`) können direkt auf der Seite anpinnen, lösen und
  mit ↑/↓ verschieben; nicht angepinnte News stehen für sie in einer aufklappbaren Liste darunter.
  Im News-Editor angepinnte News erscheinen am Ende der Reihenfolge.
- **Meine Dienste:** Übersicht der eigenen Schichteinträge pro Tag mit Stunden, Download als PDF
  (`/startseite/dienste.pdf`, eigener kleiner PDF-Schreiber `Pdf.php`, keine Zusatzbibliothek).
- **Login-Seite (Startseite für Gäste):** überschreibt `pages/login.twig` des Kerns. Oben ein Text aus
  **Admin → Konfiguration → Startseite** (`startseite_login_text`, Markdown; leer = Standard-Begrüssung), in der Mitte
  Login und Registrierung, unten die Event-Daten. Bei upstream-Änderungen an `pages/login.twig` diese Kopie abgleichen.
- **Dienstpläne versenden** (Knopf bei „Meine Dienste“, Recht `startseite.send_shifts`, per Plugin-Migration für
  alle Gruppen mit `user_shifts_admin`): schickt allen Personen mit Schichteinträgen eine Mail in ihrer Sprache mit
  ihrem Dienstplan als PDF-Anhang (`ShiftPlanMailer` baut die Mail selbst, weil der Kern-Mailer keine Anhänge kann).
- **Geführt eintragen** (`/startseite/eintragen`, Berechtigung `startseite.signup`, per Plugin-Migration für alle
  Gruppen mit `tagesplan.view` oder `user_shifts`): 1. Helferdienste (Helfertypen), 2. Tage (ohne Zeitauswahl, alle Zeitslots des Tages),
  3. Tabelle pro Helferdienst (Zeilen = Zeitslots, Spalten = Tage) zum An-/Abhaken. Volle oder nicht mögliche
  Slots sind ausgegraut; „Nur Zeitslots mit freien Plätzen zeigen“ blendet solche Zeilen aus.
  Die Auswahl aus Schritt 1 und 2 wird pro Helfer gespeichert (`startseite_signup_choices`).
  Solange noch keine Auswahl gespeichert ist, sind in Schritt 1 die bei der Registrierung gewählten Helferdienste
  (Mitgliedschaften in `user_angel_type`) vorausgewählt, soweit sie schon auswählbar sind.
  Helferdienste und Tage mit eigenen Einträgen lassen sich nicht abwählen.
  Bei Helferdiensten mit Freischaltung gibt es in Schritt 1 „Freischaltung anfragen“: trägt die (unbestätigte)
  Mitgliedschaft ein und mailt allen mit `user.angeltypes.edit` und den Supportern des Helferdienstes;
  die bisherigen Häkchen bleiben erhalten.
  Wählt jemand schon bei der Registrierung einen Helferdienst mit Freischaltung, gehen dieselben Mails an die
  Bestätiger (`RegistrationHandler` auf das Ereignis `user.created`).
  Gleiche Rechteprüfung wie der Tagesplan (`Shift_signup_allowed()`, `Shift_signout_allowed()`, gleiche
  Kollisionsregel). In der Tabelle graut ein Skript beim Anhaken sofort alle Slots aus, die sich nach dieser Regel
  überschneiden; abwählbare eigene Einträge sperrt deshalb nicht der Server, sondern das Skript (beim Speichern prüft
  der Server alles nochmals). Die Zeitspalte bleibt beim seitlichen Scrollen stehen.

Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

## Plugin „Familienkonten“

`resources/plugins/Familienkonten/` – mehrere Konten (z. B. Familienmitglieder) mit derselben Mailadresse.

- **Datenbank:** Die Plugin-Migration ersetzt den eindeutigen Index `users_email_unique` durch einen normalen Index.
  Deinstallieren (Migration rückwärts) geht nur, solange keine Adresse mehrfach vorkommt.
- **Registrierung:** Der Kern lehnt eine schon verwendete Adresse ab. Die Plugin-Middleware gibt der Registrierung
  deshalb eine eindeutige Platzhalter-Adresse und setzt beim Ereignis `user.created` als erstes (vor der
  Willkommensmail) die echte Adresse ein.
- **Login mit Mailadresse:** Der Kern nimmt das erste Konto mit der Adresse. Die Middleware sucht das Konto, zu dem
  das Passwort passt, und meldet mit dessen Benutzernamen an.
- **Passwort vergessen:** Bei mehreren Konten geht pro Konto eine Mail mit dem Benutzernamen raus
  (`PasswordResetController` des Plugins, sonst unverändert der Kern).
- Mails (Dienstpläne, Infos) gehen pro Konto an die gemeinsame Adresse.
- Nicht abgedeckt: Die alte Admin-Seite „Benutzer bearbeiten“ (`admin_user`) lehnt beim Ändern auf eine schon
  verwendete Adresse weiterhin ab. Helfer können ihre Adresse selbst unter Einstellungen → Profil ändern.

Bei upstream-Merges prüfen: die Routen-Namen `RegistrationController@save`, `AuthController@postLogin`,
`PasswordResetController@postReset` und das Ereignis `user.created`.

Auf einer neuen Installation unter **Admin → Plugins** installieren und aktivieren.

## Rechte (Datenbank, nicht im Repo)

Helfer (Gruppe „Angel“) sehen im Menü nur News, Frag den Admin und Tagesplan. Dafür wurden
`user_shifts`, `user_meetings`, `angeltypes.view` und `locations.view` der Gruppe „Angel“ entzogen
und den Gruppen „Shift Coordinator“ und „Bureaucrat“ gegeben (Admin → Gruppen).
Hinweis: `/angeltypes` und `/locations` sind im Original „freie Seiten“ und per direkter URL
weiterhin erreichbar; nur der Menüpunkt ist ausgeblendet.

## Geänderte Original-Dateien

Keine. Der Grundcode entspricht upstream; alle Anpassungen liegen in `helfer/` und in den Plugins.
Bei upstream-Merges trotzdem die Plugins prüfen: sie nutzen Funktionen und Vorlagen des Kerns
(z. B. `Shift_signup_allowed()`, `pages/news/index.twig`, kopierte `pages/login.twig`).

## Orte und Helfertypen wie im Live-System

`helfer/setup/orte-mit-helfertypen.php` baut die früheren Einzel-Orte (ein Ort pro Helfertyp) um
auf 8 Orte mit mehreren Helfertypen (Atelier, Aussenbereich, Bistro, Kasse, Kerzenziehen,
Parkplatz, Wachsküche, Wachsschalen). Gleichzeitige Schichten eines Ortes werden zu einer Schicht
mit mehreren Helfertypen zusammengelegt; setzt auch die Reihenfolge im Tagesplan.
Aufruf siehe Kopf des Skripts (`--dry-run` für einen Testlauf).
