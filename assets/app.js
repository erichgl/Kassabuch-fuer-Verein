/*
 * =====================================================================
 *  Kassabuch – kleine Helfer im Browser (assets/app.js)
 * =====================================================================
 *
 *  Das Programm funktioniert auch ohne JavaScript; dieses Skript macht die
 *  Bedienung nur angenehmer:
 *    1. Sicherheitsabfrage vor dem Löschen bzw. Erfassen der Kassaprüfung
 *       (Formulare/Knöpfe mit dem Attribut data-frage="…").
 *    2. Warnung für Administratoren, falls die Datenbank öffentlich abrufbar ist.
 *    3. Beträge beim Verlassen des Feldes schön formatieren (1200,5 → 1.200,50).
 *    4. Enter in einem Feld mit data-enter-klick="id" klickt den Knopf mit dieser id
 *       (z. B. Anzahl bei „Letzte [n] Buchungen“ im Journal).
 *    5. Bankspesen: KESt (25 % der Habenzinsen) vorschlagen und die Veränderung des
 *       Bankstands laufend anzeigen.
 *    7. Journal: schwebende Knöpfe ▲/▼ bei langen Listen, aktueller Monat in der Sprungleiste markiert.
 *    6. Belegfeld: Vorschau, „entfernen“ und Verkleinern von Fotos vor dem Hochladen.
 *       Beim Absenden wird das Formular NUR dann als Datei-Upload (multipart) gesendet,
 *       wenn wirklich ein Beleg gewählt ist – sonst als normales Formular. Manche
 *       Provider-Firewalls blockieren Upload-Formulare mit leeren Dateifeldern (403).
 */

// Sicherheitsabfragen vor dem Absenden
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  var frage = f.getAttribute('data-frage') || (ev.submitter && ev.submitter.getAttribute('data-frage'));
  if (frage && !window.confirm(frage)) ev.preventDefault();
});

// Prüfen, ob die Datenbank öffentlich abrufbar ist (nur für Administratoren eingeblendet)
(function () {
  var el = document.getElementById('datencheck');
  if (!el || !window.fetch) return;
  fetch(el.getAttribute('data-url'), { method: 'HEAD', cache: 'no-store', credentials: 'omit' })
    .then(function (r) { if (r.ok) el.hidden = false; })
    .catch(function () {});
})();

// Beträge beim Verlassen des Feldes hübsch formatieren: 1200,5 -> 1.200,50
document.addEventListener('blur', function (ev) {
  var el = ev.target;
  if (!el.classList || !el.classList.contains('betrag')) return;
  var s = el.value.replace(/\s|€/g, '');
  if (!s) return;
  if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
  else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
  // Ab hier steht der Betrag in JavaScript-Schreibweise (Punkt als Dezimalzeichen)
  var n = Number(s);
  if (!isFinite(n) || n < 0) return;
  // Zurück in österreichische Schreibweise mit Tausenderpunkt und zwei Nachkommastellen
  el.value = n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}, true);

