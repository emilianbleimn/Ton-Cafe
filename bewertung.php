<?php
/* Nimmt eine Bewertung entgegen.
   ══════════════════════════════════════════════════════════
   Die Bewertung wird gespeichert, aber NICHT veroeffentlicht.
   Sie bekommt den Status "neu" und erscheint erst auf der
   Website, wenn sie in der Uebersicht freigegeben wurde.

   Antwort als JSON:
     { "ok": true }
     { "ok": false, "fehler": "..." }                       */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function antwort(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    antwort(['ok' => false, 'fehler' => 'Nur per Formular.'], 405);
}
if (!BEWERTUNGEN_AN) {
    antwort(['ok' => false, 'fehler' => 'Bewertungen sind gerade abgeschaltet.'], 403);
}

/* Spam-Falle: Das Feld ist im Browser unsichtbar. Wer es
   ausfuellt, ist ein Programm. Die Antwort sieht trotzdem nach
   Erfolg aus — sonst weiss der Absender, dass er aufgefallen ist. */
if (trim((string)($_POST['website'] ?? '')) !== '') {
    antwort(['ok' => true]);
}

$feld = static function (string $name, int $max): string {
    $v = (string)($_POST[$name] ?? '');
    $v = str_replace(["\r", "\0"], '', $v);
    $v = trim($v);
    return mb_substr($v, 0, $max);
};

$name   = $feld('name', 60);
$text   = $feld('text', BEWERTUNG_MAX_ZEICHEN);
$sterne = (int)($_POST['sterne'] ?? 0);

/* ── Pruefungen ── */
if (mb_strlen($name) < 2) {
    antwort(['ok' => false, 'fehler' => 'Bitte gib deinen Namen an.'], 422);
}
if ($sterne < 1 || $sterne > 5) {
    antwort(['ok' => false, 'fehler' => 'Bitte vergib zwischen einem und fünf Sternen.'], 422);
}
if (mb_strlen($text) < 10) {
    antwort(['ok' => false, 'fehler' => 'Bitte schreib ein paar Worte mehr — mindestens zehn Zeichen.'], 422);
}

/* Mehr als zwei Internet-Adressen im Text sind kein Erfahrungs-
   bericht mehr, sondern Werbung. */
if (preg_match_all('#https?://|www\.#i', $text) > 2) {
    antwort(['ok' => false, 'fehler' => 'Bitte schreib deine Bewertung ohne Links.'], 422);
}

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

try {
    $ergebnis = mit_sperre(function (array &$d) use ($name, $text, $sterne, $ip) {

        /* Kurze Sperre je Absender — verhindert, dass jemand in
           einem Rutsch zwanzig Bewertungen hinterlaesst. */
        if ($ip !== '' && BEWERTUNG_SPERRE_MIN > 0) {
            $grenze = time() - BEWERTUNG_SPERRE_MIN * 60;
            foreach ($d['bewertungen'] as $b) {
                if (($b['ip'] ?? '') === $ip
                    && strtotime((string)($b['erstellt'] ?? '')) > $grenze) {
                    return 'zu_schnell';
                }
            }
        }

        $d['bewertungen'][] = [
            'id'       => bin2hex(random_bytes(8)),
            'name'     => $name,
            'sterne'   => $sterne,
            'text'     => $text,
            'erstellt' => date('Y-m-d H:i:s'),
            'status'   => 'neu',     // erst nach Freigabe sichtbar
            'ip'       => $ip,
        ];
        return 'ok';
    });
} catch (Throwable $e) {
    error_log('Bewertung nicht speicherbar: ' . $e->getMessage());
    antwort(['ok' => false,
             'fehler' => 'Das hat gerade nicht geklappt. Bitte versuch es später noch einmal.'], 503);
}

if ($ergebnis === 'zu_schnell') {
    antwort(['ok' => false,
             'fehler' => 'Du hast gerade schon eine Bewertung geschrieben. Danke dafür!'], 429);
}

antwort(['ok' => true]);
