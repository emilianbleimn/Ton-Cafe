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

foreach ($d['manuell'] as $datum => $anzahl) {
    if ($datum >= $heute) {
        $summe[$datum] = ($summe[$datum] ?? 0) + (int)$anzahl;
    }
}

// auf gültigen Bereich begrenzen
foreach ($summe as $datum => $anzahl) {
    $summe[$datum] = max(0, min((int)$anzahl, MAX_PER_DAY));
}

ksort($summe);

echo json_encode([
    'max'    => MAX_PER_DAY,
    'belegt' => (object)$summe,
], JSON_UNESCAPED_UNICODE);
