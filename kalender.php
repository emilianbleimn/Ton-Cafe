<?php
/* Liefert alle Buchungen als Kalender-Abo (iCalendar/ICS).
   Diesen Link traegst du einmal in deinem Kalender ein, danach
   erscheinen neue Anfragen dort automatisch.

   Aufruf:  kalender.php?key=<KALENDER_KEY aus config.php>

   Der Schluessel ist noetig, weil der Kalender Kundennamen und
   Kontaktdaten enthaelt — ohne ihn kommt niemand an die Daten. */

require __DIR__ . '/config.php';

/* ── Zugriff pruefen ── */
$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(KALENDER_KEY, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kein Zugriff. Der Schluessel in der Adresse stimmt nicht.\n";
    exit;
}

date_default_timezone_set('Europe/Berlin');

/* ── iCalendar-Hilfsfunktionen ── */

/** Sonderzeichen in Textfeldern maskieren. */
function ics_text(string $s): string {
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(["\r\n", "\r", "\n"], '\\n', $s);
    $s = str_replace([',', ';'], ['\\,', '\\;'], $s);
    return $s;
}

/** Zeilen auf 75 Oktett falten, wie es der Standard verlangt. */
function ics_fold(string $zeile): string {
    if (strlen($zeile) <= 75) {
        return $zeile;
    }
    $out = '';
    $rest = $zeile;
    $erste = true;
    while (strlen($rest) > 0) {
        $max = $erste ? 75 : 74;
        // an Zeichengrenze schneiden, damit Umlaute heil bleiben
        $stueck = mb_strcut($rest, 0, $max, 'UTF-8');
        $out .= ($erste ? '' : "\r\n ") . $stueck;
        $rest = substr($rest, strlen($stueck));
        $erste = false;
    }
    return $out;
}

$zeilen = [];
function z(string $s): void {
    global $zeilen;
    $zeilen[] = ics_fold($s);
}

/** Lokale Zeit -> UTC-Stempel fuer die Kalenderdatei. */
function ics_zeit(string $datum, string $uhrzeit): string {
    $dt = new DateTime($datum . ' ' . $uhrzeit, new DateTimeZone('Europe/Berlin'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}

/* ── Kalender aufbauen ── */
$d     = daten_laden_sicher();
$jetzt = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

z('BEGIN:VCALENDAR');
z('VERSION:2.0');
z('PRODID:-//Tonfluestern//Buchungen//DE');
z('CALSCALE:GREGORIAN');
z('METHOD:PUBLISH');
z('X-WR-CALNAME:Tonflüstern – Buchungen');
z('NAME:Tonflüstern – Buchungen');
z('X-WR-TIMEZONE:Europe/Berlin');
z('REFRESH-INTERVAL;VALUE=DURATION:PT15M');
z('X-PUBLISHED-TTL:PT15M');

/* Anfragen */
foreach ($d['anfragen'] as $a) {
    if (($a['status'] ?? 'offen') === 'storniert') {
        continue;
    }
    $datum = (string)($a['datum'] ?? '');
    if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
        continue;
    }

    $wt       = (int)date('w', strtotime($datum));
    $personen = (int)($a['personen'] ?? 0);
    $angebot  = (string)($a['angebot'] ?? 'Anfrage');
    $name     = (string)($a['name'] ?? '');

    // Zeitfenster: an festen Tagen die Oeffnungszeit, sonst ganztaegig
    $fest = OPEN_HOURS[$wt] ?? null;
    if ($fest !== null && preg_match('/(\d{2}:\d{2}).*?(\d{2}:\d{2})/', $fest, $m)) {
        $start = 'DTSTART:' . ics_zeit($datum, $m[1]);
        $ende  = 'DTEND:'   . ics_zeit($datum, $m[2]);
    } else {
        $naechster = date('Ymd', strtotime($datum . ' +1 day'));
        $start = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($datum));
        $ende  = 'DTEND;VALUE=DATE:' . $naechster;
    }

    $titel = sprintf('%s – %d %s (%s)',
        $angebot, $personen, $personen === 1 ? 'Person' : 'Personen', $name);

    $text = "Angebot:   $angebot\n"
          . "Personen:  $personen\n"
          . "Name:      $name\n"
          . "E-Mail:    " . ($a['email'] ?? '—') . "\n"
          . "Telefon:   " . (($a['telefon'] ?? '') !== '' ? $a['telefon'] : '—') . "\n"
          . "Zeit:      " . ($a['zeit'] ?? '—') . "\n"
          . "Eingegangen am " . ($a['erstellt'] ?? '—') . "\n"
          . (($a['nachricht'] ?? '') !== '' ? "\nNachricht:\n" . $a['nachricht'] . "\n" : '')
          . "\nÜbersicht: https://tonfluestern.de/admin.php";

    z('BEGIN:VEVENT');
    z('UID:' . ($a['id'] ?? md5($datum . $name)) . '@tonfluestern.de');
    z('DTSTAMP:' . $jetzt);
    z($start);
    z($ende);
    z('SUMMARY:' . ics_text($titel));
    z('DESCRIPTION:' . ics_text($text));
    z('LOCATION:' . ics_text('Tonflüstern, Hauptstraße 43, 64711 Erbach'));
    z('STATUS:CONFIRMED');
    z('TRANSP:OPAQUE');
    z('END:VEVENT');
}

/* Von Hand blockierte Plaetze */
foreach ($d['manuell'] as $datum => $anzahl) {
    $anzahl = (int)$anzahl;
    if ($anzahl <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$datum)) {
        continue;
    }
    $wt   = (int)date('w', strtotime($datum));
    $fest = OPEN_HOURS[$wt] ?? null;

    if ($fest !== null && preg_match('/(\d{2}:\d{2}).*?(\d{2}:\d{2})/', $fest, $m)) {
        $start = 'DTSTART:' . ics_zeit($datum, $m[1]);
        $ende  = 'DTEND:'   . ics_zeit($datum, $m[2]);
    } else {
        $start = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($datum));
        $ende  = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($datum . ' +1 day'));
    }

    z('BEGIN:VEVENT');
    z('UID:manuell-' . $datum . '@tonfluestern.de');
    z('DTSTAMP:' . $jetzt);
    z($start);
    z($ende);
    z('SUMMARY:' . ics_text(sprintf('Von Hand blockiert – %d %s',
        $anzahl, $anzahl === 1 ? 'Platz' : 'Plätze')));
    z('DESCRIPTION:' . ics_text(
        "Diese Plätze wurden in der Übersicht von Hand eingetragen "
      . "(telefonisch, per E-Mail oder direkt im Laden).\n\n"
      . "Übersicht: https://tonfluestern.de/admin.php"));
    z('LOCATION:' . ics_text('Tonflüstern, Hauptstraße 43, 64711 Erbach'));
    z('STATUS:CONFIRMED');
    z('TRANSP:OPAQUE');
    z('END:VEVENT');
}

z('END:VCALENDAR');

/* ── Ausliefern ── */
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="tonfluestern.ics"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo implode("\r\n", $zeilen) . "\r\n";
