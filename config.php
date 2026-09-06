<?php
/* ══════════════════════════════════════════════════════════
   TONFLÜSTERN — ZENTRALE EINSTELLUNGEN
   ══════════════════════════════════════════════════════════
   Hier änderst du alles Wichtige. Die anderen PHP-Dateien
   musst du nicht anfassen.
══════════════════════════════════════════════════════════ */

/* Plätze pro Tag */
const MAX_PER_DAY = 18;

/* An welche Adresse gehen die Anfragen? */
const EMPFAENGER = 'keramikcafe@tonfluestern.de';

/* Passwort für die Übersichtsseite admin.php
   >>> ÄNDERE DIESES PASSWORT <<<                            */
const ADMIN_PASS = 'jCXIIKrxGTEeAZtL';

/* Öffnungszeiten. 0=So 1=Mo 2=Di 3=Mi 4=Do 5=Fr 6=Sa */
const OPEN_HOURS = [
  3 => '15:00 – 18:00 Uhr',   // Mittwoch
  4 => '15:00 – 18:00 Uhr',   // Donnerstag
  5 => '15:00 – 20:00 Uhr',   // Freitag
];
const REQUEST_DAYS = [6, 0];  // Samstag, Sonntag – auf Anfrage
const CLOSED_DAYS  = [1, 2];  // Montag, Dienstag – geschlossen

/* Wie weit im Voraus darf gebucht werden (Tage)? */
const VORLAUF_TAGE = 365;

/* Welche Angebote koennen angefragt werden?
   Aendere hier, wenn ein Angebot dazukommt oder wegfaellt —
   Formular und Pruefung ziehen die Liste automatisch nach. */
const ANGEBOTE = ['Keramik bemalen', 'Töpfern'];

/* Schluessel fuer den Kalender-Abo-Link (kalender.php).
   Er steht in der Adresse, die du in deinem Kalender eintraegst,
   und schuetzt die Kundendaten vor fremdem Zugriff.
   Aenderst du ihn, musst du das Abo im Kalender neu einrichten. */
const KALENDER_KEY = 'omlry06src94to43dz477kss4cb5';


/* ══════════════════════════════════════════════════════════
   AB HIER TECHNIK — normalerweise nichts zu ändern
══════════════════════════════════════════════════════════ */

const DATA_DIR  = __DIR__ . '/daten';
const LOCK_FILE = DATA_DIR . '/.lock';

/* Die Datendatei endet bewusst auf .php und beginnt mit einer
   Abbruch-Anweisung. Sollte jemand sie direkt im Browser
   aufrufen, führt der Server sie als PHP aus, das Skript bricht
   sofort ab und gibt nichts preis. Der Schutz greift also auch
   dann, wenn die .htaccess einmal nicht wirken sollte.        */
const DATA_FILE  = DATA_DIR . '/anfragen.php';
const DATA_GUARD = "<?php exit; /* Datendatei – kein direkter Zugriff */ ?>\n";

/** Datenverzeichnis anlegen und gegen Web-Zugriff schützen. */
function daten_verzeichnis(): void {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        );
    }
    $idx = DATA_DIR . '/index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, '');
    }
}

/**
 * Alle Daten laden.
 *
 * Gibt NULL zurueck, wenn die Datei zwar existiert, aber nicht gelesen
 * oder nicht ausgewertet werden kann. Das ist wichtig: frueher lieferte
 * diese Funktion in dem Fall eine leere Struktur, die anschliessend
 * ueber den echten Bestand geschrieben wurde — ein einziger Lesefehler
 * haette alle Buchungen geloescht.
 *
 * Eine noch nicht angelegte Datei ist dagegen kein Fehler und liefert
 * die leere Struktur.
 */
