<?php
/* Liefert dem Kalender die aktuelle Belegung als JSON.
   Es werden nur Datum und Anzahl übermittelt — keine Namen,
   keine E-Mail-Adressen, nichts Persönliches.              */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$d     = daten_laden_sicher();
$heute = date('Y-m-d');
$summe = [];

foreach ($d['anfragen'] as $a) {
    $datum = $a['datum'] ?? '';
    if ($datum < $heute || ($a['status'] ?? 'offen') === 'storniert') {
        continue;
    }
    $summe[$datum] = ($summe[$datum] ?? 0) + (int)($a['personen'] ?? 0);
}

/* Von Hand blockierte Plaetze werden getrennt mitgeliefert. Der
   Kalender kann sonst nicht unterscheiden, ob ein Tag von Gaesten
   ausgebucht wurde oder vom Betrieb geschlossen wurde — beides
   saehe gleich aus, und "ausgebucht" waere dann schlicht falsch. */
$handeintrag = [];
foreach ($d['manuell'] as $datum => $anzahl) {
    if ($datum >= $heute) {
        $summe[$datum]       = ($summe[$datum] ?? 0) + (int)$anzahl;
        $handeintrag[$datum] = max(0, min((int)$anzahl, MAX_PER_DAY));
    }
}

// auf gültigen Bereich begrenzen
foreach ($summe as $datum => $anzahl) {
    $summe[$datum] = max(0, min((int)$anzahl, MAX_PER_DAY));
}

ksort($summe);

ksort($handeintrag);

echo json_encode([
    'max'     => MAX_PER_DAY,
    'belegt'  => (object)$summe,
    'manuell' => (object)$handeintrag,
], JSON_UNESCAPED_UNICODE);
