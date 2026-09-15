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

    // Anfrage bestätigen
    if (isset($_POST['bestaetigen'])) {
        $id = (string)$_POST['bestaetigen'];
        mit_sperre(function (array &$d) use ($id) {
            foreach ($d['anfragen'] as &$a) {
                if (($a['id'] ?? '') === $id) { $a['status'] = 'bestaetigt'; }
            }
        });
        $hinweis = 'Anfrage bestätigt. Schick der Kundin oder dem Kunden jetzt die Bestätigung.';
    }

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

/**
 * Baut Betreff und Text für die Antwort an die Kundin oder den Kunden.
 * $art: 'bestaetigt' oder 'storniert'
 */
function vorlage(array $a, string $art): array {
    $wt_lang = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    $ts    = strtotime((string)($a['datum'] ?? 'now'));
    $wt    = (int)date('w', $ts);
    $tag   = $wt_lang[$wt] . ', ' . date('d.m.Y', $ts);
    $kurz  = date('d.m.Y', $ts);
    $vname = trim(explode(' ', trim((string)($a['name'] ?? '')))[0]);
    $pers  = (int)($a['personen'] ?? 0);
    $ang   = (string)($a['angebot'] ?? 'Keramik bemalen');

    // Samstag und Sonntag laufen auf Anfrage — dort gibt es keine feste Zeit
    $wochenende = !isset(OPEN_HOURS[$wt]);

    /* Anrede: bei mehreren angemeldeten Personen das vertraute "ihr",
       bei einer einzelnen Person die foermliche Anrede "Sie". */
    $w = ($pers > 1)
        ? [
            'moechte'  => 'ihr zu Tonflüstern kommen möchtet',
            'dativ'    => 'euch',
            'akkusativ'=> 'euch',
            'kommt'    => 'kommt',
            'fragen'   => 'ihr Fragen habt, meldet euch',
            'possessiv'=> 'Eure',
            'deine'    => 'eure',
            'seid'     => 'seid ihr',
            'schreib'  => 'Schreibt',
            'frag'     => 'fragt',
          ]
        : [
            'moechte'  => 'Sie zu Tonflüstern kommen möchten',
            'dativ'    => 'Ihnen',
            'akkusativ'=> 'Sie',
            'kommt'    => 'kommen Sie',
            'fragen'   => 'Sie Fragen haben, melden Sie sich',
            'possessiv'=> 'Ihre',
            'deine'    => 'Ihre',
            'seid'     => 'sind Sie',
            'schreib'  => 'Schreiben Sie',
            'frag'     => 'fragen Sie',
          ];

    // Anfangszeit aus der Oeffnungszeit loesen: "15:00 – 18:00 Uhr" -> "15:00 Uhr"
    $beginn = '[Uhrzeit eintragen]';
    if (!$wochenende && preg_match('/(\d{1,2}:\d{2})/', OPEN_HOURS[$wt], $mm)) {
        $beginn = $mm[1] . ' Uhr';
    }

    // Oeffnungszeiten als Aufzaehlung, damit sie in den Absagen aktuell bleiben
    $zeilen = [];
    foreach (OPEN_HOURS as $t => $z) {
        $zeilen[] = '   ' . $wt_lang[$t] . ' von ' . $z;
    }
    $zeitenliste = implode("\n", $zeilen);

    /* ── Absage ── */
    if ($art === 'storniert') {

        if ($wochenende) {
            return [
                'betreff' => $w['possessiv'] . ' Anfrage für den ' . $kurz . ' – Tonflüstern',
                'text' =>
"Hallo $vname,

vielen Dank für {$w['deine']} Anfrage für den $tag!

Leider kann ich diesen Termin nicht einrichten. Samstage und Sonntage
kann ich nur anbieten, wenn es zeitlich passt — dieses Mal klappt es
leider nicht.

[Wenn du magst, hier kurz den Grund ergänzen.]

Unter der Woche {$w['seid']} jederzeit herzlich willkommen:

$zeitenliste

{$w['schreib']} mir einfach, welcher Tag {$w['dativ']} passt — oder {$w['frag']} gerne noch
einmal für ein anderes Wochenende an.

Es tut mir leid, dass es dieses Mal nicht klappt. Ich hoffe, wir sehen
uns bald!

" . GRUSS,
            ];
        }

        return [
            'betreff' => $w['possessiv'] . ' Anfrage für den ' . $kurz . ' – Tonflüstern',
            'text' =>
"Hallo $vname,

vielen Dank für {$w['deine']} Anfrage für den $tag!

Leider kann ich {$w['dativ']} diesen Termin nicht anbieten.

[Hier kurz den Grund ergänzen – zum Beispiel: der Tag ist inzwischen
ausgebucht.]

Sehr gerne finden wir einen anderen Termin:

$zeitenliste

{$w['schreib']} mir einfach, welcher Tag {$w['dativ']} passt.

Es tut mir leid, dass es dieses Mal nicht klappt. Ich hoffe, wir sehen
uns bald!

" . GRUSS,
        ];
    }

    /* ── Zusage ── */
    $hinweis = $wochenende
        ? "Da Samstage und Sonntage bei uns auf Anfrage laufen, trage ich\n{$w['dativ']} die oben genannte Uhrzeit ein."
        : "Bitte {$w['kommt']} zur angegebenen Anfangszeit, damit {$w['dativ']} genügend Zeit\nzum kreativen Gestalten bleibt.";

    return [
        'betreff' => $w['possessiv'] . ' Terminbestätigung – Tonflüstern',
        'text' =>
"Hallo $vname,

wie schön, dass {$w['moechte']}! Hiermit bestätige ich {$w['dativ']} gerne den folgenden Termin:

Angebot: $ang
Datum: $kurz
Beginn: $beginn
Personen: $pers

$hinweis

Die Bezahlung ist vor Ort bar oder per PayPal möglich. Falls sich noch etwas ändern sollte oder {$w['fragen']} gerne bei mir.

Ich freue mich auf eine schöne kreative Zeit mit {$w['dativ']}!

" . GRUSS,
    ];
}
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
  .mailstatus{display:block;font-size:.7rem;margin-top:.25rem;line-height:1.4}
  .ms-ok  {color:#4a6b3a}
  .ms-fehl{color:#a84c2a;font-weight:600}
  .ms-aus {color:#a8917a}
  button{font-family:inherit;font-size:.72rem;padding:.3rem .7rem;border:1px solid rgba(122,82,48,.35);
         background:#f4ece0;color:#5e4535;cursor:pointer;white-space:nowrap}
  button:hover{background:#d4c5af}
  button.rot{border-color:rgba(168,76,42,.4);color:#a84c2a}
  button.gruen{background:#4a6b3a;border-color:#4a6b3a;color:#f4ece0}
  button.gruen:hover{background:#3d5930}
  .mailknopf{margin-top:.35rem;width:100%;background:#e1d5c2;border-color:rgba(122,82,48,.3)}
  .st{display:inline-block;font-size:.66rem;letter-spacing:.08em;text-transform:uppercase;
      padding:.08rem .45rem;border:1px solid;margin-right:.35rem}
  .st-offen{color:#7a5230;border-color:rgba(122,82,48,.35)}
  .st-best{color:#4a6b3a;border-color:rgba(74,107,58,.45);background:rgba(74,107,58,.09)}
  .st-stor{color:#a84c2a;border-color:rgba(168,76,42,.4)}
  .mailzeile td{background:rgba(122,82,48,.05);padding:0}
  .mailbox{padding:1.2rem}
  .mailbox label{display:block;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;
                 color:#7a5230;margin:.8rem 0 .25rem}
  .mailbox label:first-child{margin-top:0}
  .mailbox input,.mailbox textarea{width:100%;padding:.55rem .7rem;background:#fff;
       border:1px solid rgba(122,82,48,.25);font-family:inherit;font-size:.85rem;color:#291b0f}
  .mailbox textarea{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.55;resize:vertical}
  .mailaktionen{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem}
  .mailaktionen button{padding:.5rem 1.1rem;font-size:.75rem}
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
            $status = $a['status'] ?? 'offen';
            $stor   = $status === 'storniert';
            $best   = $status === 'bestaetigt';
            $vb     = vorlage($a, $stor ? 'storniert' : 'bestaetigt');
            $rid    = 'm' . preg_replace('/[^a-z0-9]/i', '', (string)($a['id'] ?? '')); ?>
          <tr class="<?= $stor ? 'storniert' : '' ?>">
            <td><strong><?= (int)$a['personen'] ?></strong></td>
            <td><span class="ang"><?= $e($a['angebot'] ?? '—') ?></span></td>
            <td>
              <?= $e($a['name'] ?? '') ?><br>
              <span class="st <?= $stor ? 'st-stor' : ($best ? 'st-best' : 'st-offen') ?>">
                <?= $stor ? 'storniert' : ($best ? 'bestätigt' : 'offen') ?>
              </span>
              <span style="font-size:.72rem;color:#a8917a">
                <?= $e(date('d.m. H:i', strtotime($a['erstellt'] ?? 'now'))) ?>
              </span>
              <?php
                /* Wurde die automatische Antwort verschickt?
                   Fehlt der Eintrag ganz, stammt die Anfrage aus der Zeit
                   vor dieser Aufzeichnung — dann wird nichts angezeigt. */
                if (array_key_exists('mail_kunde', $a)):
                    $mk = $a['mail_kunde'];
                    if ($mk === null): ?>
                      <span class="mailstatus ms-aus">automatische Antwort ist abgeschaltet</span>
                <?php elseif ($mk): ?>
                      <span class="mailstatus ms-ok">✓ Bestätigung verschickt<?=
                        isset($a['mail_zeit']) ? ' · ' . $e(date('d.m. H:i', strtotime($a['mail_zeit']))) : '' ?></span>
                <?php else: ?>
                      <span class="mailstatus ms-fehl">✕ Bestätigung konnte nicht verschickt werden</span>
                <?php endif;
                endif;
                // Kam die Benachrichtigung an den Betrieb selbst durch?
                if (array_key_exists('mail_betreiber', $a) && !$a['mail_betreiber']): ?>
                      <span class="mailstatus ms-fehl">✕ Benachrichtigung an dich fehlgeschlagen</span>
                <?php endif; ?>
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
                  <?php if (!$best): ?>
                    <button class="gruen" name="bestaetigen" value="<?= $e($a['id'] ?? '') ?>">Bestätigen</button>
                  <?php endif; ?>
                  <button name="stornieren" value="<?= $e($a['id'] ?? '') ?>">Stornieren</button>
                <?php endif; ?>
                <button class="rot" name="loeschen" value="<?= $e($a['id'] ?? '') ?>"
                        onclick="return confirm('Diese Anfrage endgültig löschen?')">Löschen</button>
              </form>
              <button type="button" class="mailknopf" onclick="mailAuf('<?= $rid ?>')">
                <?= $stor ? 'Absage schreiben' : 'Bestätigung schreiben' ?>
              </button>
            </td>
          </tr>

          <tr class="mailzeile" id="<?= $rid ?>" hidden>
            <td colspan="6">
              <div class="mailbox">
                <label>Empfänger</label>
                <input type="text" id="<?= $rid ?>-to" value="<?= $e($a['email'] ?? '') ?>">

                <label>Betreff</label>
                <input type="text" id="<?= $rid ?>-sub" value="<?= $e($vb['betreff']) ?>">

                <label>Nachricht — du kannst den Text vor dem Senden anpassen</label>
                <textarea id="<?= $rid ?>-body" rows="16"><?= $e($vb['text']) ?></textarea>

                <div class="mailaktionen">
                  <button type="button" class="gruen" onclick="mailOeffnen('<?= $rid ?>')"><?= WEBMAIL_COMPOSE !== '' ? 'Im Webmail öffnen' : 'In E-Mail-Programm öffnen' ?></button>
                  <button type="button" onclick="mailKopieren('<?= $rid ?>', this)">Text kopieren</button>
                  <button type="button" onclick="mailZu('<?= $rid ?>')">Schließen</button>
                </div>
              </div>
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
<script>
/* Aufklappen der Mail-Vorlage */
function mailAuf(id) {
  const z = document.getElementById(id);
  z.hidden = false;
  z.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  document.getElementById(id + '-body').focus();
}
function mailZu(id) { document.getElementById(id).hidden = true; }

/* Vorlage im Webmail oeffnen.
   Ist WEBMAIL_COMPOSE leer, wird das normale Mailprogramm verwendet. */
const WEBMAIL = <?= json_encode(WEBMAIL_COMPOSE) ?>;

function mailOeffnen(id) {
  const to   = document.getElementById(id + '-to').value.trim();
  const sub  = document.getElementById(id + '-sub').value;
  const body = document.getElementById(id + '-body').value;

  if (!to) { alert('Es ist keine Empfängeradresse eingetragen.'); return; }

  const url = WEBMAIL
    ? WEBMAIL.replace('{to}', encodeURIComponent(to))
             .replace('{subject}', encodeURIComponent(sub))
             .replace('{body}', encodeURIComponent(body))
    : 'mailto:' + encodeURIComponent(to)
        + '?subject=' + encodeURIComponent(sub)
        + '&body='    + encodeURIComponent(body);

  window.open(url, '_blank', 'noopener');
}

/* Fallback: Text in die Zwischenablage */
async function mailKopieren(id, knopf) {
  const t = document.getElementById(id + '-body').value;
  const fertig = () => {
    const alt = knopf.textContent;
    knopf.textContent = 'Kopiert';
    setTimeout(() => { knopf.textContent = alt; }, 1600);
  };
  try {
    await navigator.clipboard.writeText(t);
    fertig();
  } catch {
    const f = document.getElementById(id + '-body');
    f.select(); f.setSelectionRange(0, 99999);
    try { document.execCommand('copy'); fertig(); }
    catch { alert('Kopieren hat nicht geklappt — bitte den Text von Hand markieren.'); }
  }
}
</script>
</body>
</html>
