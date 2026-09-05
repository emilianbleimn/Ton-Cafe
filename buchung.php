<?php
/* Nimmt eine Terminanfrage entgegen, prüft die freien Plätze,
   speichert sie und schickt eine E-Mail an den Betreiber.

   Antwortet immer als JSON:
     { "ok": true,  "frei": 6, "datum": "..." }
     { "ok": false, "fehler": "Text für den Besucher" }        */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function antwort(array $a, int $code = 200): never {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    antwort(['ok' => false, 'fehler' => 'Ungültiger Aufruf.'], 405);
}

/* ── Spamschutz: unsichtbares Feld muss leer bleiben ── */
if (trim((string)($_POST['website'] ?? '')) !== '') {
    antwort(['ok' => true, 'frei' => MAX_PER_DAY, 'datum' => '']);   // still verwerfen
}

/* ── Eingaben einlesen und säubern ── */
$feld = static function (string $name, int $max): string {
    $v = (string)($_POST[$name] ?? '');
    $v = str_replace(["\r", "\0"], '', $v);
    $v = trim($v);
    return mb_substr($v, 0, $max);
};

$name     = $feld('name', 100);
$email    = $feld('email', 150);
$telefon  = $feld('telefon', 60);
$datum    = $feld('wunschtermin', 10);
$nachricht= $feld('nachricht', 2000);
$angebot  = $feld('angebot', 60);
$personen = (int)($_POST['personen'] ?? 0);

/* ── Prüfungen ── */
if ($name === '' || mb_strlen($name) < 2) {
    antwort(['ok' => false, 'fehler' => 'Bitte gib deinen Namen an.'], 422);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    antwort(['ok' => false, 'fehler' => 'Bitte gib eine gültige E-Mail-Adresse an.'], 422);
}
if (!in_array($angebot, ANGEBOTE, true)) {
    antwort(['ok' => false, 'fehler' => 'Bitte wähle aus, ob du Keramik bemalen oder töpfern möchtest.'], 422);
}
if ($personen < 1 || $personen > MAX_PER_DAY) {
    antwort(['ok' => false, 'fehler' => 'Bitte gib eine Personenanzahl zwischen 1 und ' . MAX_PER_DAY . ' an.'], 422);
}
if ($datum === '' || !datum_gueltig($datum)) {
    antwort(['ok' => false, 'fehler' => 'Bitte wähle einen gültigen Termin im Kalender.'], 422);
}
if (!buchbarer_tag($datum)) {
    antwort(['ok' => false, 'fehler' => 'An diesem Tag haben wir geschlossen. Bitte wähle einen anderen Tag.'], 422);
}

$wt        = (int)date('w', strtotime($datum));
$oeffnung  = OPEN_HOURS[$wt] ?? 'Auf Anfrage';
$auf_anfr  = !isset(OPEN_HOURS[$wt]);

/* ── Speichern unter Sperre, damit die Plätze nicht doppelt vergeben werden ── */
$ergebnis = mit_sperre(function (array &$d) use (
    $datum, $personen, $name, $email, $telefon, $nachricht, $oeffnung, $angebot
) {
    $noch_frei = frei($d, $datum);

    if ($personen > $noch_frei) {
        return [
            'ok'    => false,
            'frei'  => $noch_frei,
            'grund' => $noch_frei === 0
                ? 'ausgebucht'
                : 'zu_viele',
        ];
    }

    $d['anfragen'][] = [
        'id'        => bin2hex(random_bytes(8)),
        'datum'     => $datum,
        'angebot'   => $angebot,
        'personen'  => $personen,
        'name'      => $name,
        'email'     => $email,
        'telefon'   => $telefon,
        'nachricht' => $nachricht,
        'zeit'      => $oeffnung,
        'status'    => 'offen',
        'erstellt'  => date('Y-m-d H:i:s'),
        'ip'        => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ];

    return ['ok' => true, 'frei' => $noch_frei - $personen];
});

if (!$ergebnis['ok']) {
    $fehler = $ergebnis['grund'] === 'ausgebucht'
        ? 'Dieser Tag ist inzwischen leider ausgebucht. Bitte wähle einen anderen Termin.'
        : 'An diesem Tag sind nur noch ' . $ergebnis['frei'] . ' '
          . ($ergebnis['frei'] === 1 ? 'Platz' : 'Plätze') . ' frei.';
    antwort(['ok' => false, 'fehler' => $fehler, 'frei' => $ergebnis['frei']], 409);
}

/* ── Benachrichtigung an den Betreiber ── */
$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
$datum_lang = $wochentage[$wt] . ', ' . date('d.m.Y', strtotime($datum));

$betreff = 'Neue Anfrage: ' . $angebot . ' am ' . $datum_lang . ' – ' . $personen
         . ($personen === 1 ? ' Person' : ' Personen');

$text = "Neue Terminanfrage über tonfluestern.de\n"
      . str_repeat('=', 45) . "\n\n"
      . "Angebot:     $angebot\n"
      . "Termin:      $datum_lang\n"
      . "Zeit:        $oeffnung" . ($auf_anfr ? "  (auf Anfrage)" : "") . "\n"
      . "Personen:    $personen\n"
      . "Noch frei:   " . $ergebnis['frei'] . " von " . MAX_PER_DAY . "\n\n"
      . "Name:        $name\n"
      . "E-Mail:      $email\n"
      . "Telefon:     " . ($telefon !== '' ? $telefon : '—') . "\n\n"
      . "Nachricht:\n" . ($nachricht !== '' ? $nachricht : '—') . "\n\n"
      . str_repeat('-', 45) . "\n"
      . "Übersicht aller Anfragen: https://tonfluestern.de/admin.php\n";

$header = [
    'From: Tonfluestern Website <' . EMPFAENGER . '>',
    'Reply-To: ' . str_replace(["\r", "\n"], '', $name) . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: Tonfluestern',
];

@mail(
    EMPFAENGER,
    '=?UTF-8?B?' . base64_encode($betreff) . '?=',
    $text,
    implode("\r\n", $header)
);

/* Die Anfrage ist gespeichert, auch wenn die Mail scheitern sollte —
   sie steht in jedem Fall in admin.php.                              */

antwort([
    'ok'    => true,
    'frei'  => $ergebnis['frei'],
    'datum' => $datum,
]);
