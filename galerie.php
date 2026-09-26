<?php
/* Listet die Bilder im Ordner galerie/ auf.
   ══════════════════════════════════════════════════════════
   Damit muss die Website nicht angefasst werden, wenn ein
   Bild dazukommt: Datei in den Ordner legen, fertig. Sie
   erscheint beim naechsten Aufruf der Seite.

   Nach aussen gehen nur Dateiname, Groesse und Seitenformat —
   nichts weiter.                                            */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$ordner = __DIR__ . '/galerie';

if (!is_dir($ordner)) {
    echo json_encode(['bilder' => []]);
    exit;
}

$erlaubt = ['jpg', 'jpeg', 'png', 'webp'];
$liste   = [];

foreach (scandir($ordner) ?: [] as $datei) {
    if ($datei[0] === '.') {
        continue;                                   // auch .cache ueberspringen
    }
    $pfad = $ordner . '/' . $datei;
    if (!is_file($pfad)) {
        continue;
    }
    $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
    if (!in_array($endung, $erlaubt, true)) {
        continue;
    }

    /* Seitenverhaeltnis mitgeben, damit der Browser den Platz
       schon kennt, bevor das Bild geladen ist. Ohne das
       springt die Seite beim Nachladen. */
    $masse = @getimagesize($pfad);

    $liste[] = [
        'datei' => $datei,
        'breite' => $masse ? (int)$masse[0] : null,
        'hoehe'  => $masse ? (int)$masse[1] : null,
        'zeit'   => (int)@filemtime($pfad),
    ];
}

/* Neueste zuerst — frisch hochgeladene Bilder stehen oben. */
usort($liste, function ($a, $b) {
    return $b['zeit'] <=> $a['zeit'] ?: strcmp($a['datei'], $b['datei']);
});

foreach ($liste as &$b) {
    unset($b['zeit']);
}
unset($b);

echo json_encode(['bilder' => $liste], JSON_UNESCAPED_UNICODE);
