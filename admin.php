<?php
/* Übersicht aller Anfragen — nur für den Betreiber.
   Passwort steht in config.php.                        */

require __DIR__ . '/config.php';

session_start();

/* ── Anmeldung ── */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!($_SESSION['auth'] ?? false)) {
    $fehler = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pass'])) {
        if (hash_equals(ADMIN_PASS, (string)$_POST['pass'])) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: admin.php');
            exit;
        }
        $fehler = 'Passwort stimmt nicht.';
        usleep(600000);                       // bremst Rateversuche
    }
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Anmeldung – Tonflüstern</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <style>
      body{font-family:system-ui,sans-serif;background:#f4ece0;color:#291b0f;
           display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
      form{background:#ebe2d2;padding:2.5rem;border-left:3px solid #7a5230;max-width:340px;width:100%}
      h1{font-size:1.2rem;margin:0 0 1.5rem;font-weight:500}
      input{width:100%;padding:.7rem;border:1px solid rgba(122,82,48,.3);background:#fff;
            font-size:1rem;box-sizing:border-box;margin-bottom:1rem}
      button{width:100%;padding:.8rem;background:#a84c2a;color:#f4ece0;border:none;
             font-size:.8rem;letter-spacing:.15em;text-transform:uppercase;cursor:pointer}
      .err{color:#a84c2a;font-size:.85rem;margin-bottom:1rem}
    </style></head><body>
    <form method="post">
      <h1>Tonflüstern — Anfragen</h1>
      <?php if ($fehler !== ''): ?><p class="err"><?= htmlspecialchars($fehler) ?></p><?php endif; ?>
      <input type="password" name="pass" placeholder="Passwort" autofocus required>
      <button type="submit">Anmelden</button>
    </form></body></html>
    <?php
    exit;
}

/* ── Aktionen ── */
$hinweis = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try {

    // Anfrage stornieren -> Plätze werden wieder frei
    if (isset($_POST['stornieren'])) {
        $id = (string)$_POST['stornieren'];
        mit_sperre(function (array &$d) use ($id) {
            foreach ($d['anfragen'] as &$a) {
                if (($a['id'] ?? '') === $id) { $a['status'] = 'storniert'; }
            }
        });
        $hinweis = 'Anfrage storniert — die Plätze sind wieder frei.';
    }

    // Stornierung rückgängig
    if (isset($_POST['aktivieren'])) {
        $id = (string)$_POST['aktivieren'];
        mit_sperre(function (array &$d) use ($id) {
            foreach ($d['anfragen'] as &$a) {
                if (($a['id'] ?? '') === $id) { $a['status'] = 'offen'; }
            }
        });
        $hinweis = 'Anfrage wieder aktiv.';
    }

    // Anfrage endgültig löschen
    if (isset($_POST['loeschen'])) {
        $id = (string)$_POST['loeschen'];
        mit_sperre(function (array &$d) use ($id) {
            $d['anfragen'] = array_values(array_filter(
                $d['anfragen'],
                fn($a) => ($a['id'] ?? '') !== $id
            ));
        });
        $hinweis = 'Anfrage gelöscht.';
    }

    // Plätze von Hand blocken (z.B. telefonische Buchung)
    if (isset($_POST['manuell_datum'])) {
        $datum  = (string)$_POST['manuell_datum'];
        $anzahl = (int)($_POST['manuell_anzahl'] ?? 0);
        if (datum_gueltig($datum)) {
            mit_sperre(function (array &$d) use ($datum, $anzahl) {
                if ($anzahl > 0) {
                    $d['manuell'][$datum] = min($anzahl, MAX_PER_DAY);
                } else {
                    unset($d['manuell'][$datum]);
                }
            });
            $hinweis = 'Handeintrag für ' . date('d.m.Y', strtotime($datum)) . ' gespeichert.';
        } else {
            $hinweis = 'Das Datum war ungültig.';
        }
    }

  } catch (Throwable $ex) {
      error_log('Tonfluestern Admin: ' . $ex->getMessage());
      $hinweis = 'Die Änderung konnte nicht gespeichert werden: ' . $ex->getMessage()
               . '. Es wurde nichts überschrieben.';
  }
}

/* ── Daten aufbereiten ── */
$d     = daten_laden_sicher();
$heute = date('Y-m-d');

$offene = array_filter($d['anfragen'], fn($a) => ($a['datum'] ?? '') >= $heute);
usort($offene, fn($x, $y) => [$x['datum'] ?? '', $x['erstellt'] ?? ''] <=> [$y['datum'] ?? '', $y['erstellt'] ?? '']);

$vergangen = array_filter($d['anfragen'], fn($a) => ($a['datum'] ?? '') < $heute);

// nach Tagen gruppieren
$tage = [];
foreach ($offene as $a) {
    $tage[$a['datum']][] = $a;
}
foreach (array_keys($d['manuell']) as $datum) {
    if ($datum >= $heute && !isset($tage[$datum])) {
        $tage[$datum] = [];
    }
}
ksort($tage);

$wt_namen = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];

