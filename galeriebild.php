<?php
/* Liefert ein Bild aus dem Ordner galerie/ in der gewuenschten
   Breite und legt das Ergebnis ab.
   ══════════════════════════════════════════════════════════
   Warum ueberhaupt: In der Uebersicht liegen viele Bilder
   nebeneinander. Wuerde dort jeweils das Original geladen,
   kaeme bei dreissig Fotos schnell ein Vielfaches dessen
   zusammen, was noetig ist — auf dem Handy ueber Mobilfunk
   dauert das spuerbar. Deshalb werden verkleinerte Fassungen
   erzeugt und in galerie/.cache abgelegt; ab dem zweiten
   Aufruf kommen sie direkt von dort.

   Fehlt die Bildbibliothek GD auf dem Server, wird einfach das
   Original ausgeliefert. Die Galerie funktioniert dann
   genauso, nur mit groesseren Dateien.

   Aufruf:  galeriebild.php?d=<dateiname>&b=<breite>        */

$ordner = __DIR__ . '/galerie';
$cache  = $ordner . '/.cache';

/* ── Eingaben pruefen ──
   Der Dateiname kommt aus der Adresszeile und wird deshalb
   streng geprueft: nur der reine Name, keine Pfade, keine
   Punkte am Anfang. Sonst liesse sich damit im Dateisystem
   herumwandern.                                            */
$datei = (string)($_GET['d'] ?? '');
$datei = basename($datei);

if ($datei === '' || $datei[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $datei)) {
    http_response_code(400);
    exit;
}

$quelle = $ordner . '/' . $datei;
if (!is_file($quelle)) {
    http_response_code(404);
    exit;
}

$masse = @getimagesize($quelle);
if ($masse === false) {
    http_response_code(415);           // keine Bilddatei
    exit;
}

/* Nur wenige feste Breiten zulassen — sonst koennte jemand
   mit beliebigen Werten beliebig viele Dateien erzeugen. */
$breite = (int)($_GET['b'] ?? 600);
$erlaubt = [400, 600, 900, 1400];
if (!in_array($breite, $erlaubt, true)) {
    $breite = 600;
}

/** Original unveraendert durchreichen. */
function durchreichen(string $pfad, array $masse): void {
    header('Content-Type: ' . $masse['mime']);
    header('Content-Length: ' . filesize($pfad));
    header('Cache-Control: public, max-age=604800');
    readfile($pfad);
    exit;
}

// Schon klein genug, oder keine Bildbibliothek vorhanden?
if ($masse[0] <= $breite || !extension_loaded('gd')) {
    durchreichen($quelle, $masse);
}

/* ── Abgelegte Fassung ── */
if (!is_dir($cache)) {
    @mkdir($cache, 0775, true);
}
$ziel = $cache . '/' . $breite . '_' . preg_replace('/\.[^.]+$/', '', $datei) . '.jpg';

// Ist die abgelegte Fassung aelter als das Original, neu erzeugen
if (is_file($ziel) && filemtime($ziel) >= filemtime($quelle)) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($ziel));
    header('Cache-Control: public, max-age=604800');
    readfile($ziel);
    exit;
}

$bild = match ($masse[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($quelle),
    IMAGETYPE_PNG  => @imagecreatefrompng($quelle),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($quelle) : false,
    default        => false,
};
if ($bild === false) {
    durchreichen($quelle, $masse);     // unbekanntes Format — lieber im Original
}

$hoehe = (int)round($masse[1] * $breite / $masse[0]);
$klein = imagecreatetruecolor($breite, $hoehe);

// Durchsichtige Flaechen wuerden sonst schwarz — auf Cremeweiss setzen
$grund = imagecolorallocate($klein, 244, 236, 224);
imagefilledrectangle($klein, 0, 0, $breite, $hoehe, $grund);
imagecopyresampled($klein, $bild, 0, 0, 0, 0, $breite, $hoehe, $masse[0], $masse[1]);

@imagejpeg($klein, $ziel, 82);
imagedestroy($bild);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
imagejpeg($klein, null, 82);
imagedestroy($klein);