function daten_laden(): ?array {
    $leer = ['anfragen' => [], 'manuell' => []];

    if (!is_file(DATA_FILE)) {
        return $leer;                       // gibt es noch nicht — in Ordnung
    }

    $roh = @file_get_contents(DATA_FILE);
    if ($roh === false) {
        return null;                        // vorhanden, aber unlesbar
    }
    if (trim($roh) === '') {
        return null;                        // leergelaufen — verdaechtig
    }

    // Schutz-Präfix abschneiden (auch mit vorangestelltem BOM)
    $roh = preg_replace('/^\xEF\xBB\xBF/', '', $roh);
    if (strncmp($roh, '<?php', 5) === 0) {
        $nl = strpos($roh, "\n");
        if ($nl === false) {
            return null;                    // Guard ohne Zeilenumbruch — defekt
        }
        $roh = substr($roh, $nl + 1);
    }

    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return null;                        // kaputtes JSON
    }

    return [
        'anfragen' => is_array($d['anfragen'] ?? null) ? $d['anfragen'] : [],
        'manuell'  => is_array($d['manuell']  ?? null) ? $d['manuell']  : [],
    ];
}

/** Wie daten_laden(), aber nie null — fuer reine Leseansichten. */
function daten_laden_sicher(): array {
    return daten_laden() ?? ['anfragen' => [], 'manuell' => []];
}

/** Daten atomar speichern (erst in temporäre Datei, dann umbenennen). */
function daten_speichern(array $d): bool {
    daten_verzeichnis();
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    // Vorherigen Stand sichern, damit ein Fehlgriff reparierbar bleibt
    if (is_file(DATA_FILE)) {
        @copy(DATA_FILE, DATA_FILE . '.bak');
    }

    $tmp = DATA_FILE . '.tmp';
    if (@file_put_contents($tmp, DATA_GUARD . $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, DATA_FILE);
}

/**
 * Führt $fn exklusiv aus — verhindert, dass zwei gleichzeitige
 * Anfragen sich gegenseitig überschreiben.
 * $fn bekommt die Daten per Referenz und gibt einen Rückgabewert zurück.
 */
function mit_sperre(callable $fn) {
    daten_verzeichnis();

    $fh = @fopen(LOCK_FILE, 'c');
    if ($fh !== false) {
        @flock($fh, LOCK_EX);
    }

    try {
        $d = daten_laden();

        // Bestand nicht lesbar -> auf keinen Fall darueber schreiben
        if ($d === null) {
            throw new RuntimeException('Datenbestand nicht lesbar');
        }

        $r = $fn($d);

        if (!daten_speichern($d)) {
            throw new RuntimeException('Datenbestand nicht speicherbar');
        }

        return $r;
    } finally {
        if ($fh !== false) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}

/** Wie viele Plätze sind an diesem Tag vergeben? */
function belegt(array $d, string $datum): int {
    $n = 0;
    foreach ($d['anfragen'] as $a) {
        if (($a['datum'] ?? '') === $datum && ($a['status'] ?? 'offen') !== 'storniert') {
            $n += (int)($a['personen'] ?? 0);
        }
    }
    $n += (int)($d['manuell'][$datum] ?? 0);
    return max(0, $n);
}

/** Freie Plätze an diesem Tag. */
function frei(array $d, string $datum): int {
    return max(0, MAX_PER_DAY - belegt($d, $datum));
}

/** Ist das ein Tag, an dem überhaupt gebucht werden kann? */
function buchbarer_tag(string $datum): bool {
    $ts = strtotime($datum);
    if ($ts === false) {
        return false;
    }
    $wt = (int)date('w', $ts);
    return isset(OPEN_HOURS[$wt]) || in_array($wt, REQUEST_DAYS, true);
}

/** Prüft ein Datum auf Format und erlaubten Zeitraum. */
function datum_gueltig(string $datum): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
        return false;
    }
    [$j, $m, $t] = array_map('intval', explode('-', $datum));
    if (!checkdate($m, $t, $j)) {
        return false;
    }
    $heute = strtotime('today');
    $ts    = strtotime($datum);
    return $ts >= $heute && $ts <= strtotime('+' . VORLAUF_TAGE . ' days', $heute);
}