// Abo-Adresse fuer den Kalender zusammenbauen
$schema  = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'tonfluestern.de';
$basis   = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
$kal_url = $schema . '://' . $host . $basis . '/kalender.php?key=' . KALENDER_KEY;
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Anfragen – Tonflüstern</title>
<link rel="icon" href="favicon.ico" sizes="any">
<style>
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;background:#f4ece0;color:#291b0f;
       margin:0;padding:1.5rem;line-height:1.6}
  .wrap{max-width:1000px;margin:0 auto}
  header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;
         gap:1rem;margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid rgba(122,82,48,.2)}
  h1{font-size:1.4rem;margin:0;font-weight:500}
  a.logout{font-size:.75rem;color:#7a5230;text-decoration:none;border:1px solid rgba(122,82,48,.35);
           padding:.4rem 1rem}
  .hinweis{background:#e1d5c2;border-left:3px solid #7a5230;padding:.8rem 1.2rem;margin-bottom:1.5rem;font-size:.9rem}
  .tag{background:#ebe2d2;margin-bottom:1.2rem;border-left:3px solid #7a5230}
  .tag.voll{border-left-color:#a84c2a}
  .tag-kopf{padding:.9rem 1.2rem;display:flex;justify-content:space-between;
            align-items:baseline;flex-wrap:wrap;gap:.5rem;background:rgba(122,82,48,.07)}
  .tag-kopf strong{font-size:1.05rem;font-weight:600}
  .zaehler{font-size:.85rem;font-weight:600;color:#7a5230}
  .zaehler.voll{color:#a84c2a}
  table{width:100%;border-collapse:collapse;font-size:.87rem}
  th{text-align:left;font-weight:600;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;
     color:#7a5230;padding:.6rem 1.2rem;border-bottom:1px solid rgba(122,82,48,.15)}
  td{padding:.7rem 1.2rem;border-bottom:1px solid rgba(122,82,48,.09);vertical-align:top}
  tr.storniert td{opacity:.45;text-decoration:line-through}
  .msg{color:#5e4535;font-size:.82rem;max-width:320px;white-space:pre-wrap;word-break:break-word}
  .ang{display:inline-block;font-size:.75rem;padding:.15rem .5rem;background:rgba(122,82,48,.1);
       border:1px solid rgba(122,82,48,.2);white-space:nowrap}
  button{font-family:inherit;font-size:.72rem;padding:.3rem .7rem;border:1px solid rgba(122,82,48,.35);
         background:#f4ece0;color:#5e4535;cursor:pointer;white-space:nowrap}
  button:hover{background:#d4c5af}
  button.rot{border-color:rgba(168,76,42,.4);color:#a84c2a}
  .manuell{background:#ebe2d2;padding:1.2rem;margin-top:2.5rem;border-left:3px solid #7a5230}
  .manuell h2{font-size:1rem;margin:0 0 .5rem;font-weight:600}
  .manuell p{font-size:.83rem;color:#5e4535;margin:0 0 1rem}
  .manuell form{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
  .manuell input{padding:.5rem;border:1px solid rgba(122,82,48,.3);background:#fff;font-family:inherit}
  .manuell button{padding:.55rem 1.2rem;background:#a84c2a;color:#f4ece0;border:none}
  .leer{color:#a8917a;font-size:.9rem;padding:2rem 0;text-align:center}
  .fuss{margin-top:2.5rem;font-size:.78rem;color:#a8917a}
  a{color:#7a5230}
  @media(max-width:640px){
    body{padding:1rem}
    th:nth-child(4),td:nth-child(4),th:nth-child(5),td:nth-child(5){display:none}
  }
</style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>Anfragen — Tonflüstern</h1>
    <a class="logout" href="?logout=1">Abmelden</a>
  </header>

  <?php if ($hinweis !== ''): ?>
    <div class="hinweis"><?= $e($hinweis) ?></div>
  <?php endif; ?>

  <?php if (!$tage): ?>
    <p class="leer">Noch keine Anfragen für kommende Tage.</p>
  <?php endif; ?>

  <?php foreach ($tage as $datum => $liste):
      $ts    = strtotime($datum);
      $wt    = (int)date('w', $ts);
      $bel   = belegt($d, $datum);
      $frei  = max(0, MAX_PER_DAY - $bel);
      $voll  = $frei === 0;
      $manu  = (int)($d['manuell'][$datum] ?? 0);
  ?>
    <div class="tag <?= $voll ? 'voll' : '' ?>">
      <div class="tag-kopf">
        <strong><?= $e($wt_namen[$wt]) ?>, <?= date('d.m.Y', $ts) ?></strong>
        <span class="zaehler <?= $voll ? 'voll' : '' ?>">
          <?= $bel ?> / <?= MAX_PER_DAY ?> belegt
          <?= $voll ? ' — AUSGEBUCHT' : ' — noch ' . $frei . ' frei' ?>
          <?= $manu > 0 ? ' (davon ' . $manu . ' von Hand)' : '' ?>
        </span>
      </div>

      <?php if ($liste): ?>
      <table>
        <tr>
          <th>Pers.</th><th>Angebot</th><th>Name</th><th>Kontakt</th><th>Nachricht</th><th></th>
        </tr>
        <?php foreach ($liste as $a):
            $stor = ($a['status'] ?? 'offen') === 'storniert'; ?>
          <tr class="<?= $stor ? 'storniert' : '' ?>">
            <td><strong><?= (int)$a['personen'] ?></strong></td>
            <td><span class="ang"><?= $e($a['angebot'] ?? '—') ?></span></td>
            <td>
              <?= $e($a['name'] ?? '') ?><br>
              <span style="font-size:.75rem;color:#a8917a">
                <?= $e(date('d.m. H:i', strtotime($a['erstellt'] ?? 'now'))) ?>
              </span>
            </td>
            <td>
              <a href="mailto:<?= $e($a['email'] ?? '') ?>"><?= $e($a['email'] ?? '') ?></a>
              <?php if (($a['telefon'] ?? '') !== ''): ?>
                <br><a href="tel:<?= $e($a['telefon']) ?>"><?= $e($a['telefon']) ?></a>
              <?php endif; ?>
            </td>
            <td class="msg"><?= $e($a['nachricht'] ?? '') ?: '—' ?></td>
            <td>
              <form method="post" style="display:flex;gap:.3rem;flex-wrap:wrap">
                <?php if ($stor): ?>
                  <button name="aktivieren" value="<?= $e($a['id'] ?? '') ?>">Aktivieren</button>
                <?php else: ?>
                  <button name="stornieren" value="<?= $e($a['id'] ?? '') ?>">Stornieren</button>
                <?php endif; ?>
                <button class="rot" name="loeschen" value="<?= $e($a['id'] ?? '') ?>"
                        onclick="return confirm('Diese Anfrage endgültig löschen?')">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="manuell">
    <h2>Plätze von Hand blocken</h2>
    <p>
      Für Buchungen die nicht über die Website kamen — telefonisch, per E-Mail oder direkt im Laden.
      Diese Plätze zählen zusätzlich zu den Anfragen oben. Trage <strong>0</strong> ein, um einen Handeintrag zu entfernen.
    </p>
    <form method="post">
      <input type="date" name="manuell_datum" required>
      <input type="number" name="manuell_anzahl" min="0" max="<?= MAX_PER_DAY ?>" value="0" required style="width:90px">
      <button type="submit">Speichern</button>
    </form>
  </div>

  <div class="manuell" style="margin-top:1.5rem">
    <h2>Kalender abonnieren</h2>
    <p>
      Trage diese Adresse einmal in deinem Kalender ein (Hetzner Webmail, Handy, Outlook &hellip;)
      &mdash; danach erscheinen alle Anfragen dort automatisch. Die Adresse enth&auml;lt
      Kundendaten, gib sie also nicht weiter.
    </p>
    <p style="background:#f4ece0;border:1px solid rgba(122,82,48,.25);padding:.7rem 1rem;
              font-family:ui-monospace,monospace;font-size:.78rem;word-break:break-all;margin:0">
      <?= $e($kal_url) ?>
    </p>
  </div>

  <p class="fuss">
    <?= count($offene) ?> Anfragen für kommende Tage,
    <?= count($vergangen) ?> vergangene.
    <?php if ($vergangen): ?>
      Vergangene Anfragen bleiben gespeichert, blockieren aber keine Plätze mehr.
    <?php endif; ?>
  </p>

</div>
</body>
</html>