// Bankspesen-Formular: KESt vorschlagen und Veränderung des Bankstands anzeigen
(function () {
  var f = document.getElementById('bankspesen');
  if (!f) return;
  // Betrag aus dem Feld lesen (österreichische Schreibweise) – in Cent
  function cent(el) {
    var s = el.value.replace(/\s|€/g, '');
    if (!s) return 0;
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    var n = Number(s);
    return isFinite(n) ? Math.round(n * 100) : 0;
  }
  function zeige(c) {
    return (c < 0 ? '−' : '+') + (Math.abs(c) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  var zinsen = f.elements.zinsen, kest = f.elements.kest, kestVorschlag = true;
  function rechne() {
    var c = 0;
    f.querySelectorAll('.spesen input').forEach(function (el) { c += (el.dataset.art === 'ein' ? 1 : -1) * cent(el); });
    document.getElementById('spesen-saldo').textContent = zeige(c);
  }
  // Sobald jemand die KESt selbst eintippt, nicht mehr überschreiben
  kest.addEventListener('input', function () { kestVorschlag = kest.value === ''; });
  zinsen.addEventListener('input', function () {
    if (kestVorschlag) {
      var k = Math.round(cent(zinsen) * 0.25);   // 25 % KESt, kaufmännisch gerundet
      kest.value = k ? (k / 100).toLocaleString('de-DE', { minimumFractionDigits: 2 }) : '';
    }
    rechne();
  });
  f.addEventListener('input', rechne);
  rechne();
})();

// Enter in einem Feld mit data-enter-klick löst den zugehörigen Knopf aus statt den ersten Knopf des Formulars
document.addEventListener('keydown', function (ev) {
  var el = ev.target;
  if (ev.key !== 'Enter' || !el.getAttribute || !el.getAttribute('data-enter-klick')) return;
  var k = document.getElementById(el.getAttribute('data-enter-klick'));
  if (k) { ev.preventDefault(); k.click(); }
});

// Belegfeld (views/_beleg.php): Vorschau anzeigen und Fotos vor dem Hochladen verkleinern
(function () {
  var GROESSE = 2000;      // längste Bildseite in Pixel
  var QUALITAET = 0.82;    // JPEG-Qualität
  function mb(b) {   // Dateigröße lesbar: „850 KB“ bzw. „7,3 MB“
    return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB'
      : (b / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB';
  }

  // Verkleinert ein Foto im Browser; ruft fertig(neueDatei oder null) auf
  function verkleinern(datei, fertig) {
    if (!/^image\/(jpeg|png|webp)$/.test(datei.type) || !window.URL || !document.createElement('canvas').toBlob) return fertig(null);
    var bild = new Image(), adr = URL.createObjectURL(datei);
    bild.onload = function () {
      var f = Math.min(1, GROESSE / Math.max(bild.naturalWidth, bild.naturalHeight));
      var c = document.createElement('canvas');
      c.width = Math.round(bild.naturalWidth * f); c.height = Math.round(bild.naturalHeight * f);
      var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
      g.drawImage(bild, 0, 0, c.width, c.height);   // Browser berücksichtigen dabei die Ausrichtung (Hoch-/Querformat)
      URL.revokeObjectURL(adr);
      c.toBlob(function (blob) {
        if (!blob || blob.size >= datei.size) return fertig(null);   // nur nehmen, wenn wirklich kleiner
        var name = (datei.name || 'beleg').replace(/\.[^.]+$/, '') + '.jpg';
        try { fertig(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })); } catch (e) { fertig(null); }
      }, 'image/jpeg', QUALITAET);
    };
    bild.onerror = function () { URL.revokeObjectURL(adr); fertig(null); };   // z. B. HEIC, das der Browser nicht anzeigen kann
    bild.src = adr;
  }

  document.querySelectorAll('.beleg-feld').forEach(function (feld) {
    var eingaben = feld.querySelectorAll('.beleg-eingabe');
    var vorschau = feld.querySelector('.beleg-vorschau'), img = vorschau.querySelector('img'), info = vorschau.querySelector('.beleg-info');
    function zeige(datei, vorher) {
      vorschau.hidden = false;
      if (/^image\//.test(datei.type)) {
        img.src = URL.createObjectURL(datei); img.hidden = false;
        img.onerror = function () { img.hidden = true; };
      } else { img.hidden = true; }
      info.textContent = (datei.type === 'application/pdf' ? '📄 ' : '🖼 ') + datei.name + ' · ' + mb(datei.size)
        + (vorher ? ' (verkleinert von ' + mb(vorher) + ')' : '');
    }
    eingaben.forEach(function (ein) {
      ein.addEventListener('change', function () {
        var datei = ein.files && ein.files[0];
        if (!datei) return;
        // Nur EIN Beleg: das andere Feld leeren
        eingaben.forEach(function (x) { if (x !== ein) x.value = ''; });
        info.textContent = 'Beleg wird vorbereitet …'; vorschau.hidden = false; img.hidden = true;
        verkleinern(datei, function (klein) {
          if (klein && window.DataTransfer) {
            try { var dt = new DataTransfer(); dt.items.add(klein); ein.files = dt.files; zeige(klein, datei.size); return; } catch (e) {}
          }
          zeige(datei, 0);
        });
      });
    });
    // Beim Absenden: ohne Beleg als normales Formular, mit Beleg nur das EINE befüllte Dateifeld senden
    var form = feld.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        var gewaehlt = Array.prototype.filter.call(eingaben, function (x) { return x.files && x.files.length; });
        eingaben.forEach(function (x) { x.disabled = gewaehlt.indexOf(x) < 0; });   // leere Felder nicht mitsenden
        form.enctype = gewaehlt.length ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
      });
      // Zurück-Taste des Browsers: Felder wieder freigeben
      window.addEventListener('pageshow', function () { eingaben.forEach(function (x) { x.disabled = false; }); });
    }
    vorschau.querySelector('.beleg-weg').addEventListener('click', function () {
      eingaben.forEach(function (x) { x.value = ''; });
      vorschau.hidden = true; img.hidden = true; img.removeAttribute('src');
    });
  });
})();

// Journal: schwebende Knöpfe ▲/▼ einblenden, sobald die Liste länger als der Bildschirm ist und
// man nach unten blättert; in der Monatsleiste den Monat markieren, der gerade oben zu sehen ist
(function () {
  var navi = document.querySelector('.journal-navi, .hilfe-navi');
  var monate = document.querySelectorAll('.monate a[data-monat]');
  var zeilen = Array.prototype.map.call(monate, function (a) { return document.getElementById('m-' + a.dataset.monat); });
  if (!navi && !monate.length) return;
  var wartet = false;
  function pruefe() {
    wartet = false;
    var lang = document.documentElement.scrollHeight > window.innerHeight * 1.5;
    if (navi) navi.hidden = !(lang && window.scrollY > 300);
    var aktuell = -1;
    zeilen.forEach(function (z, i) { if (z && z.getBoundingClientRect().top < 180) aktuell = i; });
    monate.forEach(function (a, i) { a.classList.toggle('aktiv', i === aktuell); });
  }
  window.addEventListener('scroll', function () { if (!wartet) { wartet = true; requestAnimationFrame(pruefe); } }, { passive: true });
  window.addEventListener('resize', pruefe);
  pruefe();
})();

// Hilfe: Stichwortverzeichnis filtern (Suchfeld über der A–Z-Liste)
(function () {
  var feld = document.getElementById('sw-filter');
  if (!feld) return;
  var eintraege = document.querySelectorAll('.sw-gruppe li');
  var gruppen = document.querySelectorAll('.sw-gruppe');
  var leer = document.querySelector('.sw-leer');
  var norm = function (t) { return t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
  feld.addEventListener('input', function () {
    var q = norm(feld.value.trim()), n = 0;
    eintraege.forEach(function (li) { var ok = !q || norm(li.dataset.wort).indexOf(q) >= 0; li.hidden = !ok; if (ok) n++; });
    gruppen.forEach(function (g) { g.hidden = !g.querySelector('li:not([hidden])'); });
    if (leer) leer.hidden = n > 0;
  });
})();
