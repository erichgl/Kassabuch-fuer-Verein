# Kassabuch für Vereine (PHP)

Version 1.38.0 (24.10.2026) · (c) 2026 erich.grassl@glw8.at · Lizenz: [GNU AGPL-3.0](#lizenz)

Einfaches Web-Kassabuch mit zwei Konten – **Bank** und **Handkassa** – im Stil des
bisherigen Excel-Kassabuchs: laufender Stand je Konto, Übertrag und Zwischensummen
je Seite, Gesamtsummen und Unterschriftsblock für die Kassaprüfung.

## Aufbau des Repositorys

| Ordner / Datei | Inhalt |
|---|---|
| `index.php`, `config.php`, `.htaccess`, `.user.ini` | Programm – zentrale Steuerung, Konfiguration, Webserver-Einstellungen |
| `inc/` | Programmlogik (Datenbank, Rechte, Berichte, QR-Code, Excel, Systemprüfung, Datensicherung) |
| `views/` | Seitenvorlagen (Journal, Buchung, Bericht, Einstellungen, Hilfe …) |
| `assets/` | Stylesheets, JavaScript, Logo und Bilder der Hilfe |
| `data/` | Datenordner – im Repository **leer** (nur `.htaccess`); Datenbank, Belege und Sicherungen entstehen erst am Server und sind per `.gitignore` ausgeschlossen |
| `docs/handbuch/` | fertige Handbücher als PDF (gesamt und je Rolle) |
| `docs/quelle/` | Quelle der Handbücher (`handbuch.html`, Bilder) und Skripte: `pdf.py` (Gesamthandbuch), `rollen.py` (Rollen-Handbücher), `mache_hilfe.py` (erzeugt `views/hilfe.php`) |
| `beispiele/` | Beispieldateien für den CSV-Import und den Excel-Rundlauf (erfundene Daten) |

Zum Installieren werden nur die Programmdateien gebraucht – `docs/` und `beispiele/` müssen nicht auf den Webserver.
Die Handbücher werden mit Python 3, Playwright (Chromium), pypdf und Pillow erzeugt:
`cd docs/quelle && python3 mache_hilfe.py && python3 pdf.py && python3 rollen.py`.

## Funktionen

- **Journal** mit laufendem Stand je Konto, Filter nach Zeitraum, Konto und Suchtext, Schnellknopf „Letzte [n] Buchungen“ (Anzahl einstellbar, Standard 7)
- **Buchungen** erfassen (Eingang/Ausgang, Belegnummer wird automatisch vorgeschlagen),
  optional mit **Beleg-Foto oder PDF**: am Handy „📷 Foto aufnehmen“ oder „📎 Datei / PDF wählen“,
  mit Vorschau; Fotos werden vor dem Hochladen automatisch verkleinert
- **Handkassa nie im Minus**: Buchungen, Änderungen, Löschungen, Umbuchungen und Importe, die den Kassastand
  (auch rückwirkend) unter 0 bringen würden, werden abgewiesen
- **Umbuchungen** Bank ⇄ Handkassa (z. B. Wechselgeld) – erzeugt automatisch beide Zeilen
- **Bankspesen** (Kontoabschluss): + Habenzinsen, − Kapitalertragssteuer, − Umsatzprovision in einem Schritt
  (KESt-Vorschlag 25 %); Buchung, Umbuchung und Bankspesen werden oben in der Kachel „Neue Buchung“ gewählt
- **Bericht / Druck**: A4 quer, mit Kopfzeile (Verein, Zeitraum, Prüfstatus, Seite), Übertrag, Zwischensummen je Seite, Gesamtsummen,
  Prüf-ID, QR-Code und Unterschriftszeilen – über den Browser auch als PDF speicherbar
- **Kassaprüfung** erfassen: Zeitraum wird danach **gesperrt** (keine Änderungen mehr möglich);
  eine Vermerkzeile „Kassaprüfung … i. O.“ mit Prüfern, Ständen und Prüf-ID wird automatisch ins Kassabuch geschrieben.
  Die **Prüf-ID** ist ein Fingerabdruck aller Buchungen – die App zeigt an, ob sich
  seit der Prüfung etwas geändert hat
- **Benutzer & Rollen**: Administrator, Kassaführung (bucht), Kassaprüfer (liest, druckt, erfasst Prüfung),
  Gast (nur lesen und drucken, keine Änderungen – außer am eigenen Passwort);
  E-Mail-Adressen von Kassaführung und Kassaprüfern erscheinen im Journal als Kontakt-Links
- **Wartungsmodus** (Einstellungen): nur Administratoren können sich anmelden, alle anderen sehen einen Hinweis;
  **Systemprüfung** von Provider-/Serverdaten, PHP-Einstellungen, Dateirechten und PHP-Modulen (mit Bewertung, Textexport, Protokoll)
- **Protokoll** aller Änderungen (wer, wann, vorher/nachher); An- und Abmeldungen (mit Sitzungsdauer) sowie Fehlversuche mit IP-Adresse und Browser
- **CSV-Export** (öffnet direkt in Excel) und **Datensicherung** als ZIP (Datenbank + Belege)

## Voraussetzungen

- Webspace mit **PHP 8.1 oder neuer** und der Erweiterung **pdo_sqlite**
  (bei fast allen Anbietern Standard, z. B. easyname, World4You, all-inkl)
- Keine eigene MySQL-Datenbank nötig – die Daten liegen in einer einzigen Datei.

## Anpassen an den eigenen Verein

- **Vereinsname, Personen, Anfangsstände** werden bei der Ersteinrichtung bzw. unter *Einstellungen* gepflegt.
- **Logo und Banner:** `assets/img/logo.png` (quadratisch), `assets/img/banner.jpg` (ca. 940 × 250 px, Anmeldeseite)
  und `assets/img/hintergrund.png` (dezentes Wasserzeichen) durch eigene Bilder gleichen Namens ersetzen.
  Im Repository liegen neutrale Bilder für den „Musterverein Beispielsdorf“.
- **Links** in `config.php`: `HANDBUCH_BASIS` (Internet-Ordner der PDF-Handbücher – Links auf der Hilfe-Seite und
  Standardziel des QR-Codes im Bericht) und `QUELLCODE_LINK` (Link „Quellcode (AGPL-3.0)“ in der Fußzeile).
- **Handbücher für den eigenen Verein** erzeugen: im Ordner `docs/quelle` z. B.
  `HANDBUCH_VEREIN="Mein Verein" python3 pdf.py` bzw. `rollen.py` – Deckblatt, QR-Codes und Links richten sich nach `config.php`.

## Installation

1. Den Ordner `kassabuch` per FTP auf den Webspace hochladen
   (z. B. nach `https://www.mein-verein.at/kassabuch/`).
2. Sicherstellen, dass PHP in den Ordner `data` schreiben darf
   (meist automatisch; sonst per FTP-Programm Rechte 770 bzw. 775 setzen).
3. Die Adresse im Browser öffnen. Beim ersten Aufruf erscheint die **Einrichtung**:
   Vereinsname, Ihr Zugang sowie **Startdatum und Anfangsstände** (= „Übertrag“ aus dem
   bisherigen Kassabuch, z. B. Bank 4.250,00 / Handkassa 180,00).
4. Unter **Einstellungen** Kassaprüfer und Kassaführung eintragen,
   unter **Benutzer** weitere Zugänge anlegen.

**Bitte unbedingt HTTPS verwenden** (bei den meisten Anbietern kostenlos per Let's Encrypt).

## Datenschutz: Datenordner schützen

Die Datenbank und die Belege liegen standardmäßig im Ordner `data`.

- **Apache** (die meisten Webspaces): bereits durch die mitgelieferte `.htaccess` gesperrt.
- **Besser / bei nginx**: In `config.php` einen Ordner **außerhalb** des Web-Verzeichnisses
  eintragen, z. B. `define('DATA_DIR', '/home/meinverein/kassabuch-daten');`
- nginx-Alternative:

  ```nginx
  location ~ ^/kassabuch/(data|inc|views)/ { deny all; }
  location = /kassabuch/config.php { deny all; }
  ```

Ist die Datenbank trotzdem aus dem Internet abrufbar, zeigt die App Administratoren
eine rote Warnung im Journal.

## Arbeitsablauf

1. Laufend buchen – Belegnummern werden fortlaufend vorgeschlagen und beginnen nach
   jeder Kassaprüfung wieder bei 1.
2. Vor der Prüfung: **Kassaprüfung → „Bericht für die Prüfung drucken“**.
3. Nach der Prüfung: Prüfung erfassen (Datum, Prüfer, „geprüft bis“). Der Zeitraum wird
   gesperrt, der Bericht zeigt dann das Prüfdatum und die Namen.
4. Nur der Administrator kann eine Sperre unter Einstellungen wieder aufheben (wird protokolliert).

## Bisheriges Kassabuch übernehmen (CSV-Import)

**Einstellungen → Buchungen importieren (CSV)** (nur Administrator). Format wie beim Export:

```
Datum;Beleg Nr.;Beschreibung;Bank Eingang;Bank Ausgang;Bank Stand;Handkassa Eingang;Handkassa Ausgang;Handkassa Stand
01.01.2026;;Übertrag;;;5.000,00;;;150,00
15.01.2026;1;Vereinssitzung Gasthaus Zur Linde;;;;;42,50;107,50
```

- Eine **Übertrag**-Zeile am Anfang setzt Startdatum und Anfangsstände (nur bei leerem Kassabuch).
- Die Stand-Spalten sind freiwillig; wenn vorhanden, wird **jede Zeile nachgerechnet** und
  jede Abweichung mit Zeilennummer gemeldet. Gespeichert wird erst nach Bestätigung.
- Pro Zeile nur ein Konto; Umbuchungen als zwei Zeilen.
- Weitere Dateien können später angehängt werden – deren Übertrag muss dann zum aktuellen Stand passen.
- **Nachtrag vor dem Beginn:** Übertrag-Zeile mit Datum vor dem bisherigen Beginn; der Endstand der Datei muss genau den
  bisherigen Anfangsständen entsprechen. Startdatum und Anfangsstände werden dann vorverlegt, Prüfungen bleiben gültig.

## Mit Excel bearbeiten

**Einstellungen → 📊 Mit Excel bearbeiten** (nur Administrator): alle Buchungen als `.xlsx` herunterladen, in Excel
ändern/löschen/einfügen (auch vor dem Beginn und am Ende) und wieder einlesen – oder die Zellen kopieren und einfügen.
Vor dem Speichern wird alles probeweise durchgerechnet (Kassaprüfungen bleiben gültig, Handkassa nie im Minus,
Umbuchungen vollständig), eine Übersicht aller Änderungen gezeigt und automatisch eine Sicherung angelegt
(`data/sicherungen/`, die letzten 10).

## Datensicherung

**Einstellungen → Datensicherung herunterladen** lädt ein ZIP mit der Datenbank und allen
Belegen. Zum Wiederherstellen `kassabuch.sqlite` und den Ordner `belege` in den Datenordner kopieren.

**Automatische Sicherung:** alle 3 Monate legt das Kassabuch selbst eine Sicherung in derselben Form in
`data/sicherungen/auto/` an (beim ersten Seitenaufruf nach dem Fälligkeitstag – kein Cron nötig). Es bleiben
die letzten 7 Sicherungen erhalten (21 Monate). Unter **Einstellungen → 💾 Automatische Sicherung** stehen sie zum Download
bereit; „Jetzt Sicherung anlegen“ sichert sofort. Einstellbar in `inc/sicherung.php`
(`AUTO_SICHERUNG_MONATE`, `AUTO_SICHERUNG_BEHALTEN`).

## Hinweise

- **403-Fehler beim Buchen** nach einem Provider-Update kommen von dessen Firewall (ModSecurity), nicht vom Kassabuch.
  Der Formular-Test in der Systemprüfung zeigt, was blockiert wird; der Provider kann eine Ausnahme einrichten.

- Der linke QR-Code auf der letzten Berichtsseite führt standardmäßig zum Ordner der Handbücher (`HANDBUCH_BASIS` in `config.php`); in den Einstellungen änderbar.
  (unter Einstellungen änderbar). Er wird direkt am Server erzeugt – keine externen Dienste.
- Jeder erzeugte Bericht erhält eine **eigene Bericht-ID (UUID)** in der Fußzeile jeder Seite. Unter
  **Kassaprüfung → Ausgedruckten Bericht überprüfen** zeigt sie Ersteller, Zeitpunkt, Zeitraum und
  ob sich die Buchungen seither geändert haben.
- Versionsnummer und Copyright stehen am Fuß jeder Web-Seite und jeder Berichtsseite
  (`VERSION` in `inc/lib.php`).
- Beträge werden intern in Cent gespeichert – keine Rundungsfehler.
- Fotos/PDFs bis 10 MB (in `config.php` änderbar; ggf. auch `.user.ini` anpassen).

## Dateien

```
index.php          Steuerung (alle Seiten)
config.php         Einstellungen für den Server
inc/lib.php        Datenbank, Berechnungen, Anmeldung
inc/system.php     Systemprüfung (Wartung)
inc/excel.php      Excel-Rundlauf (Export/Import .xlsx)
views/             Seitenvorlagen (_kopf/_fuss/_buchungsart = gemeinsame Teile)
assets/            Gestaltung (CSS) und kleine Skripte
assets/hilfe/      Bilder der Hilfe-Seite (Benutzerhandbuch als HTML, views/hilfe.php)
assets/img/        Vereinsbilder: banner.jpg (Anmeldeseite), logo.png (Kopfzeile, Favicon),
                   hintergrund.png (Wasserzeichen in App und Ausdruck) – einfach austauschbar
data/              Datenbank und Belege (wird automatisch angelegt)
```

## Lizenz

Copyright (c) 2026 Erich Grassl (erich.grassl@glw8.at)

Das Kassabuch ist freie Software: Sie können es unter den Bedingungen der **GNU Affero General Public License**,
wie von der Free Software Foundation veröffentlicht, weitergeben und/oder verändern – entweder gemäß Version 3
der Lizenz oder (nach Ihrer Wahl) jeder späteren Version (SPDX: `AGPL-3.0-or-later`).

Das Programm wird in der Hoffnung verbreitet, dass es nützlich ist, aber **ohne jede Gewährleistung** – sogar ohne
die implizite Gewährleistung der Marktreife oder der Eignung für einen bestimmten Zweck. Einzelheiten stehen in der
Datei [`LICENSE`](LICENSE) (vollständiger Lizenztext).

**Wichtig bei Betrieb im Internet (§ 13 AGPL):** Wer das Kassabuch – auch in veränderter Form – auf einem Webserver
für andere bereitstellt, muss deren Benutzern den Quellcode der verwendeten Fassung anbieten. Dafür steht in der
Fußzeile jeder Seite der Link „Quellcode (AGPL-3.0)“ (`QUELLCODE_LINK` in `config.php`); bei Änderungen am
Programm muss er auf die geänderte Fassung zeigen.

Alle Namen, Firmen und Beträge in den Handbüchern, Bildern und Beispieldateien sind **frei erfunden**
(„Musterverein Beispielsdorf“); Ähnlichkeiten mit realen Personen oder Unternehmen sind zufällig.

## Versionen

Jede Änderung – am Programm, an der Hilfe oder an den Handbüchern – bekommt eine neue Versionsnummer:
**kleine Änderungen** (Korrekturen, Texte, kleine Anpassungen) erhöhen die **rechte Zahl** (z. B. 1.36.0 → 1.36.1),
**neue Funktionen** die **mittlere Zahl** (z. B. 1.36.1 → 1.37.0). Die Versionsnummer steht in `inc/lib.php` (`VERSION`), auf der Anmeldeseite, in der Fußzeile
jeder Seite und jedes Berichts, auf den Deckblättern der Handbücher und im Namen des Gesamtpakets.

- **1.38.0** (24.10.2026) – Freie Software unter GNU AGPL-3.0: Link „Quellcode (AGPL-3.0)“ in der Fußzeile; Links für Handbücher und Quellcode in `config.php` (`HANDBUCH_BASIS`, `QUELLCODE_LINK`); Banner-Beschriftung aus dem Vereinsnamen; Handbücher, Hilfe, Bilder und Beispiele mit erfundenen Daten (Musterverein Beispielsdorf); Handbuch-Skripte mit Platzhaltern für Vereinsname, Version und Handbuch-Adresse
- **1.37.0** (24.10.2026) – Journal: Filter „Buchungs-ID“ – zeigt die Buchung (bei Umbuchungen beide Zeilen) und alle Korrekturbuchungen, die diese Buchungs-ID in der Beschreibung nennen, mit Hinweis (Datum, Anzahl Korrekturen, ggf. wann/von wem gelöscht); Handbücher und Hilfe ergänzt
- **1.36.1** (23.10.2026) – Rollen-Handbücher: QR-Codes auf Deckblatt und Übersichtsseite im PDF anklickbar; Regel für Versionsnummern festgehalten
- **1.36.0** (23.10.2026) – Hilfe-Seite: ausführliches Inhaltsverzeichnis (Kapitel, Abschnitte, Unterabschnitte), Kapitel-Navigation (vorheriges/nächstes), schwebende Knöpfe ☰ / A–Z / ▲ und Stichwortverzeichnis A–Z mit Suchfeld; Handbücher und Hilfe: Korrekturbuchung mit Angabe der Buchungs-ID der korrigierten Buchung (4.5, 7.1, Häufige Fragen); PDF-Handbuch mit anklickbarem Inhaltsverzeichnis und Lesezeichen
- **1.35.0** (23.10.2026) – Auch der Gast kann sein eigenes Passwort ändern (und muss ein vergebenes Anfangspasswort wie alle anderen bei der ersten Anmeldung ändern); Rollen-Handbücher: am Ende von „Ihre Rolle auf einen Blick“ die QR-Codes aller vier Rollen-Handbücher
- **1.34.1** (23.10.2026) – Automatische Sicherung: es bleiben die letzten 7 Sicherungen erhalten (statt 6)
- **1.34.0** (23.10.2026) – Bericht: zweiter QR-Code „Bericht aufrufen & prüfen“ auf der letzten Seite; Scannen öffnet genau diesen Bericht (nach Anmeldung, Ziel wird gemerkt) mit Hinweis, ob die Buchungen seit dem Ausdruck unverändert sind (dann gleiche Bericht-ID); Nachprüfung verlinkt den Bericht; Aufrufe im Protokoll
- **1.33.0** (23.10.2026) – Automatische Datensicherung alle 3 Monate (ZIP mit Datenbank und Belegen in data/sicherungen/auto/, ohne Cron beim ersten Seitenaufruf nach Fälligkeit), es bleiben die letzten 6; Liste mit Download und „Jetzt Sicherung anlegen“ unter Einstellungen; Protokoll und Systemprüfung ergänzt
- **1.32.0** (23.10.2026) – Journal-Navigation für lange Listen: Monatsleiste je Jahr (Sprung zur ersten Buchung des Monats, aktueller Monat markiert), ▲ Anfang / ▼ Ende, schwebende Knöpfe ▲ 📅 ▼ beim Blättern
- **1.31.0** (23.10.2026) – Handbücher je Rolle (Administrator, Kassaführung, Kassaprüfer, Gast) als PDF; Hilfe-Seite verlinkt die Kurzfassung der eigenen Rolle; QR-Code auf den Deckblättern
- **1.30.0** (23.10.2026) – Mit Excel bearbeiten: Export aller Buchungen als .xlsx (mit ID, Übertrag, Stand-Formeln, Anleitung), Einlesen per Upload oder Einfügen, Abgleich neu/geändert/gelöscht mit Prüfung aller Regeln, automatische Sicherung
- **1.29.0** (23.10.2026) – Buchung/Bankspesen ohne Beleg als normales Formular (umgeht 403 durch Provider-Firewalls bei Upload-Formularen), mit Beleg nur ein Dateifeld; Systemprüfung: Formular-Test (normal / Upload ohne / mit Datei)
- **1.28.0** (23.10.2026) – CSV-Import: ältere Buchungen vor dem Beginn des Kassabuchs nachtragen (Endstand muss den bisherigen Anfangsständen entsprechen; Startdatum/Anfangsstände werden vorverlegt, Prüfungen bleiben gültig)
- **1.27.0** (23.10.2026) – Systemprüfung erweitert: SQLite-Schreib-, Sperr- und Sicherungstest, Tabellen-Vollständigkeit, gründliche Integritätsprüfung, ungenutzter Platz; alle Programm- und Belegdateien, .htaccess/.user.ini, Upload-Temp- und Sitzungsordner, Besitzer-Abgleich
- **1.26.0** (23.10.2026) – Wartung: Systemprüfung (Server & Provider, PHP-Einstellungen, Datenbank & Speicher, Dateirechte, PHP-Module) mit Bewertung und Textdownload; wichtigste Werte werden protokolliert, auch beim Ein-/Ausschalten der Wartung
- **1.25.0** (19.10.2026) – Belegfeld handytauglich: Knöpfe „Foto aufnehmen“ (Kamera) und „Datei / PDF wählen“, Vorschau, automatisches Verkleinern von Fotos (max. 2000 px, JPEG)
- **1.24.0** (19.10.2026) – Journal: Tooltip je Zeile mit Buchungs-ID, Erfassung (wann/wer) und letzter Änderung
- **1.23.0** (19.10.2026) – Journal: Anzahl der angezeigten Buchungen in der Überschrift („35 Buchungen ab …“)
- **1.22.0** (19.10.2026) – Journal: „Letzte [n] Buchungen“ mit einstellbarer Anzahl (vorbelegt 7, Enter im Feld löst aus)
- **1.21.0** (19.10.2026) – Buchungsnummern (id) werden nie doppelt vergeben (AUTOINCREMENT, Datenbank-Stufe 6); Journal mit 4-stelliger Jahreszahl; Abschnitt „Protokoll“ aus Handbuch und Hilfe entfernt
- **1.20.0** (19.10.2026) – Bericht: Kopfzeile auf jeder Seite (Logo und Verein, Titel und Zeitraum, „geprüft am“, Seitenzahl)
- **1.19.0** (19.10.2026) – Wartungsmodus in den Einstellungen: nur Administratoren können sich anmelden, Hinweis für alle anderen, angemeldete Benutzer werden abgemeldet
- **1.18.0** (19.10.2026) – Kassaprüfung erzeugt eine geschützte Vermerkzeile im Kassabuch; Protokoll: Programmversion bei der Anmeldung, Anzahl der Einträge je Art
- **1.17.0** (19.10.2026) – Protokoll: Aktionen pastellfarben nach Art hinterlegt, Farblegende als Filter
- **1.16.0** (19.10.2026) – Handkassa darf nicht ins Minus gehen (Prüfung bei Buchen, Ändern, Löschen, Umbuchung, Import – auch rückdatiert); aktueller Kassastand im Buchungsformular
- **1.15.0** (19.10.2026) – Nach jeder Buchung, Umbuchung und den Bankspesen zeigt das Journal die letzten 7 Buchungen, die gespeicherten Zeilen sind hervorgehoben
- **1.14.0** (19.10.2026) – Umbuchung aus dem Menü in die Kachel „Neue Buchung“ verschoben (Auswahl Buchung / Umbuchung / Bankspesen); neuer Knopf „Bankspesen“ bucht Habenzinsen, Kapitalertragssteuer und Umsatzprovision in einem Schritt
- **1.13.0** (19.10.2026) – Neue Rolle „Gast“: nur ansehen und drucken, keine Änderungen, kein eigenes Passwort ändern (gemeinsam nutzbar), nicht in der Kontaktliste
- **1.12.0** (19.10.2026) – Journal: Knopf „Letzte 7 Buchungen“ (mit Übertrag unmittelbar davor, kombinierbar mit Konto- und Suchfilter)
- **1.11.0** (19.10.2026) – Protokoll: Abmeldung mit Sitzungsdauer, IP und Browser; automatische Abmeldung wegen Inaktivität wird ebenfalls protokolliert
- **1.10.0** (19.10.2026) – Protokoll: Anmeldung mit IP-Adresse und Browser (Kurzform + vollständige Kennung), fehlgeschlagene Anmeldungen mit Benutzername/IP/Browser, Details lesbar als „Feld: Wert“
- **1.9.0** (19.10.2026) – E-Mail-Adresse je Benutzer; Journal zeigt neben Bank/Handkassa/Gesamt eine Kontakt-Kachel mit E-Mail-Links (alle außer Administratoren) und „E-Mail an alle“
- **1.8.0** (19.10.2026) – Bericht/Druck: Umschalter „Nur letzte Seite“ (Gesamtsummen, Prüfvermerk, Unterschriften)
- **1.7.0** (19.10.2026) – Hilfe als HTML-Seite (Handbuch mit Bildern und Inhaltsverzeichnis, Link zur PDF-Fassung am Anfang); ausführliche deutsche Kommentare im Quellcode
- **1.6.0** (19.10.2026) – „Passwort ändern“ für alle Benutzer deutlicher; vom Administrator vergebene Passwörter müssen bei der nächsten Anmeldung geändert werden
- **1.5.0** (19.10.2026) – Menüpunkt „Hilfe“ mit eingebautem Benutzerhandbuch, Kapitel-Sprungmarken und Link zur Online-Fassung
- **1.4.0** (19.10.2026) – Bericht/Druck: Schnellauswahl „Laufendes Jahr“, „Letztes Quartal“, „Ab letzter Kassaprüfung“
- **1.3.1** (19.10.2026) – Rolle (Administrator / Kassaführung / Kassaprüfer) auf allen Seiten sichtbar
- **1.3.0** (19.10.2026) – Jeder Bericht trägt eine eigene Bericht-ID (UUID) in der Fußzeile; Nachprüfung unter „Kassaprüfung“
- **1.2.1** (19.10.2026) – Bericht: Eingang/Ausgang der Handkassa grau hinterlegt
- **1.2.0** (19.10.2026) – QR-Code im Bericht, Versionsnummer und Copyright auf allen Seiten
- **1.1.0** (19.10.2026) – CSV-Import mit Nachrechnen aller Stände, Vereinslogo/Banner/Wasserzeichen
- **1.0.0** (19.10.2026) – erste Version
