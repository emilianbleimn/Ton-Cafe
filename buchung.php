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

/* Kennung schon hier erzeugen: nach dem Mailversand wird der Datensatz
   damit wiedergefunden, um festzuhalten ob die Mails rausgingen. */
$anfrage_id = bin2hex(random_bytes(8));

/* ── Speichern unter Sperre, damit die Plätze nicht doppelt vergeben werden ── */
try {
$ergebnis = mit_sperre(function (array &$d) use (
    $datum, $personen, $name, $email, $telefon, $nachricht, $oeffnung, $angebot, $anfrage_id
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
        'id'        => $anfrage_id,
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
} catch (Throwable $ex) {
    // Bestand nicht lesbar oder nicht schreibbar: lieber ehrlich abbrechen,
    // als eine Anfrage stillschweigend zu verlieren oder Daten zu ueberschreiben
    error_log('Tonfluestern Buchung: ' . $ex->getMessage());
    antwort([
        'ok'     => false,
        'fehler' => 'Die Anfrage konnte gerade nicht gespeichert werden. '
                  . 'Bitte versuche es in ein paar Minuten noch einmal oder '
                  . 'schreib mir direkt an ' . EMPFAENGER . '.',
    ], 503);
}

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

$mail_betreiber = @mail(
    EMPFAENGER,
    '=?UTF-8?B?' . base64_encode($betreff) . '?=',
    $text,
    implode("\r\n", $header)
);

/* ── Automatische Antwort an die Kundin oder den Kunden ──
   Zwei Faelle, weil sie sich inhaltlich unterscheiden:

   Mittwoch bis Freitag laufen zu festen Zeiten und die freien Plaetze
   sind geprueft — hier wird der Termin direkt zugesagt.

   Samstag und Sonntag laufen ausdruecklich auf Anfrage: es gibt keine
   feste Anfangszeit und der Betrieb muss erst zustimmen. Eine
   automatische Zusage waere hier eine Aussage, die niemand geprueft
   hat, deshalb geht dort nur eine Eingangsbestaetigung raus.

   Dazu die Anrede: bei mehreren angemeldeten Personen das vertraute
   "ihr", bei einer einzelnen Person die foermliche Anrede "Sie".   */
$mail_kunde = null;                 // null = automatische Antwort ist abgeschaltet
if (AUTO_ANTWORT) {
    $vorname    = trim(explode(' ', $name)[0]);
    $datum_kurz = date('d.m.Y', strtotime($datum));

    $w = ($personen > 1)
        ? [
            'moechte'  => 'ihr zu Tonflüstern kommen möchtet',
            'dativ'    => 'euch',
            'kommt'    => 'kommt',
            'fragen'   => 'ihr Fragen habt, meldet euch',
            'aufWen'   => 'euch',
            'anfrage'  => 'Eure Anfrage',
            'possessiv'=> 'Eure',
          ]
        : [
            'moechte'  => 'Sie zu Tonflüstern kommen möchten',
            'dativ'    => 'Ihnen',
            'kommt'    => 'kommen Sie',
            'fragen'   => 'Sie Fragen haben, melden Sie sich',
            'aufWen'   => 'Sie',
            'anfrage'  => 'Ihre Anfrage',
            'possessiv'=> 'Ihre',
          ];

    $gruss = GRUSS . "\n";

    if ($auf_anfr) {
        /* ── Samstag / Sonntag: Anfrage eingegangen ── */
        $k_betreff = $w['possessiv'] . ' Anfrage bei Tonflüstern ist angekommen';

        $k_text =
"Hallo $vorname,

wie schön, dass {$w['moechte']}! {$w['anfrage']} ist bei mir angekommen.

Angebot: $angebot
Datum: $datum_kurz
Personen: $personen

Samstag und Sonntag sind bei uns nur auf Anfrage möglich. Ich schaue, ob ich den Termin einrichten kann, und melde mich innerhalb von " . ANTWORTFRIST . " bei {$w['dativ']} — dann auch mit einer festen Uhrzeit.

Die Bezahlung ist vor Ort bar oder per PayPal möglich. Falls sich noch etwas ändern sollte oder {$w['fragen']} gerne bei mir.

Ich freue mich auf {$w['aufWen']}!

" . $gruss;

    } else {
        /* ── Mittwoch bis Freitag: Termin zugesagt ── */
        $beginn = 'nach Absprache';
        if (preg_match('/(\d{1,2}:\d{2})/', $oeffnung, $mm)) {
            $beginn = $mm[1] . ' Uhr';
        }

        $k_betreff = $w['possessiv'] . ' Terminbestätigung – Tonflüstern';

        $k_text =
"Hallo $vorname,

wie schön, dass {$w['moechte']}! Hiermit bestätige ich {$w['dativ']} gerne den folgenden Termin:

Angebot: $angebot
Datum: $datum_kurz
Beginn: $beginn
Personen: $personen

Bitte {$w['kommt']} zur angegebenen Anfangszeit, damit {$w['dativ']} genügend Zeit zum kreativen Gestalten bleibt.

Die Bezahlung ist vor Ort bar oder per PayPal möglich. Falls sich noch etwas ändern sollte oder {$w['fragen']} gerne bei mir.

Ich freue mich auf eine schöne kreative Zeit mit {$w['dativ']}!

" . $gruss;
    }

    $k_header = [
        'From: Tonfluestern <' . EMPFAENGER . '>',
        'Reply-To: ' . EMPFAENGER,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: Tonfluestern',
        'Auto-Submitted: auto-replied',          // damit Mailserver es als
        'X-Auto-Response-Suppress: All',         // automatische Antwort erkennen
    ];

    // An die reine Adresse senden; der Name kommt bewusst nicht in den
    // Kopfbereich, damit dort nichts eingeschleust werden kann.
    $mail_kunde = @mail(
        $email,
        '=?UTF-8?B?' . base64_encode($k_betreff) . '?=',
        $k_text,
        implode("\r\n", $k_header)
    );
}

/* ── Ergebnis des Versands im Datensatz festhalten ──
   Damit laesst sich in der Uebersicht nachsehen, ob die automatische
   Antwort rausging. Wichtig zu wissen: ein Ja bedeutet, dass der
   Server die Mail zur Zustellung angenommen hat — nicht, dass sie im
   Postfach angekommen ist. Ob sie im Spam landet oder vom Empfaenger
   abgelehnt wird, laesst sich von hier aus nicht feststellen.       */
try {
    mit_sperre(function (array &$d) use ($anfrage_id, $mail_betreiber, $mail_kunde) {
        foreach ($d['anfragen'] as &$a) {
            if (($a['id'] ?? '') === $anfrage_id) {
                $a['mail_betreiber'] = (bool)$mail_betreiber;
                $a['mail_kunde']     = $mail_kunde;   // true, false oder null wenn abgeschaltet
                $a['mail_zeit']      = date('Y-m-d H:i:s');
            }
        }
    });
} catch (Throwable $ex) {
    // Nur die Notiz ist misslungen; die Anfrage selbst steht bereits sicher
    error_log('Tonfluestern Versandnotiz: ' . $ex->getMessage());
}

/* Die Anfrage ist gespeichert, auch wenn eine der Mails scheitern
   sollte — sie steht in jedem Fall in admin.php.                     */

antwort([
    'ok'    => true,
    'frei'  => $ergebnis['frei'],
    'datum' => $datum,
]);
