<?php
/*
 * =====================================================================
 *  Kassabuch – zentrale Steuerung (index.php)
 * =====================================================================
 *
 *  Jede Seite des Programms wird über diese eine Datei aufgerufen. Welche
 *  Seite gemeint ist, steht im Parameter „a“ (Aktion) der Adresse, z. B.
 *
 *      index.php?a=journal          → Journal anzeigen
 *      index.php?a=buchung&id=17    → Buchung Nr. 17 bearbeiten
 *      index.php?a=bericht          → Bericht / Druckansicht
 *
 *  Ablauf jedes Aufrufs:
 *    1. Bibliothek laden, Sitzung starten
 *    2. Bei abgeschickten Formularen (POST) das CSRF-Token prüfen
 *    3. Gibt es noch keinen Benutzer → Ersteinrichtung anzeigen
 *    4. Anmeldung bearbeiten; ohne Anmeldung → zur Anmeldeseite
 *    5. Muss der Benutzer sein Passwort ändern → nur Passwort-Seite erlauben
 *    6. Die gewünschte Aktion ausführen (großer switch-Block unten)
 *
 *  Jede Aktion prüft zuerst die nötigen Rechte (brauche()), liest bzw.
 *  speichert Daten und gibt am Ende mit render() eine Vorlage aus views/ aus.
 *  Nach dem Speichern wird immer weitergeleitet (umleiten()), damit ein
 *  Neuladen der Seite das Formular nicht ein zweites Mal abschickt.
 *
 *  Alle Funktionen (db, e, geld, brauche, render …) stehen in inc/lib.php.
 */
declare(strict_types=1);

require __DIR__ . '/inc/lib.php';
require __DIR__ . '/inc/system.php';   // Systemprüfung (Wartung)
require __DIR__ . '/inc/excel.php';    // Excel-Rundlauf (alle Buchungen bearbeiten)
require __DIR__ . '/inc/sicherung.php'; // Datensicherung, automatische Sicherung alle 3 Monate

sitzung_starten();

// Gewünschte Aktion (Seite) und ob ein Formular abgeschickt wurde
$a = (string)($_GET['a'] ?? 'journal');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
// Jedes abgeschickte Formular muss das geheime Sitzungs-Token enthalten (Schutz vor gefälschten Anfragen)
if ($post) csrf_pruefen();

/* =====================================================================
 * Ersteinrichtung
 * Solange es keinen einzigen Benutzer gibt, wird statt aller anderen
 * Seiten das Einrichtungsformular gezeigt. Wer es ausfüllt, wird der
 * erste Administrator. Danach ist diese Seite nie wieder erreichbar.
 * ===================================================================== */
$hatBenutzer = (int)db()->query('SELECT COUNT(*) FROM benutzer')->fetchColumn() > 0;
if (!$hatBenutzer) {
    if ($post) {
        $f = $_POST;
        $fehler = [];
        // Eingaben prüfen – alle Fehler sammeln und gemeinsam anzeigen
        if (trim($f['vereinsname'] ?? '') === '') $fehler[] = 'Bitte den Vereinsnamen angeben.';
        if (!preg_match('/^[\w.@-]{3,40}$/u', $f['login'] ?? '')) $fehler[] = 'Der Benutzername muss 3–40 Zeichen lang sein (Buchstaben, Ziffern, . _ - @).';
        if (trim($f['name'] ?? '') === '') $fehler[] = 'Bitte Ihren Namen angeben.';
        if (mb_strlen($f['pass'] ?? '') < 8) $fehler[] = 'Das Passwort muss mindestens 8 Zeichen haben.';
        if (($f['pass'] ?? '') !== ($f['pass2'] ?? '')) $fehler[] = 'Die Passwörter stimmen nicht überein.';
        if (!iso_ok($f['start_datum'] ?? '')) $fehler[] = 'Bitte ein gültiges Startdatum angeben.';
        $sb = parse_betrag($f['start_bank'] ?? '0') ?? null;    // Anfangsstand Bank in Cent
        $sk = parse_betrag($f['start_kassa'] ?? '0') ?? null;   // Anfangsstand Handkassa in Cent
        if ($sb === null || $sk === null) $fehler[] = 'Bitte gültige Anfangsstände angeben (z. B. 4.250,00).';
        if (!$fehler) {
            // Ersten Benutzer als Administrator anlegen; das Passwort wird nur als Hash gespeichert
            db()->prepare('INSERT INTO benutzer (login, name, pass, rolle) VALUES (?, ?, ?, ?)')
                ->execute([trim($f['login']), trim($f['name']), password_hash($f['pass'], PASSWORD_DEFAULT), 'admin']);
            $neueId = (int)db()->lastInsertId();   // gleich merken – spätere INSERTs überschreiben lastInsertId
            einst_setzen('vereinsname', trim($f['vereinsname']));
            einst_setzen('kassier', trim($f['name']));
            einst_setzen('start_datum', $f['start_datum']);
            einst_setzen('start_bank', $sb);
            einst_setzen('start_kassa', $sk);
            einst_setzen('zeilen_pro_seite', 22);
            // Direkt anmelden (neue Sitzungs-ID gegen „Session Fixation“)
            session_regenerate_id(true);
            $_SESSION['uid'] = $neueId;
            protokoll('Kassabuch eingerichtet', ['start' => $f['start_datum'], 'bank' => $sb, 'kassa' => $sk]);
            flash('ok', 'Das Kassabuch ist eingerichtet. Unter „Einstellungen“ können Sie Kassaprüfer usw. eintragen.');
            umleiten('journal');
        }
        render('setup', ['fehler' => $fehler, 'f' => $f]);
    }
    render('setup', ['fehler' => [], 'f' => ['start_datum' => date('Y-01-01')]]);
}

/* =====================================================================
 * Anmeldung
 * ===================================================================== */
if ($a === 'login') {
    $fehler = '';
    if ($post) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (login_gesperrt($ip)) {
            // Zu viele Fehlversuche von dieser Adresse – vorübergehend keine Anmeldung möglich
            $fehler = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        } else {
            $st = db()->prepare('SELECT * FROM benutzer WHERE login = ? AND aktiv = 1');
            $st->execute([trim((string)($_POST['login'] ?? ''))]);
            $u = $st->fetch();
            // password_verify vergleicht das eingegebene Passwort mit dem gespeicherten Hash
            if ($u && password_verify((string)($_POST['pass'] ?? ''), $u['pass']) && wartung_aktiv() && $u['rolle'] !== 'admin') {
                // Wartungsmodus: richtiges Passwort, aber nur Administratoren dürfen hinein.
                // Zählt NICHT als Fehlversuch (keine Sperre der IP-Adresse).
                protokoll('Anmeldung abgelehnt (Wartung)', ['benutzer' => $u['name']] + geraet_info());
                $fehler = 'Anmeldung derzeit nicht möglich – das Kassabuch wird gewartet.';
                render('login', ['fehler' => $fehler]);
            }
            if ($u && password_verify((string)($_POST['pass'] ?? ''), $u['pass'])) {
                session_regenerate_id(true);       // neue Sitzungs-ID nach der Anmeldung
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['seit'] = time();          // Anmeldezeitpunkt (für die Sitzungsdauer im Protokoll)
                // Falls PHP inzwischen ein stärkeres Hash-Verfahren hat: Hash erneuern
                if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE benutzer SET pass = ? WHERE id = ?')
                        ->execute([password_hash($_POST['pass'], PASSWORD_DEFAULT), $u['id']]);
                }
                // Anmeldung mit IP-Adresse und Browser protokollieren
                protokoll('Anmeldung', ['version' => VERSION] + geraet_info());
                $ziel = (string)($_SESSION['nach_login'] ?? '');
                unset($_SESSION['nach_login']);
                if ($ziel !== '') { header('Location: index.php?' . $ziel); exit; }
                umleiten('journal');
            }
            // Fehlversuch merken und kurz warten – macht automatisches Passwort-Raten langsam
            db()->prepare('INSERT INTO fehlversuche (ip, zeit) VALUES (?, ?)')->execute([$ip, time()]);
            // Auch Fehlversuche protokollieren (eingegebener Benutzername, IP, Browser) –
            // so sind Rateversuche erkennbar. Das Passwort wird natürlich NIE gespeichert.
            protokoll('Anmeldung fehlgeschlagen',
                ['benutzername' => mb_substr(trim((string)($_POST['login'] ?? '')), 0, 40)] + geraet_info());
            usleep(700000);
            // Bewusst keine Unterscheidung „Benutzer unbekannt“ / „Passwort falsch“ (verrät nichts)
            $fehler = 'Benutzername oder Passwort ist falsch.';
        }
    }
    render('login', ['fehler' => $fehler]);
}

// Alle weiteren Seiten nur für angemeldete Benutzer
if (!user()) {
    // Aufgerufene Seite merken (z. B. Bericht über den QR-Code eines Ausdrucks) und nach der Anmeldung dorthin
    if (!$post && $a !== 'logout' && preg_match('/^[A-Za-z0-9_=&%.:-]{1,300}$/', (string)($_SERVER['QUERY_STRING'] ?? ''))) {
        $_SESSION['nach_login'] = (string)$_SERVER['QUERY_STRING'];
    }
    umleiten('login');
}

