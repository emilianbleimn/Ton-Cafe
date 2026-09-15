<?php
/* ══════════════════════════════════════════════════════════
   DATENSCHUTZERKLAERUNG — EIGENE SEITE
   ══════════════════════════════════════════════════════════
   Erreichbar unter /datenschutz.php — eine Adresse ohne Raute,
   damit der Link sich ueberall eintragen laesst.

   Diese Datei braucht KEINE weitere Datei ausser der
   index.html. Sie laeuft fuer sich allein — es kann also
   nichts kaputtgehen, weil beim Hochladen etwas fehlt.

   Sie haelt auch KEINE eigene Kopie des Rechtstextes,
   sondern liest ihn bei jedem Aufruf aus der index.html.
   Damit gibt es den Text nur ein einziges Mal. Wird er dort
   geaendert, aendert er sich hier automatisch mit.
══════════════════════════════════════════════════════════ */

$rechtstext_id    = 'datenschutz';
$rechtstext_titel = 'Datenschutzerklärung';

/**
 * Holt den Inhalt eines Rechtstextes aus der index.html.
 *
 * Gesucht wird der Kasten <div class="lm" id="..."> und darin
 * das innere <div class="lm-inner">. Dessen Inhalt wird
 * zurueckgegeben — ohne das Schliesskreuz, das nur im
 * Fenster auf der Startseite einen Sinn ergibt.
 *
 * Gibt null zurueck, wenn sich der Text nicht finden laesst.
 */
function rechtstext_holen(string $id): ?string
{
    $quelle = __DIR__ . '/index.html';
    if (!is_file($quelle)) {
        return null;
    }
    $html = @file_get_contents($quelle);
    if ($html === false) {
        return null;
    }

    // Anfang des gesuchten Kastens
    $start = strpos($html, 'id="' . $id . '"');
    if ($start === false) {
        return null;
    }

    // Von dort zum inneren Kasten
    $innen = strpos($html, '<div class="lm-inner">', $start);
    if ($innen === false) {
        return null;
    }
    $pos = $innen + strlen('<div class="lm-inner">');

    /* Bis zum passenden </div> laufen und dabei mitzaehlen,
       wie viele <div> unterwegs geoeffnet werden. So endet
       die Suche am richtigen Schluss-Tag und nicht am ersten
       besten. */
    $tiefe = 1;
    $len   = strlen($html);
    $i     = $pos;
    while ($i < $len && $tiefe > 0) {
        $auf = strpos($html, '<div', $i);
        $zu  = strpos($html, '</div>', $i);
        if ($zu === false) {
            return null;                    // unvollstaendig
        }
        if ($auf !== false && $auf < $zu) {
            $tiefe++;
            $i = $auf + 4;
        } else {
            $tiefe--;
            if ($tiefe === 0) {
                $inhalt = substr($html, $pos, $zu - $pos);
                // Schliesskreuz entfernen — hier gibt es nichts zu schliessen
                $inhalt = preg_replace('#<a class="lm-close".*?</a>#s', '', $inhalt);
                return trim($inhalt);
            }
            $i = $zu + 6;
        }
    }
    return null;
}

$inhalt = rechtstext_holen($rechtstext_id);

if ($inhalt === null) {
    /* Der Text liess sich nicht lesen. Lieber ehrlich auf die
       Startseite verweisen, als eine leere Seite zeigen —
       dort steht er auf jeden Fall. */
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
       . '<title>' . htmlspecialchars($rechtstext_titel) . ' – Tonflüstern</title>'
       . '<p style="font:1rem/1.7 system-ui;max-width:34rem;margin:4rem auto;padding:0 1.5rem">'
       . htmlspecialchars($rechtstext_titel) . ' lässt sich gerade nicht anzeigen. '
       . 'Sie finden den Text auf der <a href="/#' . htmlspecialchars($rechtstext_id)
       . '">Startseite</a>.</p></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($rechtstext_titel) ?> – Tonflüstern</title>
  <meta name="description" content="<?= htmlspecialchars($rechtstext_titel) ?> von Tonflüstern – Keramikcafé, Hauptstraße 43, 64711 Erbach.">
  <link rel="canonical" href="https://tonfluestern.de/<?= htmlspecialchars($rechtstext_id) ?>.php">

  <link rel="icon" href="favicon.ico" sizes="any">
  <link rel="icon" type="image/png" href="favicon.png" sizes="180x180">
  <link rel="apple-touch-icon" href="favicon.png">

  <!-- Dieselben Schriften wie die Startseite, ebenfalls vom
       eigenen Server. Keine fremden Anbieter einbinden. -->
  <link rel="stylesheet" href="schriften.css">
  <style>
    :root {
      --bg-0: #f4ece0;
      --bg-1: #ebe2d2;
      --terra: #a84c2a;
      --bronze: #7a5230;
      --cream: #291b0f;
      --cream-mid: #5e4535;
      --cream-faint: #a8917a;
      --fd: 'Fraunces', Georgia, serif;
      --fb: 'Lato', system-ui, sans-serif;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: var(--fb); background: var(--bg-1); color: var(--cream);
      line-height: 1.75; padding: clamp(1.5rem, 5vw, 4rem) 1rem;
    }
    a { color: inherit; }
    .blatt {
      max-width: 700px; margin: 0 auto; background: var(--bg-0);
      padding: clamp(2rem, 6vw, 3.5rem) clamp(1.5rem, 5vw, 3.5rem);
    }
    .zurueck {
      display: inline-block; margin-bottom: 2rem; font-size: .72rem;
      letter-spacing: .22em; text-transform: uppercase;
      color: var(--cream-faint); text-decoration: none;
      border-bottom: 1px solid transparent; padding-bottom: .2rem;
      transition: color .3s, border-color .3s;
    }
    .zurueck:hover { color: var(--bronze); border-color: var(--bronze); }
    h2 { font-family: var(--fd); font-size: clamp(1.7rem, 5vw, 2rem); font-weight: 400; margin-bottom: 2rem; }
    h3 { font-family: var(--fd); font-size: 1.1rem; font-weight: 400; margin: 1.6rem 0 .5rem; }
    p  { font-size: .88rem; color: var(--cream-mid); margin-bottom: .8rem; }
    strong { color: var(--cream); }
    .fuss {
      max-width: 700px; margin: 1.5rem auto 0; font-size: .72rem;
      color: var(--cream-faint); display: flex; gap: 1.2rem; flex-wrap: wrap;
    }
    .fuss a { text-decoration: none; border-bottom: 1px solid rgba(122,82,48,.25); padding-bottom: .1rem; }
    .fuss a:hover { color: var(--bronze); border-color: var(--bronze); }
  </style>
</head>
<body>
  <main class="blatt">
    <a class="zurueck" href="/">&larr; Zurück zur Startseite</a>
<?= $inhalt ?>
  </main>
  <nav class="fuss">
    <a href="/">Startseite</a>
    <a href="/impressum.php">Impressum</a>
    <a href="/datenschutz.php">Datenschutz</a>
  </nav>
</body>
</html>
