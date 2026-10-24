<?php
/*
 * =====================================================================
 *  Kassabuch – Konfiguration (config.php)
 * =====================================================================
 *
 * Diese Datei bei Bedarf anpassen. Alles andere (Vereinsname, Anfangsstände,
 * Kassaprüfer usw.) wird im Programm unter „Einstellungen“ gepflegt.
 */

// Ordner für Datenbank und hochgeladene Belege.
// EMPFEHLUNG: einen Ordner AUSSERHALB des öffentlichen Web-Verzeichnisses angeben,
// z. B. '/home/meinverein/kassabuch-daten'. Der Standard-Ordner "data" wird per
// .htaccess gesperrt (funktioniert nur bei Apache).
define('DATA_DIR', __DIR__ . '/data');

// Zeitzone für alle Datums- und Zeitangaben (Buchungsdatum, Protokoll, Bericht)
define('ZEITZONE', 'Europe/Vienna');

// Maximale Größe eines Beleg-Uploads in Bytes (10 MB).
// Achtung: Auch die PHP-Einstellungen upload_max_filesize/post_max_size
// (.htaccess bzw. .user.ini) müssen mindestens so groß sein.
define('MAX_UPLOAD', 10 * 1024 * 1024);

// Automatisch abmelden nach so vielen Minuten ohne Tätigkeit (Schutz bei
// vergessenem Abmelden, z. B. an einem fremden PC)
define('SITZUNG_MINUTEN', 60);

// Internet-Ordner mit den PDF-Handbüchern (Gesamthandbuch und je Rolle).
// Darauf verweisen die Links oben auf der Hilfe-Seite; der Ordner ist auch das
// Standardziel des QR-Codes auf der letzten Berichtsseite (in den Einstellungen änderbar).
define('HANDBUCH_BASIS', 'https://github.com/erichgl/kassabuch/blob/main/docs/handbuch/');

// Adresse des Quellcodes. Das Kassabuch steht unter der Lizenz GNU AGPL-3.0:
// Wer es (auch verändert) im Internet betreibt, muss den Benutzern den Quellcode
// anbieten – dafür steht dieser Link in der Fußzeile jeder Seite.
define('QUELLCODE_LINK', 'https://github.com/erichgl/kassabuch');