// Wartungsmodus: angemeldete Nicht-Administratoren werden abgemeldet und sehen den Hinweis
if (wartung_aktiv() && user()['rolle'] !== 'admin') {
    protokoll('Abmeldung (Wartung)', sitzung_info() + geraet_info());
    $_SESSION = [];
    session_regenerate_id(true);
    umleiten('login');
}

// Vom Administrator vergebenes Passwort muss zuerst geändert werden:
// bis dahin sind nur die Passwort-Seite, Abmelden und die Hilfe erreichbar.
if (!empty(user()['pw_aendern']) && darf('konto') && !in_array($a, ['passwort', 'logout', 'hilfe'], true)) {
    umleiten('passwort');
}

// Automatische Sicherung: ist die letzte älter als 3 Monate, wird jetzt eine angelegt
// (beim ersten Seitenaufruf nach dem Fälligkeitstag – ein Zeitplan am Server ist nicht nötig)
if (!$post) auto_sicherung_pruefen();

/* =====================================================================
 * Ab hier: angemeldete Benutzer – Auswahl der Seite
 * ===================================================================== */
switch ($a) {

/* ---------- Abmelden (nur per Formular/POST, damit fremde Links nicht abmelden können) ---------- */
case 'logout':
    if ($post) {
        // Abmeldung mit Sitzungsdauer, IP-Adresse und Browser protokollieren
        protokoll('Abmeldung', sitzung_info() + geraet_info());
        $_SESSION = [];
        session_regenerate_id(true);
    }
    umleiten('login');

/* ---------- Journal: Buchungsliste mit laufenden Ständen ---------- */
case 'journal':
    // Filter aus der Adresse übernehmen – ungültige Werte werden ignoriert
    $von = iso_ok($_GET['von'] ?? '') ? $_GET['von'] : offener_zeitraum_von();   // Standard: ab letzter Prüfung
    $bis = iso_ok($_GET['bis'] ?? '') ? $_GET['bis'] : '';                       // leer = ohne Ende
    $konto = isset(KONTEN[$_GET['konto'] ?? '']) ? $_GET['konto'] : '';
    $q = trim((string)($_GET['q'] ?? ''));                                         // Suchtext
    // Knopf „Letzte 7 Buchungen“: ?letzte=7 – dann über ALLE Buchungen ab Startdatum rechnen
    // und nur die letzten Zeilen anzeigen (Zeitraum-Filter wird dabei ignoriert)
    // Anzahl kommt entweder aus dem Feld neben dem Knopf (letzte_n + Knopf nur_letzte)
    // oder direkt aus der Adresse (?letzte=7, z. B. nach dem Speichern einer Buchung)
    $letzte = isset($_GET['nur_letzte']) ? (int)($_GET['letzte_n'] ?? 7) : (int)($_GET['letzte'] ?? 0);
    $letzte = $letzte ? max(1, min(500, $letzte)) : 0;
    // Filter „Buchungs-ID“: genau diese Buchung (bei einer Umbuchung beide Zeilen) und alle
    // Korrekturbuchungen, die sich in der Beschreibung darauf beziehen („… Buchungs-ID 57 …“).
    // Zeitraum, Konto, Suche und „Letzte“ gelten dabei nicht.
    $bid = isset($_GET['nur_letzte']) ? 0 : max(0, (int)($_GET['bid'] ?? 0));   // Knopf „Letzte“ hat Vorrang
    $bidInfo = null;   // ['buchung' => Zeile|null, 'korrekturen' => Anzahl, 'geloescht' => Protokolleintrag|null]
    if ($bid) {
        $letzte = 0; $konto = ''; $q = '';
        $von = start_datum(); $bis = '';
    }
    if ($letzte) { $von = start_datum(); $bis = ''; }
    $start = saldo_vor($von);                                                      // Übertrag
    // Stände IMMER über alle Buchungen des Zeitraums rechnen …
    [$zeilen, $ende, $summen] = mit_staenden(buchungen($von, $bis ?: null), $start);
    $gefiltert = $konto !== '' || $q !== '';
    if ($gefiltert) {
        // … und erst danach nach Konto/Suchtext filtern, damit die Stand-Spalte richtig bleibt
        $zeilen = array_values(array_filter($zeilen, function ($r) use ($konto, $q) {
            if ($konto !== '' && $r['konto'] !== $konto) return false;
            if ($q !== '' && mb_stripos($r['text'] . ' ' . $r['beleg'], $q) === false) return false;
            return true;
        }));
        // Summen nur über die angezeigten Zeilen bilden
        $summen = ['bank' => ['ein' => 0, 'aus' => 0], 'kassa' => ['ein' => 0, 'aus' => 0]];
        foreach ($zeilen as $r) { $summen[$r['konto']]['ein'] += $r['ein']; $summen[$r['konto']]['aus'] += $r['aus']; }
    }
    if ($bid) {
        $haupt = null;
        foreach ($zeilen as $r) if ((int)$r['id'] === $bid) $haupt = $r;
        $bezug = '/Buchungs-?ID\s*:?\s*' . $bid . '(?!\d)/iu';   // „Buchungs-ID 57“, „Buchungs-ID: 57“, „BuchungsID 57“
        $partner = fn($r) => $haupt && $haupt['gruppe'] && $r['gruppe'] === $haupt['gruppe'];
        $zeilen = array_values(array_filter($zeilen, fn($r) => (int)$r['id'] === $bid || $partner($r) || preg_match($bezug, (string)$r['text'])));
        $bidInfo = ['buchung' => $haupt, 'korrekturen' => 0, 'geloescht' => null];
        foreach ($zeilen as $r) if ((int)$r['id'] !== $bid && !$partner($r)) $bidInfo['korrekturen']++;
        if (!$haupt) {
            // Nicht (mehr) vorhanden? Im Protokoll nach dem Löschen suchen
            $st = db()->prepare("SELECT zeit, benutzer, details FROM protokoll WHERE aktion IN ('Buchung gelöscht', 'Umbuchung gelöscht') AND details LIKE ? ORDER BY id DESC");
            $st->execute(['%"id":' . $bid . '%']);
            foreach ($st as $p) {
                $d = json_decode((string)$p['details'], true);
                if ((int)($d['id'] ?? 0) === $bid) { $bidInfo['geloescht'] = $p + ['daten' => $d]; break; }
            }
        }
        if ($haupt) $_SESSION['markiert'] = ['ids' => [$bid], 'datum' => null];   // Zeile hervorheben
    }
    if (($letzte || $bid) && $zeilen) {
        // Übertrag = Stände unmittelbar vor der ersten angezeigten Zeile. Dazu die Stände
        // aller weggelassenen Zeilen (ohne Konto-/Suchfilter!) der Reihe nach durchgehen.
        if ($letzte) $zeilen = array_slice($zeilen, -$letzte);   // höchstens die letzten n Zeilen
        $erste = $zeilen[0];
        foreach (mit_staenden(buchungen($von, null), $start)[0] as $r) {
            if ((int)$r['id'] === (int)$erste['id']) break;
            $start[$r['konto']] = $r['stand'];
        }
        $von = $erste['datum'];                 // für Überschrift, Drucken und CSV-Export
        // Summen nur über die angezeigten Zeilen
        $summen = ['bank' => ['ein' => 0, 'aus' => 0], 'kassa' => ['ein' => 0, 'aus' => 0]];
        foreach ($zeilen as $r) { $summen[$r['konto']]['ein'] += $r['ein']; $summen[$r['konto']]['aus'] += $r['aus']; }
    }
    // Nach dem Speichern: gerade gebuchte Zeilen hervorheben (einmalig, aus der Sitzung)
    $markiert = $_SESSION['markiert'] ?? ['ids' => [], 'datum' => null];
    unset($_SESSION['markiert']);
    $gefiltert = $gefiltert || $bid;
    render('journal', compact('von', 'bis', 'konto', 'q', 'start', 'zeilen', 'ende', 'summen', 'gefiltert', 'letzte', 'markiert', 'bid', 'bidInfo')
        + ['aktuell' => saldo_aktuell(), 'kontakte' => kontakte(), 'titel' => 'Journal']);

/* ---------- Buchung anlegen / bearbeiten ---------- */
case 'buchung':
    brauche('buchen');
    $id = (int)($_GET['id'] ?? 0);   // 0 = neue Buchung, sonst Nummer der zu bearbeitenden Buchung
    $alt = null;                     // bisherige Werte beim Bearbeiten
    if ($id) {
        $st = db()->prepare('SELECT * FROM buchungen WHERE id = ?');
        $st->execute([$id]);
        $alt = $st->fetch();
        if (!$alt) { flash('fehler', 'Buchung nicht gefunden.'); umleiten('journal'); }
        // Die automatisch erzeugte Zeile einer Kassaprüfung kann nicht bearbeitet werden
        if (!empty($alt['pruefung_id'])) { flash('fehler', 'Die Zeile einer Kassaprüfung wird automatisch erzeugt und kann nicht bearbeitet werden.'); umleiten('journal'); }
        // Zeilen einer Umbuchung werden immer gemeinsam im Umbuchungs-Formular bearbeitet
        if ($alt['gruppe']) umleiten('umbuchung', ['gruppe' => $alt['gruppe']]);
    }
    // Formularwerte vorbelegen: beim Bearbeiten aus der Buchung, sonst Standardwerte
    $f = $alt ? [
        'datum' => $alt['datum'], 'beleg' => $alt['beleg'], 'text' => $alt['text'], 'konto' => $alt['konto'],
        'art' => $alt['ein'] > 0 ? 'ein' : 'aus', 'betrag' => geld((int)max($alt['ein'], $alt['aus'])),
    ] : ['datum' => date('Y-m-d'), 'beleg' => naechste_belegnr(), 'text' => '', 'konto' => $_GET['konto'] ?? 'kassa', 'art' => 'aus', 'betrag' => ''];
    $fehler = [];

    if ($post) {
        // Nur die bekannten Formularfelder übernehmen (array_intersect_key filtert alles andere aus)
        $f = array_merge($f, array_intersect_key($_POST, $f));
        $f['text'] = trim((string)$f['text']);
        $f['beleg'] = trim((string)$f['beleg']);
        $betrag = parse_betrag($f['betrag']);
        // Prüfungen: gültiges Datum, nicht vor Beginn, nicht im gesperrten (geprüften) Zeitraum
        if (!iso_ok($f['datum'])) $fehler[] = 'Bitte ein gültiges Datum angeben.';
        elseif ($f['datum'] < start_datum()) $fehler[] = 'Das Datum liegt vor dem Beginn des Kassabuchs (' . d_lang(start_datum()) . ').';
        elseif (ist_gesperrt($f['datum'])) $fehler[] = 'Bis ' . d_lang(gesperrt_bis()) . ' ist das Kassabuch geprüft und gesperrt.';
        // Auch eine Buchung, die schon im gesperrten Zeitraum LIEGT, darf nicht verschoben werden
        if ($alt && ist_gesperrt($alt['datum'])) $fehler[] = 'Diese Buchung liegt im geprüften Zeitraum und kann nicht mehr geändert werden.';
        if ($f['text'] === '') $fehler[] = 'Bitte eine Beschreibung angeben.';
        if (!isset(KONTEN[$f['konto']])) $fehler[] = 'Bitte ein Konto wählen.';
        if (!in_array($f['art'], ['ein', 'aus'], true)) $fehler[] = 'Bitte Eingang oder Ausgang wählen.';
        if ($betrag === null) $fehler[] = 'Bitte einen gültigen Betrag angeben (z. B. 35,57).';
        if (mb_strlen($f['beleg']) > 20) $fehler[] = 'Die Belegnummer ist zu lang.';
        // Handkassa darf nicht ins Minus gehen – vor dem Speichern durchrechnen
        if (!$fehler && ($f['konto'] === 'kassa' || ($alt && $alt['konto'] === 'kassa'))) {
            $m = kassa_pruefen($alt ? [$id] : [], [array_filter(['id' => $alt ? $id : null]) + ['datum' => $f['datum'], 'konto' => $f['konto'],
                'ein' => $f['art'] === 'ein' ? $betrag : 0, 'aus' => $f['art'] === 'aus' ? $betrag : 0, 'text' => $f['text']]]);
            if ($m) $fehler[] = $m;
        }

        if (!$fehler) {
            try {
                $datei = beleg_upload();   // null, wenn kein Beleg gewählt wurde; wirft Fehler bei ungültiger Datei
                $jetzt = date('Y-m-d H:i:s');
                // Spaltenwerte: Betrag landet je nach „Art“ in ein oder aus, die andere Spalte ist 0
                $werte = [$f['datum'], $f['beleg'], mb_substr($f['text'], 0, 250), $f['konto'],
                    $f['art'] === 'ein' ? $betrag : 0, $f['art'] === 'aus' ? $betrag : 0];
                if ($alt) {
                    // Bearbeiten: alten Beleg löschen, wenn ein neuer kommt oder „entfernen“ angehakt ist
                    $dateiAlt = $alt['datei'];
                    if ($datei || !empty($_POST['datei_weg'])) beleg_loeschen($dateiAlt);
                    $d = $datei ?: (!empty($_POST['datei_weg']) ? ['datei' => null, 'datei_name' => null]
                        : ['datei' => $alt['datei'], 'datei_name' => $alt['datei_name']]);
                    db()->prepare('UPDATE buchungen SET datum=?, beleg=?, text=?, konto=?, ein=?, aus=?, datei=?, datei_name=?, geaendert_am=? WHERE id=?')
                        ->execute([...$werte, $d['datei'], $d['datei_name'], $jetzt, $id]);
                    // Im Protokoll die Werte vorher und nachher festhalten
                    protokoll('Buchung geändert', ['id' => $id, 'vorher' => array_intersect_key($alt, array_flip(['datum', 'beleg', 'text', 'konto', 'ein', 'aus'])),
                        'nachher' => array_combine(['datum', 'beleg', 'text', 'konto', 'ein', 'aus'], $werte)]);
                    flash('ok', 'Buchung gespeichert.');
                } else {
                    // Neue Buchung speichern
                    db()->prepare('INSERT INTO buchungen (datum, beleg, text, konto, ein, aus, datei, datei_name, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([...$werte, $datei['datei'] ?? null, $datei['datei_name'] ?? null, user()['id'], $jetzt]);
                    $id = (int)db()->lastInsertId();
                    protokoll('Buchung angelegt', ['id' => $id] + array_combine(['datum', 'beleg', 'text', 'konto', 'ein', 'aus'], $werte));
                    flash('ok', 'Buchung ' . ($f['beleg'] !== '' ? 'Nr. ' . $f['beleg'] . ' ' : '') . 'gespeichert.');
                    // „Speichern & nächste“: gleich wieder ein leeres Formular (gleiches Konto) öffnen
                    if (!empty($_POST['weiter'])) umleiten('buchung', ['konto' => $f['konto']]);
                }
                // Zurück ins Journal mit den letzten 7 Buchungen; die gespeicherte Zeile wird hervorgehoben
                nach_buchung([$id], $f['datum']);
            } catch (Fehler $ex) {
                $fehler[] = $ex->getMessage();   // z. B. „Der Beleg ist zu groß“
            }
        }
    }
    render('buchung', ['f' => $f, 'alt' => $alt, 'fehler' => $fehler,
        'titel' => $alt ? 'Buchung bearbeiten' : 'Neue Buchung']);

/* ---------- Umbuchung zwischen Bank und Handkassa ----------
 * Eine Umbuchung besteht immer aus ZWEI Zeilen mit derselben „gruppe“:
 * Ausgang beim abgebenden Konto und Eingang beim empfangenden Konto.
 * Beide werden gemeinsam angelegt, geändert und gelöscht. */
case 'umbuchung':
    brauche('buchen');
    // Kennung der Umbuchung beim Bearbeiten (nur Hex-Zeichen zulassen)
    $gruppe = preg_replace('/[^a-f0-9]/', '', (string)($_GET['gruppe'] ?? ''));
    $alt = [];
    if ($gruppe) {
        $st = db()->prepare('SELECT * FROM buchungen WHERE gruppe = ? ORDER BY id');
        $st->execute([$gruppe]);
        $alt = $st->fetchAll();
        if (count($alt) !== 2) { flash('fehler', 'Umbuchung nicht gefunden.'); umleiten('journal'); }
        // Die Ausgangszeile bestimmt Richtung und Betrag
        $ab = $alt[0]['aus'] > 0 ? $alt[0] : $alt[1];
        $f = ['datum' => $ab['datum'], 'beleg' => $alt[0]['beleg'], 'richtung' => $ab['konto'] === 'bank' ? 'bank_kassa' : 'kassa_bank',
            'betrag' => geld((int)$ab['aus']), 'text' => umbuchung_zusatz($ab['text'])];
    } else {
        $f = ['datum' => date('Y-m-d'), 'beleg' => naechste_belegnr(), 'richtung' => 'bank_kassa', 'betrag' => '', 'text' => ''];
    }
    $fehler = [];
    if ($post) {
        $f = array_merge($f, array_intersect_key($_POST, $f));
        $f['text'] = trim((string)$f['text']);
        $betrag = parse_betrag($f['betrag']);
        if (!iso_ok($f['datum'])) $fehler[] = 'Bitte ein gültiges Datum angeben.';
        elseif ($f['datum'] < start_datum()) $fehler[] = 'Das Datum liegt vor dem Beginn des Kassabuchs.';
        elseif (ist_gesperrt($f['datum'])) $fehler[] = 'Bis ' . d_lang(gesperrt_bis()) . ' ist das Kassabuch geprüft und gesperrt.';
        if ($alt && ist_gesperrt($alt[0]['datum'])) $fehler[] = 'Diese Umbuchung liegt im geprüften Zeitraum.';
        if (!in_array($f['richtung'], ['bank_kassa', 'kassa_bank'], true)) $fehler[] = 'Bitte die Richtung wählen.';
        if (!$betrag) $fehler[] = 'Bitte einen gültigen Betrag angeben.';   // auch 0 ist hier nicht sinnvoll
        if (!$fehler) {
            // Abgebendes und empfangendes Konto sowie die beiden Zeilentexte bestimmen
            [$vonK, $nachK] = $f['richtung'] === 'bank_kassa' ? ['bank', 'kassa'] : ['kassa', 'bank'];
            // Handkassa darf nicht ins Minus gehen (z. B. mehr einzahlen als in der Kassa liegt)
            $plan = [['datum' => $f['datum'], 'konto' => $vonK, 'ein' => 0, 'aus' => $betrag, 'text' => KONTEN[$vonK] . ' Ausgang'],
                     ['datum' => $f['datum'], 'konto' => $nachK, 'ein' => $betrag, 'aus' => 0, 'text' => KONTEN[$nachK] . ' Eingang']];
            if ($alt) { $plan[0]['id'] = (int)$alt[0]['id']; $plan[1]['id'] = (int)$alt[1]['id']; }
            $m = kassa_pruefen(array_column($alt, 'id'), $plan);
            if ($m) $fehler[] = $m;
        }
        if (!$fehler) {
            $zus = $f['text'] !== '' ? ' ' . mb_substr($f['text'], 0, 200) : '';
            $t1 = KONTEN[$vonK] . ' Ausgang' . $zus;    // z. B. „Bank Ausgang Wechselgeld“
            $t2 = KONTEN[$nachK] . ' Eingang' . $zus;   // z. B. „Handkassa Eingang Wechselgeld“
            $jetzt = date('Y-m-d H:i:s');
            // Transaktion: beide Zeilen werden gemeinsam gespeichert – oder keine
            db()->beginTransaction();
            if ($alt) {
                db()->prepare('UPDATE buchungen SET datum=?, beleg=?, text=?, konto=?, ein=0, aus=?, geaendert_am=? WHERE id=?')
                    ->execute([$f['datum'], trim($f['beleg']), $t1, $vonK, $betrag, $jetzt, $alt[0]['id']]);
                db()->prepare("UPDATE buchungen SET datum=?, beleg='', text=?, konto=?, ein=?, aus=0, geaendert_am=? WHERE id=?")
                    ->execute([$f['datum'], $t2, $nachK, $betrag, $jetzt, $alt[1]['id']]);
                protokoll('Umbuchung geändert', ['gruppe' => $gruppe, 'datum' => $f['datum'], 'von' => $vonK, 'nach' => $nachK, 'betrag' => $betrag]);
            } else {
                $gruppe = bin2hex(random_bytes(8));   // neue, zufällige Kennung verbindet beide Zeilen
                $ins = db()->prepare('INSERT INTO buchungen (datum, beleg, text, konto, ein, aus, gruppe, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->execute([$f['datum'], trim($f['beleg']), $t1, $vonK, 0, $betrag, $gruppe, user()['id'], $jetzt]);
                $ins->execute([$f['datum'], '', $t2, $nachK, $betrag, 0, $gruppe, user()['id'], $jetzt]);   // Belegnr. nur in der 1. Zeile
                protokoll('Umbuchung angelegt', ['gruppe' => $gruppe, 'datum' => $f['datum'], 'von' => $vonK, 'nach' => $nachK, 'betrag' => $betrag]);
            }
            db()->commit();
            flash('ok', 'Umbuchung ' . geld($betrag) . ' € von ' . KONTEN[$vonK] . ' nach ' . KONTEN[$nachK] . ' gespeichert.');
            $st = db()->prepare('SELECT id FROM buchungen WHERE gruppe = ?');
            $st->execute([$gruppe]);
            nach_buchung(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $f['datum']);
        }
    }
    render('umbuchung', ['f' => $f, 'alt' => $alt, 'gruppe' => $gruppe, 'fehler' => $fehler,
        'titel' => $alt ? 'Umbuchung bearbeiten' : 'Umbuchung']);

/* ---------- Bankspesen: bis zu drei Bank-Zeilen in einem Schritt ----------
 * + Habenzinsen, – Kapitalertragssteuer, – Umsatzprovision (siehe BANKSPESEN in inc/lib.php).
 * Die Zeilen sind normale Einzelbuchungen (keine „gruppe“) und lassen sich danach
 * im Journal einzeln bearbeiten oder löschen. */
case 'bankspesen':
    brauche('buchen');
    $f = ['datum' => date('Y-m-d'), 'beleg' => naechste_belegnr()] + array_fill_keys(array_keys(BANKSPESEN), '');
    $fehler = [];
    if ($post) {
        $f = array_merge($f, array_map(fn($x) => trim((string)$x), array_intersect_key($_POST, $f)));
        if (!iso_ok($f['datum'])) $fehler[] = 'Bitte ein gültiges Datum angeben.';
        elseif ($f['datum'] < start_datum()) $fehler[] = 'Das Datum liegt vor dem Beginn des Kassabuchs (' . d_lang(start_datum()) . ').';
        elseif (ist_gesperrt($f['datum'])) $fehler[] = 'Bis ' . d_lang(gesperrt_bis()) . ' ist das Kassabuch geprüft und gesperrt.';
        if (mb_strlen($f['beleg']) > 20) $fehler[] = 'Die Belegnummer ist zu lang.';
        // Beträge prüfen: leer = Posten weglassen, sonst gültiger Betrag (auch 0,00)
        $zeilen = [];
        foreach (BANKSPESEN as $k => [$text, $richtung]) {
            if ($f[$k] === '') continue;
            $b = parse_betrag($f[$k]);
            if ($b === null) { $fehler[] = $text . ': bitte einen gültigen Betrag angeben (z. B. 0,18).'; continue; }
            $zeilen[] = [$text, $richtung === 'ein' ? $b : 0, $richtung === 'aus' ? $b : 0];
        }
        if (!$zeilen && !$fehler) $fehler[] = 'Bitte mindestens einen Betrag eintragen.';
        if (!$fehler) {
            try {
                $datei = beleg_upload();
                $jetzt = date('Y-m-d H:i:s');
                $ins = db()->prepare('INSERT INTO buchungen (datum, beleg, text, konto, ein, aus, datei, datei_name, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?,?,?,?,?)');
                // Alle Zeilen gemeinsam speichern – oder keine
                db()->beginTransaction();
                $ids = [];
                foreach ($zeilen as $i => [$text, $ein, $aus]) {
                    // Beleg-Nr. und Beleg-Datei nur bei der ersten Zeile (wie im bisherigen Kassabuch)
                    $ins->execute([$f['datum'], $i === 0 ? $f['beleg'] : '', $text, 'bank', $ein, $aus,
                        $i === 0 ? ($datei['datei'] ?? null) : null, $i === 0 ? ($datei['datei_name'] ?? null) : null, user()['id'], $jetzt]);
                    $ids[] = (int)db()->lastInsertId();
                }
                db()->commit();
                $saldo = array_sum(array_column($zeilen, 1)) - array_sum(array_column($zeilen, 2));
                protokoll('Bankspesen gebucht', ['ids' => $ids, 'datum' => $f['datum'], 'beleg' => $f['beleg'],
                    'zeilen' => array_map(fn($z) => $z[0] . ' ' . ($z[1] ? '+' . geld($z[1]) : '-' . geld($z[2])), $zeilen)]);
                flash('ok', count($zeilen) . ' Bankspesen-Zeile' . (count($zeilen) > 1 ? 'n' : '') . ' gebucht (Bank ' . ($saldo >= 0 ? '+' : '−') . geld(abs($saldo)) . ' €).');
                nach_buchung($ids, $f['datum']);
            } catch (Fehler $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $fehler[] = $ex->getMessage();
            }
        }
    }
    render('bankspesen', ['f' => $f, 'posten' => BANKSPESEN, 'fehler' => $fehler, 'titel' => 'Bankspesen']);

/* ---------- Buchung löschen (nur per POST mit Sicherheitsabfrage im Browser) ---------- */
case 'loeschen':
    brauche('buchen');
    if (!$post) umleiten('journal');
    $st = db()->prepare('SELECT * FROM buchungen WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    $b = $st->fetch();
    if (!$b) { flash('fehler', 'Buchung nicht gefunden.'); umleiten('journal'); }
    if (ist_gesperrt($b['datum'])) { flash('fehler', 'Buchungen im geprüften Zeitraum können nicht gelöscht werden.'); umleiten('journal'); }
    if (!empty($b['pruefung_id'])) { flash('fehler', 'Die Zeile einer Kassaprüfung kann nicht gelöscht werden.'); umleiten('journal'); }
    // Auch Löschen kann die Handkassa ins Minus bringen (z. B. eine Kassa-Einnahme entfernen)
    $weg = [(int)$b['id']];
    if ($b['gruppe']) {
        $st = db()->prepare('SELECT id FROM buchungen WHERE gruppe = ?');
        $st->execute([$b['gruppe']]);
        $weg = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($m = kassa_pruefen($weg, [])) {
        flash('fehler', 'Nicht gelöscht. ' . $m);
        umleiten('journal', ['von' => min($b['datum'], offener_zeitraum_von())]);
    }
    if ($b['gruppe']) {
        // Umbuchung: immer beide zusammengehörenden Zeilen löschen
        db()->prepare('DELETE FROM buchungen WHERE gruppe = ?')->execute([$b['gruppe']]);
        protokoll('Umbuchung gelöscht', $b);
    } else {
        db()->prepare('DELETE FROM buchungen WHERE id = ?')->execute([$b['id']]);
        beleg_loeschen($b['datei']);   // angehängten Beleg ebenfalls entfernen
        protokoll('Buchung gelöscht', $b);   // komplette Buchung im Protokoll aufbewahren
    }
    flash('ok', 'Buchung gelöscht.');
    umleiten('journal');

/* ---------- Beleg-Datei anzeigen ----------
 * Belege liegen im geschützten Datenordner und sind NICHT direkt über eine
 * Adresse erreichbar. Sie werden nur hier – nach der Anmeldeprüfung – ausgeliefert. */
case 'datei':
    $st = db()->prepare('SELECT datei, datei_name FROM buchungen WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $b = $st->fetch();
    // Dateiname muss unserem Zufallsformat entsprechen (Schutz vor Pfad-Tricks wie ../)
    $pfad = $b && $b['datei'] && preg_match('/^[a-f0-9]{32}\.([a-z]+)$/', $b['datei'], $m) ? beleg_ordner() . '/' . $b['datei'] : null;
    if (!$pfad || !is_file($pfad)) { http_response_code(404); exit('Beleg nicht gefunden.'); }
    $typen = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];
    header('Content-Type: ' . ($typen[$m[1]] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($pfad));
    // „inline“ = im Browser anzeigen; Originalname für „Speichern unter“
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($b['datei_name'] ?: $b['datei']));
    header('Cache-Control: private, max-age=3600');
    readfile($pfad);
    exit;

/* ---------- Bericht / Druckansicht (eigenes Layout ohne Kopf-/Fußzeile) ---------- */
case 'bericht':
    // Aufruf über den QR-Code eines Ausdrucks: ?id=<Bericht-ID> → derselbe Zeitraum wie damals.
    // Sind die Buchungen seither unverändert, bekommt die Ansicht dieselbe Bericht-ID wie der Ausdruck.
    $quelle = null;
    if (isset($_GET['id'])) {
        $st = db()->prepare('SELECT * FROM berichte WHERE uuid = ?');
        $st->execute([strtolower(trim((string)$_GET['id']))]);
        $quelle = $st->fetch() ?: null;
        if (!$quelle) {
            flash('fehler', 'Zu dieser Bericht-ID gibt es keinen Bericht. Bitte die ID aus der Fußzeile des Ausdrucks prüfen.');
            umleiten('pruefung', ['bericht' => (string)$_GET['id']]);
        }
        $quelle['unveraendert'] = hash_equals($quelle['pruef_id'], pruef_id($quelle['von'], $quelle['bis']));
        $_GET['von'] = $quelle['von'];
        $_GET['bis'] = $quelle['bis'];
        unset($_GET['zeitraum']);
        protokoll('Bericht über QR-Code aufgerufen', ['bericht_id' => $quelle['uuid'], 'von' => $quelle['von'], 'bis' => $quelle['bis'],
            'unverändert' => $quelle['unveraendert']]);
    }
    // Zeitraum: entweder eine Schnellauswahl (laufendes Jahr, letztes Quartal, ab letzter Prüfung) …
    $zeitraeume = berichts_zeitraeume();
    $auswahl = (string)($_GET['zeitraum'] ?? '');
    if (isset($zeitraeume[$auswahl])) {
        [, $von, $bis] = $zeitraeume[$auswahl];
    } else {
        // … oder frei gewählt über „von“/„bis“ (Standard: offener Zeitraum bis zur letzten Buchung)
        $auswahl = '';
        $von = iso_ok($_GET['von'] ?? '') ? $_GET['von'] : offener_zeitraum_von();
        $bis = iso_ok($_GET['bis'] ?? '') ? $_GET['bis'] : (letztes_buchungsdatum() ?: date('Y-m-d'));
    }
    if ($bis < $von) $bis = $von;
    $start = saldo_vor($von);
    [$zeilen, $ende, $summen] = mit_staenden(buchungen($von, $bis), $start);
    // Gibt es für genau diesen Zeitraum schon eine Kassaprüfung? Dann deren Datum/Namen drucken
    $st = db()->prepare('SELECT * FROM pruefungen WHERE von = ? AND bis = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$von, $bis]);
    $pruefung = $st->fetch() ?: null;
    $pid = pruef_id($von, $bis);
    // Jeder erzeugte Bericht bekommt eine eigene UUID und wird registriert (für spätere Nachprüfung) –
    // außer er wurde über den QR-Code aufgerufen und die Daten sind unverändert: dann bleibt die ID gleich
    if ($quelle && $quelle['unveraendert']) {
        $berichtId = $quelle['uuid'];
    } else {
        $berichtId = uuid4();
        db()->prepare('INSERT INTO berichte (uuid, erstellt_am, benutzer, von, bis, pruef_id, bank, kassa) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$berichtId, date('Y-m-d H:i:s'), user()['name'] ?? '-', $von, $bis, $pid, $ende['bank'], $ende['kassa']]);
    }
    // Adresse für den QR-Code „diesen Bericht aufrufen“ auf der letzten Seite
    $berichtLink = basis_url() . '?' . http_build_query(['a' => 'bericht', 'id' => $berichtId]);
    // „Nur letzte Seite“: nur die Seite mit Gesamtsummen, Prüfvermerk und Unterschriften ausgeben
    $nurLetzte = ($_GET['seite'] ?? '') === 'letzte';
    render('bericht', compact('von', 'bis', 'start', 'zeilen', 'ende', 'summen', 'pruefung', 'pid', 'berichtId', 'zeitraeume', 'auswahl', 'nurLetzte', 'quelle', 'berichtLink')
        + ['proSeite' => max(10, (int)einst('zeilen_pro_seite', 22))], false);

/* ---------- CSV-Export (öffnet direkt in Excel) ---------- */
case 'export':
    $von = iso_ok($_GET['von'] ?? '') ? $_GET['von'] : start_datum();
    $bis = iso_ok($_GET['bis'] ?? '') ? $_GET['bis'] : null;
    $anfang = saldo_vor($von);
    [$zeilen] = mit_staenden(buchungen($von, $bis), $anfang);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kassabuch_' . $von . '_' . ($bis ?: date('Y-m-d')) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Byte-Order-Mark: damit Excel die Umlaute (UTF-8) richtig erkennt
    // Kopfzeile, dann die Übertrag-Zeile (macht die Datei wieder importierbar), dann die Buchungen.
    // Trennzeichen „;“ – Standard für deutschsprachiges Excel.
    fputcsv($out, ['Datum', 'Beleg Nr.', 'Beschreibung', 'Bank Eingang', 'Bank Ausgang', 'Bank Stand',
        'Handkassa Eingang', 'Handkassa Ausgang', 'Handkassa Stand', 'Beleg-Datei'], ';', '"', '');
    fputcsv($out, [d_lang($von), '', 'Übertrag', '', '', geld($anfang['bank']), '', '', geld($anfang['kassa']), ''], ';', '"', '');
    foreach ($zeilen as $r) {
        $b = $r['konto'] === 'bank';
        fputcsv($out, [d_lang($r['datum']), $r['beleg'], $r['text'],
            $b ? geld_leer($r['ein']) : '', $b ? geld_leer($r['aus']) : '', $b ? geld($r['stand']) : '',
            !$b ? geld_leer($r['ein']) : '', !$b ? geld_leer($r['aus']) : '', !$b ? geld($r['stand']) : '',
            $r['datei_name'] ?? ''], ';', '"', '');
    }
    fclose($out);
    exit;

/* ---------- CSV-Import (zweistufig: erst prüfen, dann nach Bestätigung speichern) ----------
 * Der Dateiinhalt wird zwischen den beiden Schritten in der Sitzung aufbewahrt.
 * Beim Speichern wird er noch einmal vollständig geprüft. */
case 'import':
    brauche('admin');
    $ergebnis = null;
    $fehler = [];
    if ($post && ($_POST['tun'] ?? '') === 'abbrechen') {
        unset($_SESSION['import']);
        umleiten('import');
    }
    if ($post && ($_POST['tun'] ?? '') === 'ausfuehren' && !empty($_SESSION['import'])) {
        // Schritt 2: erneut prüfen und – nur wenn fehlerfrei – speichern
        $ergebnis = import_pruefen($_SESSION['import']['inhalt']);
        if (!$ergebnis['fehler']) {
            $n = import_ausfuehren($ergebnis, $_SESSION['import']['name']);
            unset($_SESSION['import']);
            flash('ok', "$n Buchungen importiert.");
            umleiten('journal', ['von' => $ergebnis['start']['datum'] ?? $ergebnis['buchungen'][0]['datum']]);
        }
    } elseif ($post) {
        // Schritt 1: Datei hochladen und prüfen (noch nichts speichern)
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) $fehler[] = 'Bitte eine CSV-Datei auswählen.';
        elseif ($f['size'] > 2 * 1024 * 1024) $fehler[] = 'Die Datei ist zu groß (max. 2 MB).';
        else {
            $inhalt = (string)file_get_contents($f['tmp_name']);
            $_SESSION['import'] = ['inhalt' => $inhalt, 'name' => basename((string)$f['name'])];
            $ergebnis = import_pruefen($inhalt);
        }
    } elseif (!empty($_SESSION['import'])) {
        // Seite neu geladen: Prüfergebnis der gemerkten Datei wieder anzeigen
        $ergebnis = import_pruefen($_SESSION['import']['inhalt']);
    }
    render('import', ['ergebnis' => $ergebnis, 'fehler' => $fehler, 'datei' => $_SESSION['import']['name'] ?? '', 'titel' => 'Import']);

/* ---------- Kassaprüfung: erfassen, Liste, Nachprüfung eines Ausdrucks ---------- */
case 'pruefung':
    $vonOffen = offener_zeitraum_von();   // geprüft wird immer ab dem Tag nach der letzten Prüfung
    // Vorbelegung: heute geprüft, bis zur letzten Buchung, Namen aus den Einstellungen, Sperre an
    $f = ['datum' => date('Y-m-d'), 'bis' => max($vonOffen, letztes_buchungsdatum() ?: $vonOffen),
        'pruefer1' => einst('pruefer1', ''), 'pruefer2' => einst('pruefer2', ''), 'kassier' => einst('kassier', ''),
        'bemerkung' => '', 'sperren' => '1'];
    $fehler = [];
    if ($post) {
        brauche('pruefen');   // Ansehen darf jeder, erfassen nur Kassaprüfer und Administrator
        // 'sperren' => '' vorab, weil ein nicht angehakter Haken gar nicht mitgeschickt wird
        $f = array_merge($f, ['sperren' => ''], array_intersect_key($_POST, $f));
        if (!iso_ok($f['datum'])) $fehler[] = 'Bitte das Prüfungsdatum angeben.';
        if (!iso_ok($f['bis']) || $f['bis'] < $vonOffen) $fehler[] = 'Das Ende des Prüfzeitraums muss am oder nach dem ' . d_lang($vonOffen) . ' liegen.';
        if (trim($f['pruefer1']) === '') $fehler[] = 'Bitte mindestens einen Kassaprüfer angeben.';
        if (!$fehler) {
            $s = saldo_vor(d_plus($f['bis'], 1));   // Stände am Ende des geprüften Tages
            $pid = pruef_id($vonOffen, $f['bis']);   // Fingerabdruck des geprüften Datenbestands
            db()->prepare('INSERT INTO pruefungen (datum, von, bis, pruefer1, pruefer2, kassier, bank, kassa, pruef_id, bemerkung, erfasst_von, erfasst_am) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$f['datum'], $vonOffen, $f['bis'], trim($f['pruefer1']), trim($f['pruefer2']), trim($f['kassier']),
                    $s['bank'], $s['kassa'], $pid, trim($f['bemerkung']), user()['name'], date('Y-m-d H:i:s')]);
            $prId = (int)db()->lastInsertId();
            if ($f['sperren']) einst_setzen('gesperrt_bis', $f['bis']);   // ab jetzt unveränderbar
            // Vermerk-Zeile „Kassaprüfung …“ (0,00 €) ins Kassabuch schreiben – wie im Papier-Kassabuch.
            // Sie steht am Prüfungstag, frühestens am Tag NACH dem geprüften Zeitraum, damit sie den
            // geprüften Datenbestand (Prüf-ID) nicht verändert.
            $prDatum = max($f['datum'], d_plus($f['bis'], 1));
            $namen = implode(', ', array_filter([trim($f['pruefer1']), trim($f['pruefer2'])]));
            // Wichtigstes zuerst – im Ausdruck wird zu langer Text am Zeilenende abgeschnitten
            $prText = 'Kassaprüfung ' . d_kurz($vonOffen) . '–' . d_kurz($f['bis']) . ' i. O.'
                . ($f['datum'] !== $prDatum ? ' (am ' . d_kurz($f['datum']) . ')' : '')
                . ' · ' . $namen
                . ' · Bank ' . geld($s['bank']) . ' / Kassa ' . geld($s['kassa'])
                . ' · Prüf-ID ' . substr($pid, 0, 8);
            db()->prepare('INSERT INTO buchungen (datum, beleg, text, konto, ein, aus, pruefung_id, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$prDatum, '', mb_substr($prText, 0, 250), 'bank', 0, 0, $prId, user()['id'], date('Y-m-d H:i:s')]);
            protokoll('Kassaprüfung erfasst', ['von' => $vonOffen, 'bis' => $f['bis'], 'pruef_id' => $pid, 'gesperrt' => (bool)$f['sperren']]);
            flash('ok', 'Kassaprüfung für ' . d_lang($vonOffen) . ' – ' . d_lang($f['bis']) . ' erfasst' . ($f['sperren'] ? ' und Zeitraum gesperrt.' : '.'));
            umleiten('pruefung');
        }
    }
    // Liste aller Prüfungen; für jede wird geprüft, ob die Daten seither unverändert sind
    $liste = db()->query('SELECT * FROM pruefungen ORDER BY bis DESC, id DESC')->fetchAll();
    foreach ($liste as &$p) $p['unveraendert'] = hash_equals($p['pruef_id'], pruef_id($p['von'], $p['bis']));
    unset($p);
    // Nachprüfung eines Ausdrucks anhand der Bericht-ID aus dessen Fußzeile
    $suche = strtolower(trim((string)($_GET['bericht'] ?? '')));
    $gefunden = null;   // null = nicht gesucht, false = unbekannt, Array = gefunden
    if ($suche !== '') {
        $st = db()->prepare('SELECT * FROM berichte WHERE uuid = ?');
        $st->execute([$suche]);
        $gefunden = $st->fetch() ?: false;
        if ($gefunden) $gefunden['unveraendert'] = hash_equals($gefunden['pruef_id'], pruef_id($gefunden['von'], $gefunden['bis']));
    }
    render('pruefung', ['f' => $f, 'fehler' => $fehler, 'liste' => $liste, 'vonOffen' => $vonOffen, 'suche' => $suche, 'gefunden' => $gefunden,
        'saldoOffen' => saldo_vor(d_plus($f['bis'], 1)), 'titel' => 'Kassaprüfung']);

/* ---------- Einstellungen (nur Administrator) ---------- */
case 'einstellungen':
    brauche('admin');
    $felder = ['vereinsname', 'kassier', 'pruefer1', 'pruefer2', 'start_datum', 'start_bank', 'start_kassa', 'zeilen_pro_seite', 'gesperrt_bis', 'qr_link'];
    $f = [];
    foreach ($felder as $k) $f[$k] = (string)einst($k, $k === 'qr_link' ? QR_LINK_STANDARD : '');
    // Anfangsstände für das Formular von Cent in „1.234,56“ umwandeln
    $f['start_bank'] = geld((int)$f['start_bank']);
    $f['start_kassa'] = geld((int)$f['start_kassa']);
    $fehler = [];
    // Eigenes Formular „Wartungsmodus“ (Feld tun=wartung): ein- bzw. ausschalten
    if ($post && ($_POST['tun'] ?? '') === 'wartung') {
        $an = ($_POST['wartung'] ?? '') === '1';
        $text = mb_substr(trim((string)($_POST['wartung_text'] ?? '')), 0, 300);
        $warSchon = wartung_aktiv();   // läuft bereits? Dann wird nur der Hinweis geändert
        einst_setzen('wartung', $an ? '1' : '0');
        einst_setzen('wartung_text', $text);
        if ($an && !$warSchon) einst_setzen('wartung_seit', date('Y-m-d H:i:s'));
        // Beim Einschalten zusätzlich die wichtigsten Systemdaten festhalten (Zustand vor der Wartung)
        protokoll($an ? ($warSchon ? 'Wartungshinweis geändert' : 'Wartung eingeschaltet') : 'Wartung ausgeschaltet',
            $an ? ['hinweis' => wartung_hinweis()] + ($warSchon ? [] : systemdaten_kurz()) : systemdaten_kurz());
        flash('ok', $an ? 'Wartungsmodus eingeschaltet – nur Administratoren können sich jetzt anmelden.' : 'Wartungsmodus ausgeschaltet – alle Benutzer können sich wieder anmelden.');
        umleiten('einstellungen');
    }
    // Eigenes Formular „Automatische Sicherung“: sofort eine Sicherung anlegen
    if ($post && ($_POST['tun'] ?? '') === 'sicherung_jetzt') {
        try {
            $s = auto_sicherung_anlegen('von Hand');
            flash('ok', 'Sicherung ' . $s['datei'] . ' angelegt (' . bytes_text($s['groesse']) . ', ' . $s['belege'] . ' Belege).'
                . ($s['geloescht'] ? ' Älteste Sicherung gelöscht: ' . implode(', ', $s['geloescht']) . '.' : ''));
        } catch (Throwable $e) {
            protokoll('Automatische Datensicherung fehlgeschlagen', ['anlass' => 'von Hand', 'fehler' => $e->getMessage()]);
            flash('fehler', 'Die Sicherung konnte nicht angelegt werden: ' . $e->getMessage());
        }
        header('Location: ' . url('einstellungen') . '#sicherung'); exit;
    }
    if ($post) {
        $f = array_merge($f, array_intersect_key(array_map(fn($x) => trim((string)$x), $_POST), $f));
        $sb = parse_betrag($f['start_bank']);
        $sk = parse_betrag($f['start_kassa']);
        if ($f['vereinsname'] === '') $fehler[] = 'Bitte den Vereinsnamen angeben.';
        if (!iso_ok($f['start_datum'])) $fehler[] = 'Bitte ein gültiges Startdatum angeben.';
        if ($sb === null || $sk === null) $fehler[] = 'Bitte gültige Anfangsstände angeben.';
        if ($f['gesperrt_bis'] !== '' && !iso_ok($f['gesperrt_bis'])) $fehler[] = 'Das Sperrdatum ist ungültig.';
        if ($f['qr_link'] !== '' && !preg_match('#^https?://\S+$#', $f['qr_link'])) $fehler[] = 'Der Link für den QR-Code muss mit http:// oder https:// beginnen.';
        $z = (int)$f['zeilen_pro_seite'];
        if ($z < 10 || $z > 40) $fehler[] = 'Zeilen pro Druckseite: bitte 10 bis 40.';
        // Das Startdatum darf nicht nach bereits vorhandenen Buchungen liegen
        $frueher = db()->prepare('SELECT COUNT(*) FROM buchungen WHERE datum < ?');
        $frueher->execute([$f['start_datum']]);
        if (iso_ok($f['start_datum']) && $frueher->fetchColumn() > 0) $fehler[] = 'Es gibt Buchungen vor diesem Startdatum.';
        if (!$fehler) {
            // Vorher-/Nachher-Werte merken, damit im Protokoll nur die Änderungen stehen
            $vorher = [];
            foreach ($felder as $k) $vorher[$k] = einst($k);
            foreach (['vereinsname', 'kassier', 'pruefer1', 'pruefer2', 'start_datum', 'gesperrt_bis', 'qr_link'] as $k) einst_setzen($k, $f[$k]);
            einst_setzen('start_bank', $sb);
            einst_setzen('start_kassa', $sk);
            einst_setzen('zeilen_pro_seite', $z);
            $nachher = [];
            foreach ($felder as $k) $nachher[$k] = einst($k);
            protokoll('Einstellungen geändert', array_filter(['vorher' => array_diff_assoc($vorher, $nachher), 'nachher' => array_diff_assoc($nachher, $vorher)]));
            flash('ok', 'Einstellungen gespeichert.');
            umleiten('einstellungen');
        }
    }
    render('einstellungen', ['f' => $f, 'fehler' => $fehler, 'titel' => 'Einstellungen',
        'sicherungen' => auto_sicherungen(), 'faellig' => auto_sicherung_faellig_ab()]);

/* ---------- Benutzerverwaltung (nur Administrator) ----------
 * Feld „tun“ im Formular bestimmt die Aktion: neu | aktiv | rolle | passwort.
 * Den eigenen Zugang kann man hier nicht sperren oder herabstufen – so bleibt
 * immer mindestens ein Administrator übrig. */
case 'benutzer':
    brauche('admin');
    $fehler = [];
    $f = ['login' => '', 'name' => '', 'rolle' => 'pruefer', 'email' => ''];
    if ($post) {
        $tun = $_POST['tun'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($tun === 'neu') {
            $f = array_merge($f, array_intersect_key(array_map(fn($x) => trim((string)$x), $_POST), $f));
            if (!preg_match('/^[\w.@-]{3,40}$/u', $f['login'])) $fehler[] = 'Benutzername: 3–40 Zeichen (Buchstaben, Ziffern, . _ - @).';
            if ($f['name'] === '') $fehler[] = 'Bitte den Namen angeben.';
            if (!isset(ROLLEN[$f['rolle']])) $fehler[] = 'Bitte eine Rolle wählen.';
            if (!email_ok($f['email'])) $fehler[] = 'Die E-Mail-Adresse ist ungültig.';
            if (mb_strlen($_POST['pass'] ?? '') < 8) $fehler[] = 'Das Passwort muss mindestens 8 Zeichen haben.';
            $st = db()->prepare('SELECT COUNT(*) FROM benutzer WHERE login = ?');
            $st->execute([$f['login']]);
            if ($st->fetchColumn()) $fehler[] = 'Diesen Benutzernamen gibt es schon.';
            if (!$fehler) {
                // pw_aendern = 1: Der neue Benutzer muss das Anfangspasswort bei der ersten Anmeldung ändern
                db()->prepare('INSERT INTO benutzer (login, name, pass, rolle, pw_aendern, email) VALUES (?,?,?,?,?,?)')
                    ->execute([$f['login'], $f['name'], password_hash($_POST['pass'], PASSWORD_DEFAULT), $f['rolle'],
                        1, $f['email']]);
                protokoll('Benutzer angelegt', ['login' => $f['login'], 'rolle' => $f['rolle']]);
                flash('ok', 'Benutzer „' . $f['login'] . '“ angelegt.');
                umleiten('benutzer');
            }
        } elseif ($id && $tun === 'email') {
            // E-Mail-Adresse ändern – auch die eigene ist erlaubt
            $m = trim((string)($_POST['email'] ?? ''));
            if (!email_ok($m)) { flash('fehler', 'Die E-Mail-Adresse „' . $m . '“ ist ungültig.'); umleiten('benutzer'); }
            db()->prepare('UPDATE benutzer SET email = ? WHERE id = ?')->execute([$m, $id]);
            protokoll('E-Mail geändert', ['id' => $id, 'email' => $m]);
            flash('ok', 'E-Mail-Adresse gespeichert.');
            umleiten('benutzer');
        } elseif ($id && $id !== (int)user()['id']) {
            if ($tun === 'aktiv') {
                // Umschalten aktiv ↔ gesperrt (1 - aktiv)
                db()->prepare('UPDATE benutzer SET aktiv = 1 - aktiv WHERE id = ?')->execute([$id]);
                protokoll('Benutzer (de)aktiviert', ['id' => $id]);
            } elseif ($tun === 'rolle' && isset(ROLLEN[$_POST['rolle'] ?? ''])) {
                db()->prepare('UPDATE benutzer SET rolle = ? WHERE id = ?')->execute([$_POST['rolle'], $id]);
                protokoll('Rolle geändert', ['id' => $id, 'rolle' => $_POST['rolle']]);
            } elseif ($tun === 'passwort') {
                if (mb_strlen($_POST['pass'] ?? '') < 8) { flash('fehler', 'Das neue Passwort muss mindestens 8 Zeichen haben.'); umleiten('benutzer'); }
                // Zurückgesetztes Passwort muss bei der nächsten Anmeldung geändert werden
                db()->prepare('UPDATE benutzer SET pass = ?, pw_aendern = 1 WHERE id = ?')
                    ->execute([password_hash($_POST['pass'], PASSWORD_DEFAULT), $id]);
                protokoll('Passwort zurückgesetzt', ['id' => $id]);
            }
            flash('ok', 'Gespeichert.');
            umleiten('benutzer');
        }
    }
    render('benutzer', ['liste' => db()->query('SELECT * FROM benutzer ORDER BY aktiv DESC, name')->fetchAll(),
        'f' => $f, 'fehler' => $fehler, 'titel' => 'Benutzer']);

/* ---------- Eigenes Passwort ändern (jeder angemeldete Benutzer) ---------- */
case 'passwort':
    brauche('konto');   // alle Rollen, auch der Gast
    $fehler = [];
    if ($post) {
        if (!password_verify((string)($_POST['alt'] ?? ''), user()['pass'])) $fehler[] = 'Das bisherige Passwort stimmt nicht.';
        if (mb_strlen($_POST['neu'] ?? '') < 8) $fehler[] = 'Das neue Passwort muss mindestens 8 Zeichen haben.';
        if (($_POST['neu'] ?? '') !== ($_POST['neu2'] ?? '')) $fehler[] = 'Die neuen Passwörter stimmen nicht überein.';
        if (!$fehler && password_verify((string)$_POST['neu'], user()['pass'])) $fehler[] = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
        if (!$fehler) {
            // Neues Passwort speichern und die Pflicht zur Änderung aufheben
            db()->prepare('UPDATE benutzer SET pass = ?, pw_aendern = 0 WHERE id = ?')->execute([password_hash($_POST['neu'], PASSWORD_DEFAULT), user()['id']]);
            session_regenerate_id(true);
            protokoll('Eigenes Passwort geändert');
            flash('ok', 'Passwort geändert.');
            umleiten('journal');
        }
    }
    render('passwort', ['fehler' => $fehler, 'titel' => 'Passwort ändern', 'muss' => !empty(user()['pw_aendern'])]);

/* ---------- Hilfe: Benutzerhandbuch als HTML-Seite (views/hilfe.php) ---------- */
case 'hilfe':
    render('hilfe', ['titel' => 'Hilfe']);

/* ---------- Protokoll (nur Administrator): die letzten 500 Einträge ---------- */
/* ---------- Excel-Rundlauf (nur Administrator) ----------
 * tun=export (GET): alle Buchungen als .xlsx herunterladen
 * tun=pruefen (POST): .xlsx hochladen ODER aus Excel kopierten Text einfügen → nur prüfen, Übersicht zeigen
 * tun=speichern (POST): geprüfte Änderungen übernehmen (vorher automatische Sicherung)
 * tun=abbrechen (POST): gemerkte Datei verwerfen */
case 'excel':
    brauche('admin');
    $tun = $post ? (string)($_POST['tun'] ?? '') : (string)($_GET['tun'] ?? '');
    if ($tun === 'export') {
        if (!class_exists('ZipArchive')) { flash('fehler', 'Für Excel-Dateien fehlt am Server die PHP-Erweiterung „zip“ – bitte CSV-Export im Journal verwenden.'); umleiten('excel'); }
        $datei = excel_export();
        protokoll('Excel-Export', ['buchungen' => (int)db()->query('SELECT COUNT(*) FROM buchungen')->fetchColumn()]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="kassabuch_' . date('Y-m-d_His') . '.xlsx"');
        header('Content-Length: ' . filesize($datei));
        readfile($datei);
        @unlink($datei);
        exit;
    }
    if ($tun === 'abbrechen') { unset($_SESSION['excel']); umleiten('excel'); }
    $ergebnis = null;
    $fehler = [];
    if ($tun === 'pruefen') {
        try {
            $f = $_FILES['xlsx'] ?? null;
            if ($f && $f['error'] === UPLOAD_ERR_OK) {
                if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) throw new Fehler('Zum Einlesen von .xlsx fehlen am Server die PHP-Erweiterungen „zip“/„simplexml“ – bitte „Aus Excel einfügen“ verwenden.');
                $tab = tabelle_aus_xlsx($f['tmp_name']);
                $quelle = basename((string)$f['name']);
            } elseif (trim((string)($_POST['text'] ?? '')) !== '') {
                $tab = tabelle_aus_text((string)$_POST['text']);
                $quelle = 'aus Excel eingefügt';
            } else {
                throw new Fehler('Bitte eine Excel-Datei auswählen oder die kopierten Zellen in das Textfeld einfügen.');
            }
            $_SESSION['excel'] = ['tab' => $tab, 'quelle' => $quelle];
        } catch (Fehler $x) { $fehler[] = $x->getMessage(); }
    }
    if ($tun === 'speichern' && !empty($_SESSION['excel'])) {
        $ergebnis = excel_pruefen($_SESSION['excel']['tab']);
        if (!$ergebnis['fehler']) {
            $sich = sicherung_vor_aenderung('vor_excel_import');
            $f2 = excel_anwenden($ergebnis, false);
            if (!$f2) {
                $kurz = fn($x) => d_kurz($x['datum']) . ' ' . mb_strimwidth($x['text'], 0, 40, '…') . ' ' . ($x['ein'] ? '+' . geld((int)$x['ein']) : '-' . geld((int)$x['aus']));
                protokoll('Excel-Import', array_filter([
                    'quelle' => $_SESSION['excel']['quelle'], 'sicherung' => $sich,
                    'neu' => count($ergebnis['neu']), 'geaendert' => count($ergebnis['geaendert']), 'geloescht' => count($ergebnis['geloescht']),
                    'unveraendert' => $ergebnis['unveraendert'],
                    'beginn' => $ergebnis['start']['datum'] !== $ergebnis['start_alt']['datum'] || $ergebnis['start']['bank'] !== $ergebnis['start_alt']['bank'] || $ergebnis['start']['kassa'] !== $ergebnis['start_alt']['kassa']
                        ? d_lang($ergebnis['start_alt']['datum']) . ' ' . geld($ergebnis['start_alt']['bank']) . '/' . geld($ergebnis['start_alt']['kassa']) . ' → ' . d_lang($ergebnis['start']['datum']) . ' ' . geld($ergebnis['start']['bank']) . '/' . geld($ergebnis['start']['kassa']) : null,
                    'aenderungen' => array_slice(array_map(fn($ap) => 'ID ' . $ap[0]['id'] . ': ' . $kurz($ap[0]) . ' → ' . $kurz($ap[1]), $ergebnis['geaendert']), 0, 30),
                    'geloeschte' => array_slice(array_map(fn($a) => 'ID ' . $a['id'] . ': ' . $kurz($a), $ergebnis['geloescht']), 0, 30),
                    'neue' => array_slice(array_map($kurz, $ergebnis['neu']), 0, 30),
                ]));
                unset($_SESSION['excel']);
                flash('ok', 'Excel-Datei übernommen: ' . count($ergebnis['neu']) . ' neu, ' . count($ergebnis['geaendert']) . ' geändert, ' . count($ergebnis['geloescht'])
                    . ' gelöscht. Sicherung vorher: ' . $sich . '.');
                umleiten('journal', ['von' => $ergebnis['start']['datum']]);
            }
            $ergebnis['fehler'] = $f2;
        }
    } elseif (!empty($_SESSION['excel'])) {
        $ergebnis = excel_pruefen($_SESSION['excel']['tab']);
    }
    render('excel', ['ergebnis' => $ergebnis, 'fehler' => $fehler, 'quelle' => $_SESSION['excel']['quelle'] ?? '', 'titel' => 'Excel']);

/* ---------- Systemprüfung (nur Administrator, Teil der Wartung) ----------
 * Zeigt Provider-/Serverdaten, PHP-Einstellungen, Dateirechte und PHP-Module.
 * Jeder Aufruf wird mit den wichtigsten Werten protokolliert. ?format=txt lädt
 * das Ergebnis als Textdatei herunter (z. B. für den Support des Providers). */
case 'system':
    brauche('admin');
    // Formular-Test (aus der Systemprüfung per JavaScript gesendet): Kommt die Anfrage hier an,
    // hat sie die Firewall des Providers passiert. Antwort als JSON für die Anzeige.
    if ($post && ($_POST['tun'] ?? '') === 'formtest') {
        $dateien = 0;
        foreach ($_FILES as $f) if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $dateien++;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'art' => (string)($_POST['art'] ?? ''), 'dateien' => $dateien,
            'typ' => (string)($_SERVER['CONTENT_TYPE'] ?? '')]);
        exit;
    }
    // Ergebnis des Formular-Tests ins Protokoll schreiben (normales Formular – kommt immer durch)
    if ($post && ($_POST['tun'] ?? '') === 'formtest_ergebnis') {
        $erg = json_decode((string)($_POST['ergebnis'] ?? ''), true);
        if (is_array($erg)) protokoll('Systemprüfung: Formular-Test', array_map(fn($x) => mb_substr((string)$x, 0, 120), array_slice($erg, 0, 10, true)));
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true}';
        exit;
    }
    $gruendlich = !empty($_GET['gruendlich']);   // vollständige Integritätsprüfung (dauert länger)
    $daten = systemdaten($gruendlich);
    if (($_GET['format'] ?? '') === 'txt') {
        protokoll('Systemprüfung heruntergeladen', systemdaten_kurz($daten));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="kassabuch_systempruefung_' . date('Y-m-d_His') . '.txt"');
        echo systemdaten_text($daten);
        exit;
    }
    protokoll($gruendlich ? 'Systemprüfung (gründlich) durchgeführt' : 'Systemprüfung durchgeführt', systemdaten_kurz($daten));
    render('system', ['daten' => $daten, 'bilanz' => system_bilanz($daten), 'gruendlich' => $gruendlich, 'titel' => 'Systemprüfung']);

case 'protokoll':
    brauche('admin');
    // Optionaler Filter nach Farbgruppe (Klick auf die Legende), z. B. ?art=anmeldung
    $art = isset(PROTOKOLL_ARTEN[$_GET['art'] ?? '']) ? $_GET['art'] : '';
    $liste = db()->query('SELECT * FROM protokoll ORDER BY id DESC LIMIT ' . ($art ? 5000 : 500))->fetchAll();
    if ($art) $liste = array_slice(array_values(array_filter($liste, fn($p) => protokoll_art($p['aktion']) === $art)), 0, 500);
    // Anzahl der Einträge je Farbgruppe (über das GESAMTE Protokoll) für die Legende
    $anzahl = array_fill_keys(array_keys(PROTOKOLL_ARTEN), 0);
    foreach (db()->query('SELECT aktion, COUNT(*) AS n FROM protokoll GROUP BY aktion') as $z) $anzahl[protokoll_art($z['aktion'])] += (int)$z['n'];
    render('protokoll', ['liste' => $liste, 'art' => $art, 'anzahl' => $anzahl, 'titel' => 'Protokoll']);

/* ---------- Datensicherung (nur Administrator) ----------
 * Ohne Parameter: neue Sicherung (Datenbank + alle Belege als ZIP) erstellen
 * und herunterladen. Mit ?datei=…: eine der automatischen Sicherungen aus
 * data/sicherungen/auto/ herunterladen (nur Dateinamen nach festem Muster). */
case 'sicherung':
    brauche('admin');
    $datei = (string)($_GET['datei'] ?? '');
    if ($datei !== '') {
        $pfad = auto_sicherung_ordner() . '/' . basename($datei);
        if (!preg_match(AUTO_SICHERUNG_MUSTER, basename($datei)) || !is_file($pfad)) {
            flash('fehler', 'Diese Sicherung gibt es nicht (mehr).');
            header('Location: ' . url('einstellungen') . '#sicherung'); exit;
        }
        protokoll('Automatische Datensicherung heruntergeladen', ['datei' => basename($pfad)]);
        header('Content-Type: ' . (str_ends_with($pfad, '.zip') ? 'application/zip' : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($pfad) . '"');
        header('Content-Length: ' . filesize($pfad));
        readfile($pfad);
        exit;
    }
    $s = sicherung_erstellen(DATA_DIR . '/kassabuch_sicherung_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)));
    $name = 'kassabuch_sicherung_' . date('Y-m-d_His') . (str_ends_with($s['datei'], '.zip') ? '.zip' : '.sqlite');
    protokoll('Datensicherung heruntergeladen', ['belege' => $s['belege']]);
    header('Content-Type: ' . (str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($s['datei']));
    readfile($s['datei']);
    @unlink($s['datei']);   // Zwischendatei sofort wieder löschen
    exit;

/* ---------- Unbekannte Aktion → Journal ---------- */
default:
    umleiten('journal');
}
