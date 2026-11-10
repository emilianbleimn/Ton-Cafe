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
$anzeigeart  = [];
foreach ($d['manuell'] as $datum => $anzahl) {
    if ($datum >= $heute) {
        $summe[$datum]       = ($summe[$datum] ?? 0) + (int)$anzahl;
        $handeintrag[$datum] = max(0, min((int)$anzahl, MAX_PER_DAY));

        /* Wie der Tag heissen soll, hat der Betrieb beim Blocken
           festgelegt. Steht dort nichts — etwa bei Eintraegen aus
           der Zeit davor —, bleibt es bei der alten Regel: ganz
           geblockt heisst geschlossen.                          */
        $art = $d['manuell_art'][$datum] ?? '';
        if (in_array($art, ['zu', 'voll'], true)) {
            $anzeigeart[$datum] = $art;
        }
    }
}

/* Belegung je Wochenend-Block. Nur fuer kommende Wochenendtage,
   an denen ueberhaupt etwas liegt — sonst waere die Antwort voller
   Nullen. Je Tag eine Liste in der Reihenfolge der Bloecke. */
$blockbelegt = [];
foreach ($d['anfragen'] as $a) {
    $datum = (string)($a['datum'] ?? '');
    if ($datum < $heute || ($a['status'] ?? 'offen') === 'storniert') {
        continue;
    }
    if (!in_array((int)date('w', strtotime($datum)), REQUEST_DAYS, true)) {
        continue;
    }
    $i = array_search((string)($a['wunschzeit'] ?? ''), WOCHENEND_BLOECKE, true);
    if ($i === false) {
        continue;                       // Anfrage ohne bekannten Block
    }
    if (!isset($blockbelegt[$datum])) {
        $blockbelegt[$datum] = array_fill(0, count(WOCHENEND_BLOECKE), 0);
    }
    $blockbelegt[$datum][$i] += (int)($a['personen'] ?? 0);
}
/* Von Hand geblockte Plaetze gelten fuer den ganzen Tag und zaehlen
   deshalb in beiden Bloecken mit. */
foreach ($handeintrag as $datum => $anzahl) {
    if (!in_array((int)date('w', strtotime($datum)), REQUEST_DAYS, true)) {
        continue;
    }
    if (!isset($blockbelegt[$datum])) {
        $blockbelegt[$datum] = array_fill(0, count(WOCHENEND_BLOECKE), 0);
    }
    foreach ($blockbelegt[$datum] as $i => $n) {
        $blockbelegt[$datum][$i] = $n + (int)$anzahl;
    }
}
foreach ($blockbelegt as $datum => $werte) {
    $blockbelegt[$datum] = array_map(
        fn($n) => max(0, min((int)$n, MAX_PER_DAY)), $werte);
}
ksort($blockbelegt);

// auf gültigen Bereich begrenzen
foreach ($summe as $datum => $anzahl) {
    $summe[$datum] = max(0, min((int)$anzahl, MAX_PER_DAY));
}

ksort($summe);

ksort($handeintrag);
ksort($anzeigeart);

echo json_encode([
    'max'     => MAX_PER_DAY,
    /* Sind Wochenend-Anfragen gerade gestoppt? Der Kalender zeigt
       Samstag und Sonntag dann als geschlossen. */
    'wochenende_zu' => (bool)($d['wochenende_zu'] ?? false),
    /* Einzelne gestoppte Wochenendtage. Nur kommende — vergangene
       haetten keine Wirkung und blaehen die Antwort nur auf. */
    'wochenende_tage' => array_values(array_filter(
        $d['wochenende_tage'] ?? [], fn($t) => $t >= $heute)),
    /* Die beiden Wochenend-Zeitfenster und wie voll sie sind.
       Der Kalender braucht beides: die Texte fuer die Auswahl und
       je Tag, welcher Block schon belegt ist. Es gehen nur Zahlen
       hinaus, keine Namen. */
    'bloecke'  => WOCHENEND_BLOECKE,
    'blockbelegt' => (object)$blockbelegt,
    'belegt'  => (object)$summe,
    'manuell' => (object)$handeintrag,
    'art'     => (object)$anzeigeart,
], JSON_UNESCAPED_UNICODE);
