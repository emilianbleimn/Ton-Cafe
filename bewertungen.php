<?php
/* Liefert die freigegebenen Bewertungen fuer die Website.
   ══════════════════════════════════════════════════════════
   Es gehen nur Name, Sterne, Text und Datum hinaus — die
   IP-Adresse und noch nicht freigegebene Bewertungen bleiben
   hier. Sie stehen zwar in derselben Datei, verlassen den
   Server aber nicht.                                        */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$d    = daten_laden_sicher();
$liste = [];

foreach ($d['bewertungen'] as $b) {
    if (($b['status'] ?? 'neu') !== 'frei') {
        continue;                         // noch nicht freigegeben
    }
    $liste[] = [
        'name'   => (string)($b['name'] ?? ''),
        'sterne' => max(1, min(5, (int)($b['sterne'] ?? 5))),
        'text'   => (string)($b['text'] ?? ''),
        'datum'  => substr((string)($b['erstellt'] ?? ''), 0, 10),
    ];
}

// neueste zuerst, dann auf die gewuenschte Anzahl kuerzen
$liste = array_reverse($liste);
$gesamt = count($liste);
$liste  = array_slice($liste, 0, BEWERTUNG_ANZEIGE);

$schnitt = null;
if ($gesamt > 0) {
    $summe = 0;
    foreach ($d['bewertungen'] as $b) {
        if (($b['status'] ?? 'neu') === 'frei') {
            $summe += max(1, min(5, (int)($b['sterne'] ?? 5)));
        }
    }
    $schnitt = round($summe / $gesamt, 1);
}

echo json_encode([
    'an'      => BEWERTUNGEN_AN,
    'anzahl'  => $gesamt,
    'schnitt' => $schnitt,
    'liste'   => $liste,
], JSON_UNESCAPED_UNICODE);
