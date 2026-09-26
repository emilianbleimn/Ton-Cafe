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
/* Eine Meldung aus der vorigen Aktion abholen. Sie wird in der
   Sitzung zwischengelagert, weil die Seite nach jeder Aktion neu
   geladen wird (siehe unten).                                  */
$hinweis = (string)($_SESSION['hinweis'] ?? '');
unset($_SESSION['hinweis']);

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
    /* ── Anfrage auf ein anderes Datum verschieben ──
       Der neue Tag muss buchbar sein UND genug Platz haben. Ohne
       diese Pruefung liesse sich eine Gruppe in einen vollen Tag
       schieben — und der waere dann ueberbucht, ohne dass es
       jemandem auffaellt. Die eigene Anfrage zaehlt beim Pruefen
       nicht mit, sonst blockierte sie sich selbst.            */
    if (isset($_POST['verschieben'])) {
        $id  = (string)$_POST['verschieben'];
        $neu = (string)($_POST['neues_datum'] ?? '');

        if ($neu === '') {
            $hinweis = 'Bitte zuerst ein Datum im Feld auswählen, dann auf Verschieben klicken.';
        } elseif (!datum_gueltig($neu)) {
            // Die Meldung wird beim Ausgeben maskiert ($e), hier also roh
            $hinweis = 'Das Datum ' . $neu . ' geht nicht — es muss zwischen heute und '
                     . date('d.m.Y', strtotime('+' . VORLAUF_TAGE . ' days')) . ' liegen.';
        } elseif (!buchbarer_tag($neu)) {
            $hinweis = 'An diesem Wochentag ist geschlossen — bitte ein anderes Datum wählen.';
        } else {
            $ergebnis = mit_sperre(function (array &$d) use ($id, $neu) {
                $treffer = null;
                foreach ($d['anfragen'] as $i => $a) {
                    if (($a['id'] ?? '') === $id) { $treffer = $i; break; }
                }
                if ($treffer === null) {
                    return ['ok' => false, 'grund' => 'weg'];
                }
                $a = $d['anfragen'][$treffer];

                if (($a['datum'] ?? '') === $neu) {
                    return ['ok' => false, 'grund' => 'gleich'];
                }

                /* Platz am Zieltag pruefen. Stornierte zaehlen nicht,
                   und diese Anfrage selbst auch nicht — sie zieht ja um. */
                $belegt_neu = (int)($d['manuell'][$neu] ?? 0);
                foreach ($d['anfragen'] as $i => $b) {
                    if ($i === $treffer) continue;
                    if (($b['datum'] ?? '') !== $neu) continue;
                    if (($b['status'] ?? 'offen') === 'storniert') continue;
                    $belegt_neu += (int)($b['personen'] ?? 0);
                }
                $frei_neu = MAX_PER_DAY - $belegt_neu;
                $pers     = (int)($a['personen'] ?? 0);
                if ($pers > $frei_neu) {
                    return ['ok' => false, 'grund' => 'voll', 'frei' => max(0, $frei_neu)];
                }

                /* Die Uhrzeit haengt am Wochentag: An festen Tagen gilt
                   die Oeffnungszeit, am Wochenende laeuft es auf Anfrage.
                   Wandert eine Anfrage vom Wochenende auf einen Werktag,
                   passt die alte Wunschzeit nicht mehr — sie bleibt aber
                   als Notiz erhalten, statt verloren zu gehen. */
                $wt   = (int)date('w', strtotime($neu));
                $alt  = (string)($a['datum'] ?? '');
                $d['anfragen'][$treffer]['datum'] = $neu;
                $d['anfragen'][$treffer]['zeit']  = OPEN_HOURS[$wt] ?? 'Auf Anfrage';

                if (isset(OPEN_HOURS[$wt]) && ($a['wunschzeit'] ?? '') !== '') {
                    $notiz = 'Wunschzeit vom ursprünglichen Termin: ' . $a['wunschzeit'];
                    $bisher = (string)($a['nachricht'] ?? '');
                    $d['anfragen'][$treffer]['nachricht'] =
                        $bisher === '' ? $notiz : $bisher . "\n" . $notiz;
                    $d['anfragen'][$treffer]['wunschzeit'] = '';
                }

                /* Jede Verschiebung anhaengen, nicht die vorige
                   ueberschreiben — sonst waere nach der zweiten nicht
                   mehr zu sehen, wo der Termin urspruenglich lag. */
                $verlauf = $a['verschoben'] ?? [];
                if (!is_array($verlauf)) { $verlauf = []; }
                $verlauf[] = $alt;
                $d['anfragen'][$treffer]['verschoben'] = $verlauf;
                unset($d['anfragen'][$treffer]['verschoben_von']);
                return ['ok' => true, 'von' => $alt];
            });

            if ($ergebnis['ok']) {
                $hinweis = 'Anfrage verschoben: '
                    . date('d.m.Y', strtotime($ergebnis['von'])) . ' → '
                    . date('d.m.Y', strtotime($neu))
                    . '. Bitte gib den Gästen Bescheid.';
            } elseif ($ergebnis['grund'] === 'voll') {
                $hinweis = 'Verschieben nicht möglich — am '
                    . date('d.m.Y', strtotime($neu)) . ' sind nur noch '
                    . $ergebnis['frei'] . ' Plätze frei.';
            } elseif ($ergebnis['grund'] === 'gleich') {
                $hinweis = 'Die Anfrage steht schon auf dem '
                    . date('d.m.Y', strtotime($neu))
                    . ' — wähle ein anderes Datum, um sie erneut zu verschieben.';
            } else {
                $hinweis = 'Die Anfrage wurde nicht gefunden.';
            }
        }
    }

    if (isset($_POST['bw_frei'])) {
        $id = (string)$_POST['bw_frei'];
        mit_sperre(function (array &$d) use ($id) {
            foreach ($d['bewertungen'] as &$b) {
                if (($b['id'] ?? '') === $id) { $b['status'] = 'frei'; }
            }
        });
        $hinweis = 'Bewertung freigegeben — sie steht jetzt auf der Website.';
    }
    if (isset($_POST['bw_zurueck'])) {
        $id = (string)$_POST['bw_zurueck'];
        mit_sperre(function (array &$d) use ($id) {
            foreach ($d['bewertungen'] as &$b) {
                if (($b['id'] ?? '') === $id) { $b['status'] = 'neu'; }
            }
        });
        $hinweis = 'Bewertung von der Website genommen.';
    }
    if (isset($_POST['bw_loeschen'])) {
        $id = (string)$_POST['bw_loeschen'];
        mit_sperre(function (array &$d) use ($id) {
            $d['bewertungen'] = array_values(array_filter(
                $d['bewertungen'],
                fn($b) => ($b['id'] ?? '') !== $id
            ));
        });
        $hinweis = 'Bewertung gelöscht.';
    }

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

    /* ── Anfrage bearbeiten ──
       Alles ausser Datum und Status laesst sich hier aendern. Das
       Datum hat mit "Verschieben" eine eigene Bedienung, weil dabei
       der Platz am Zieltag geprueft werden muss; den Status setzen
       die Knoepfe daneben.

       Geprueft wird wie beim Formular auf der Website: Name, E-Mail
       und Angebot muessen stimmen, und die Personenzahl darf den Tag
       nicht ueberbuchen. Schlaegt eine Pruefung fehl, wird nichts
       gespeichert — das Eingetippte bleibt aber in der Sitzung
       liegen und steht nach dem Neuladen wieder im Formular. Sonst
       waere bei einem Tippfehler in der E-Mail die ganze Eingabe
       verloren.                                                  */
    if (isset($_POST['bearbeiten'])) {
        $id = (string)$_POST['bearbeiten'];

        $f = static function (string $n, int $max): string {
            $v = str_replace(["\r", "\0"], '', (string)($_POST[$n] ?? ''));
            return mb_substr(trim($v), 0, $max);
        };

        $neu = [
            'personen'    => (int)($_POST['b_personen'] ?? 0),
            'angebot'     => $f('b_angebot', 60),
            'name'        => $f('b_name', 100),
            'email'       => $f('b_email', 150),
            'telefon'     => $f('b_telefon', 60),
            'anlass'      => $f('b_anlass', 60),
            'anlass_text' => $f('b_anlass_text', 80),
            'wunschzeit'  => $f('b_wunschzeit', 60),
            'nachricht'   => $f('b_nachricht', 2000),
        ];

        $problem = '';
        if (mb_strlen($neu['name']) < 2) {
            $problem = 'Der Name fehlt oder ist zu kurz.';
        } elseif ($neu['email'] === '' || !filter_var($neu['email'], FILTER_VALIDATE_EMAIL)) {
            $problem = 'Die E-Mail-Adresse „' . $neu['email'] . '" sieht nicht richtig aus.';
        } elseif (!in_array($neu['angebot'], ANGEBOTE, true)) {
            $problem = 'Bitte ein Angebot auswählen.';
        } elseif ($neu['anlass'] !== '' && !in_array($neu['anlass'], ANLAESSE, true)) {
            $problem = 'Diesen Anlass gibt es nicht.';
        } elseif ($neu['personen'] < 1 || $neu['personen'] > MAX_PER_DAY) {
            $problem = 'Die Personenzahl muss zwischen 1 und ' . MAX_PER_DAY . ' liegen.';
        }

        if ($problem !== '') {
            $hinweis = $problem . ' Es wurde nichts geändert — deine Eingaben stehen weiter im Formular.';
            $_SESSION['bearb_id']    = $id;
            $_SESSION['bearb_werte'] = $neu;
        } else {
            $ergebnis = mit_sperre(function (array &$d) use ($id, $neu) {
                $treffer = null;
                foreach ($d['anfragen'] as $i => $a) {
                    if (($a['id'] ?? '') === $id) { $treffer = $i; break; }
                }
                if ($treffer === null) { return ['ok' => false, 'grund' => 'weg']; }

                $a     = $d['anfragen'][$treffer];
                $datum = (string)($a['datum'] ?? '');

                /* Mehr Personen als frei sind? Dann waere der Tag
                   ueberbucht. Die Anfrage selbst zaehlt beim Rechnen
                   nicht mit, sonst blockierte sie sich selbst.
                   Bei stornierten Anfragen ist das egal — die
                   belegen ohnehin keinen Platz. */
                if (($a['status'] ?? 'offen') !== 'storniert') {
                    $sonst = (int)($d['manuell'][$datum] ?? 0);
                    foreach ($d['anfragen'] as $i => $b) {
                        if ($i === $treffer) { continue; }
                        if (($b['datum'] ?? '') !== $datum) { continue; }
                        if (($b['status'] ?? 'offen') === 'storniert') { continue; }
                        $sonst += (int)($b['personen'] ?? 0);
                    }
                    if ($neu['personen'] > MAX_PER_DAY - $sonst) {
                        return ['ok' => false, 'grund' => 'voll',
                                'frei' => max(0, MAX_PER_DAY - $sonst)];
                    }
                }

                /* Festhalten, was sich geaendert hat — damit spaeter
                   nachvollziehbar bleibt, was von Hand angepasst wurde
                   und was so aus dem Formular kam. */
                $geaendert = [];
                foreach ($neu as $k => $v) {
                    $vorher = $a[$k] ?? ($k === 'personen' ? 0 : '');
                    if ((string)$vorher !== (string)$v) { $geaendert[] = $k; }
                    $d['anfragen'][$treffer][$k] = $v;
                }
                if ($geaendert) {
                    $verlauf = $a['bearbeitet'] ?? [];
                    if (!is_array($verlauf)) { $verlauf = []; }
                    $verlauf[] = ['zeit' => date('Y-m-d H:i:s'), 'felder' => $geaendert];
                    $d['anfragen'][$treffer]['bearbeitet'] = $verlauf;
                }
                return ['ok' => true, 'anzahl' => count($geaendert)];
            });

            if (!empty($ergebnis['ok'])) {
                unset($_SESSION['bearb_id'], $_SESSION['bearb_werte']);
                $hinweis = $ergebnis['anzahl'] === 0
                    ? 'Es gab nichts zu ändern — es steht schon alles so da.'
                    : 'Änderungen gespeichert. Denk daran, den Gästen Bescheid zu geben,'
                      . ' wenn es sie betrifft.';
            } elseif (($ergebnis['grund'] ?? '') === 'voll') {
                $hinweis = 'So viele Personen passen an diesem Tag nicht mehr —'
                         . ' frei sind noch ' . $ergebnis['frei']
                         . '. Es wurde nichts geändert.';
                $_SESSION['bearb_id']    = $id;
                $_SESSION['bearb_werte'] = $neu;
            } else {
                $hinweis = 'Die Anfrage wurde nicht gefunden.';
            }
        }
    }

    // Plätze von Hand blocken (z.B. telefonische Buchung)
    if (isset($_POST['manuell_datum'])) {
        $datum  = (string)$_POST['manuell_datum'];
        $anzahl = (int)($_POST['manuell_anzahl'] ?? 0);
        // Nur die beiden bekannten Werte annehmen, sonst entscheidet
        // wie bisher die Anzahl.
        $art    = in_array($_POST['manuell_art'] ?? '', ['zu', 'voll'], true)
                  ? (string)$_POST['manuell_art'] : '';
        if (datum_gueltig($datum)) {
            mit_sperre(function (array &$d) use ($datum, $anzahl, $art) {
                if ($anzahl > 0) {
                    $d['manuell'][$datum] = min($anzahl, MAX_PER_DAY);
                    if ($art !== '') {
                        $d['manuell_art'][$datum] = $art;
                    } else {
                        unset($d['manuell_art'][$datum]);
                    }
                } else {
                    unset($d['manuell'][$datum], $d['manuell_art'][$datum]);
                }
            });
            $wort = $anzahl <= 0 ? 'entfernt'
                  : ($art === 'voll' ? 'gespeichert — der Tag erscheint als ausgebucht'
                  : ($art === 'zu'   ? 'gespeichert — der Tag erscheint als geschlossen'
                  : 'gespeichert'));
            $hinweis = 'Handeintrag für ' . date('d.m.Y', strtotime($datum)) . ' ' . $wort . '.';
        } else {
            $hinweis = 'Das Datum war ungültig.';
        }
    }

  } catch (Throwable $ex) {
      error_log('Tonfluestern Admin: ' . $ex->getMessage());
      $hinweis = 'Die Änderung konnte nicht gespeichert werden: ' . $ex->getMessage()
               . '. Es wurde nichts überschrieben.';
  }

  /* Nach der Aktion einmal neu laden lassen, statt die Antwort
     direkt auf das Formular zu schicken.

     Das hat zwei Gruende. Erstens fragt der Browser beim
     Aktualisieren sonst "Formular erneut senden?" und fuehrt die
     Aktion womoeglich ein zweites Mal aus. Zweitens behalten
     Browser die zuletzt eingetippten Feldinhalte bei, wenn eine
     Seite als Antwort auf ein Formular kommt — im Datumsfeld stand
     dann weiter der alte Wert.

     Die Weiterleitung ist aber nur eine Bequemlichkeit, keine
     Bedingung. Hat der Server vorher schon irgendetwas
     ausgegeben — eine Warnung genuegt —, laesst sie sich nicht
     mehr schicken. Frueher kam dann eine leere Seite, und es sah
     aus, als ginge gar nichts mehr. Deshalb wird hier geprueft:
     geht es nicht, wird die Seite einfach normal aufgebaut. Die
     Aktion ist zu diesem Zeitpunkt laengst ausgefuehrt.        */
  if (!headers_sent()) {
      $_SESSION['hinweis'] = $hinweis;
      header('Location: admin.php');
      exit;
  }
  // sonst: weiter unten normal anzeigen, mit der Meldung von oben
}

/* ── Daten aufbereiten ── */
$d     = daten_laden_sicher();

/* Hat das Bearbeiten nicht geklappt, liegen die eingetippten Werte
   noch in der Sitzung. Sie werden gleich wieder ins Formular
   gesetzt und das Formular dieser Anfrage wird aufgeklappt
   dargestellt — sonst muesste alles noch einmal getippt werden. */
$bearb_id    = (string)($_SESSION['bearb_id'] ?? '');
$bearb_werte = (array)($_SESSION['bearb_werte'] ?? []);
unset($_SESSION['bearb_id'], $_SESSION['bearb_werte']);
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

Die Bezahlung ist vor Ort bar oder mit Karte möglich. Falls sich noch etwas ändern sollte oder {$w['fragen']} gerne bei mir.

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
  .hinweis{background:#e1d5c2;border-left:3px solid #7a5230;padding:.8rem 1.2rem;
           margin-bottom:1.5rem;font-size:.9rem;
           /* Bleibt oben stehen, auch wenn weiter unten in der Liste
              geklickt wurde — sonst steht die Antwort des Servers
              ausserhalb des Bildschirms und es sieht aus, als waere
              nichts passiert. */
           position:sticky;top:0;z-index:5;box-shadow:0 .4rem .6rem -.4rem rgba(41,27,15,.25)}
  .tag{background:#ebe2d2;margin-bottom:1.2rem;border-left:3px solid #7a5230}
  .tag.voll{border-left-color:#a84c2a}
  .tag-kopf{padding:.9rem 1.2rem;display:flex;justify-content:space-between;
            align-items:baseline;flex-wrap:wrap;gap:.5rem;background:rgba(122,82,48,.07)}
  .tag-kopf strong{font-size:1.05rem;font-weight:600}
  .zaehler{font-size:.85rem;font-weight:600;color:#7a5230}
  .zaehler.voll{color:#a84c2a}
  /* Die Tabelle rollt in sich, nicht die ganze Seite. Sonst wanderten
     auf dem Handy die Knoepfe der letzten Spalte aus dem Bild heraus
     und liessen sich nicht mehr antippen. */
  .tabelle{overflow-x:auto;-webkit-overflow-scrolling:touch}
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
    .umbuchen { display: flex; gap: .3rem; align-items: center; flex-wrap: wrap;
                margin-top: .45rem; font-size: .75rem; }
    .umbuchen label { color: #a8917a; white-space: nowrap; }
    .umbuchen input[type=date] { font: inherit; padding: .2rem .35rem;
                border: 1px solid rgba(122,82,48,.3); background: #fff; color: #291b0f; }
    .umbuchen button { font-size: .75rem; padding: .25rem .6rem; }
    .umgebucht { display: inline-block; margin-top: .35rem; font-size: .72rem;
                 color: #7a5230; background: #f0e7d8; padding: .12rem .45rem; }
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
  /* ── Anfrage bearbeiten ── */
  .bearbzeile td{background:rgba(122,82,48,.09);padding:0}
  .bearb{padding:1.2rem}
  .bearb-gitter{display:grid;gap:.9rem;
                grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
  .bf label{display:block;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;
            color:#7a5230;margin:0 0 .25rem}
  .bf input,.bf select,.bf textarea{width:100%;box-sizing:border-box;padding:.55rem .7rem;
       background:#fff;border:1px solid rgba(122,82,48,.25);
       font-family:inherit;font-size:.9rem;color:#291b0f}
  .bf textarea{line-height:1.5;resize:vertical}
  .bf-klein{display:block;margin-top:.25rem;font-size:.7rem;color:#a8917a}
  .bf.weit{grid-column:1/-1}
  .bearb-hinweis{font-size:.78rem;color:#5e4535;margin:1rem 0 0}
  .bearb-aktionen{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem}
  .bearb-aktionen button{padding:.55rem 1.2rem;font-size:.78rem}
  .manuell{background:#ebe2d2;padding:1.2rem;margin-top:2.5rem;border-left:3px solid #7a5230}
  .manuell h2{font-size:1rem;margin:0 0 .5rem;font-weight:600}
  .manuell p{font-size:.83rem;color:#5e4535;margin:0 0 1rem}
  .manuell form{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
  .manuell input{padding:.5rem;border:1px solid rgba(122,82,48,.3);background:#fff;font-family:inherit}
  .manuell button{padding:.55rem 1.2rem;background:#a84c2a;color:#f4ece0;border:none}
  .leer{color:#a8917a;font-size:.9rem;padding:2rem 0;text-align:center}
  .fuss{margin-top:2.5rem;font-size:.78rem;color:#a8917a}
  a{color:#7a5230}
  /* ── Handy ──
     Nebeneinander passen sechs Spalten nicht auf ein Handy. Die
     Tabelle wurde dadurch breiter als der Bildschirm, und was rechts
     stand — also genau die Knoepfe — war nicht mehr erreichbar. Auf
     schmalen Geraeten steht deshalb jede Anfrage als Block
     untereinander. Jede Zeile bekommt ihre Beschriftung aus data-l,
     damit man weiterhin erkennt, was was ist.                     */
  @media(max-width:640px){
    body{padding:1rem}
    .tabelle{overflow-x:visible}
    .tabelle table,.tabelle tr,.tabelle td{display:block;width:auto}
    .tabelle tr.kopf{display:none}
    /* Muss nach der Regel darueber stehen: "display:block" von dort
       wuerde sonst das eingebaute Verstecken des Browsers aushebeln,
       und die aufklappbaren Zeilen staenden dauerhaft offen. */
    .tabelle tr[hidden],.tabelle td[hidden]{display:none}
    .tabelle tr.mailzeile,.tabelle tr.bearbzeile{padding:0}
    .tabelle tr.mailzeile td,.tabelle tr.bearbzeile td{padding:0}
    .mailbox,.bearb{padding:1.1rem}
    .bearb-aktionen button{width:100%}
    .tabelle tr{padding:.9rem 1.1rem;border-bottom:1px solid rgba(122,82,48,.15)}
    .tabelle tr:last-child{border-bottom:0}
    .tabelle td{border:0;padding:.3rem 0}
    .tabelle td[data-l]::before{content:attr(data-l);display:block;
                font-size:.66rem;letter-spacing:.09em;text-transform:uppercase;
                color:#a8917a;margin-bottom:.15rem}
    .msg{max-width:none}

    /* Datumsfeld und Knopf untereinander und auf ganzer Breite.
       Nebeneinander waren sie zusammen breiter als ein Handy. */
    .umbuchen{display:block}
    .umbuchen label{display:block;margin-bottom:.2rem}
    .umbuchen input[type=date]{width:100%;box-sizing:border-box;
                min-width:0;padding:.5rem .4rem}
    .umbuchen button{width:100%;margin-top:.4rem;padding:.55rem .6rem}
    button{min-height:2.4rem}
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
      <div class="tabelle">
      <table>
        <tr class="kopf">
          <th>Pers.</th><th>Angebot</th><th>Name</th><th>Kontakt</th><th>Nachricht</th><th></th>
        </tr>
        <?php foreach ($liste as $a):
            $status = $a['status'] ?? 'offen';
            $stor   = $status === 'storniert';
            $best   = $status === 'bestaetigt';
            $vb     = vorlage($a, $stor ? 'storniert' : 'bestaetigt');
            $rid    = 'm' . preg_replace('/[^a-z0-9]/i', '', (string)($a['id'] ?? ''));

            /* Stand in dieser Anfrage gerade ein Tippfehler? Dann
               werden die zurueckgehaltenen Eingaben angezeigt statt
               der gespeicherten Werte, und das Formular steht offen. */
            $b_auf = $bearb_id !== '' && $bearb_id === (string)($a['id'] ?? '');
            $bv    = function (string $k, $std = '') use ($a, $b_auf, $bearb_werte) {
                $quelle = $b_auf ? $bearb_werte : $a;
                return $quelle[$k] ?? $std;
            };
            /* Wie viele Plaetze waeren an diesem Tag frei, wenn diese
               Anfrage nicht mitgezaehlt wird? */
            $frei_ohne = $stor ? $frei : $frei + (int)($a['personen'] ?? 0); ?>
          <tr class="<?= $stor ? 'storniert' : '' ?>">
            <td data-l="Personen"><strong><?= (int)$a['personen'] ?></strong></td>
            <td data-l="Angebot"><span class="ang"><?= $e($a['angebot'] ?? '—') ?></span>
              <?php /* Am Wochenende gibt es keine feste Zeit — dann steht
                       hier, ab wann die Gaeste kommen moechten. */
              if (($a['wunschzeit'] ?? '') !== ''): ?>
                <br><span style="display:inline-block;margin-top:.3rem;font-size:.76rem;
                                 color:#7a5230;background:#f0e7d8;padding:.15rem .45rem;
                                 border-radius:2px;white-space:nowrap;">Wunschzeit:
                  <strong><?= $e($a['wunschzeit']) ?></strong></span>
              <?php endif; ?>
              <?php /* Anlass — bei "Sonstiges" mit der kurzen Erklaerung */
              if (($a['anlass'] ?? '') !== ''): ?>
                <br><span style="display:inline-block;margin-top:.3rem;font-size:.76rem;color:#5e4535;">
                  <?= $e($a['anlass']) ?><?= ($a['anlass_text'] ?? '') !== ''
                      ? ' — ' . $e($a['anlass_text']) : '' ?></span>
              <?php endif; ?>
            </td>
            <td data-l="Name">
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
            <td data-l="Kontakt">
              <a href="mailto:<?= $e($a['email'] ?? '') ?>"><?= $e($a['email'] ?? '') ?></a>
              <?php if (($a['telefon'] ?? '') !== ''): ?>
                <br><a href="tel:<?= $e($a['telefon']) ?>"><?= $e($a['telefon']) ?></a>
              <?php endif; ?>
            </td>
            <td class="msg" data-l="Nachricht"><?= $e($a['nachricht'] ?? '') ?: '—' ?></td>
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
              <button type="button" class="mailknopf" onclick="bearbAuf('<?= $rid ?>')">Bearbeiten</button>

              <?php /* Umbuchen: Der Server prueft, ob der Zieltag buchbar
                       ist und genug Platz hat. */ ?>
              <form method="post" class="umbuchen">
                <label for="<?= $rid ?>-neu">Verschieben auf</label>
                <?php /* Bewusst ohne required, min und max: Passt der Wert
                         nicht dazu, verweigert der Browser das Absenden
                         wortlos — der Knopf tut dann scheinbar nichts.
                         Die Pruefung macht ohnehin der Server, und der
                         sagt auch, was nicht stimmt. */ ?>
                <input type="date" id="<?= $rid ?>-neu" name="neues_datum"
                       autocomplete="off"
                       value="<?= $e($a['datum'] ?? '') ?>">
                <button type="submit" name="verschieben" value="<?= $e($a['id'] ?? '') ?>">Verschieben</button>
              </form>
              <?php
              /* Aeltere Eintraege haben noch das einzelne Feld — beide
                 Schreibweisen anzeigen, damit nichts verloren geht. */
              $weg = $a['verschoben'] ?? (($a['verschoben_von'] ?? '') !== '' ? [$a['verschoben_von']] : []);
              if (is_array($weg) && $weg):
                /* Bei vielen Verschiebungen wuerde die Kette sonst ueber
                   mehrere Zeilen laufen. Interessant sind die letzten
                   Stationen — die vollstaendige Kette steht im Tooltip. */
                $alle = array_map(fn($t) => date('d.m.', strtotime($t)), $weg);
                $alle[] = date('d.m.Y', strtotime($a['datum'] ?? 'now'));
                $kurz  = count($alle) > 4 ? array_slice($alle, -4) : $alle;
                $text  = (count($alle) > 4 ? '… → ' : '') . implode(' → ', $kurz); ?>
                <span class="umgebucht" title="<?= count($weg) ?>× verschoben: <?= $e(implode(' → ', $alle)) ?>">
                  verschoben: <?= $e($text) ?></span>
              <?php endif; ?>
              <?php
              /* Wann wurde zuletzt von Hand etwas geaendert? */
              $bhist = $a['bearbeitet'] ?? [];
              if (is_array($bhist) && $bhist):
                $letzte = end($bhist);
                $felder = is_array($letzte['felder'] ?? null) ? $letzte['felder'] : []; ?>
                <span class="umgebucht" title="<?= count($bhist) ?>× bearbeitet, zuletzt: <?= $e(implode(', ', $felder)) ?>">
                  bearbeitet: <?= $e(date('d.m. H:i', strtotime((string)($letzte['zeit'] ?? 'now')))) ?></span>
              <?php endif; ?>
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

          <?php /* ── Anfrage bearbeiten ──
                   Ein eigenes Formular, absichtlich getrennt von den
                   Knoepfen oben: Liegt alles in einem Formular,
                   bestaetigt die Eingabetaste in einem Textfeld die
                   Anfrage, statt die Aenderung zu speichern.

                   novalidate: Die Pruefung macht der Server. Der
                   Browser wuerde sonst bei einer Kleinigkeit wortlos
                   blockieren, und der Knopf saehe aus, als taete er
                   nichts.                                          */ ?>
          <tr class="bearbzeile" id="<?= $rid ?>-b"<?= $b_auf ? '' : ' hidden' ?>>
            <td colspan="6">
              <form method="post" class="bearb" novalidate>
                <div class="bearb-gitter">

                  <div class="bf">
                    <label for="<?= $rid ?>-b-pers">Personen</label>
                    <input type="number" inputmode="numeric" id="<?= $rid ?>-b-pers"
                           name="b_personen" value="<?= $e((string)(int)$bv('personen', 0)) ?>">
                    <span class="bf-klein">an diesem Tag wären <?= (int)$frei_ohne ?> möglich</span>
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-ang">Angebot</label>
                    <select id="<?= $rid ?>-b-ang" name="b_angebot">
                      <?php
                      $ang_jetzt = (string)$bv('angebot', '');
                      $ang_liste = ANGEBOTE;
                      // Steht dort etwas Unbekanntes, geht es nicht verloren
                      if ($ang_jetzt !== '' && !in_array($ang_jetzt, $ang_liste, true)) {
                          $ang_liste[] = $ang_jetzt;
                      }
                      foreach ($ang_liste as $opt): ?>
                        <option value="<?= $e($opt) ?>"<?= $opt === $ang_jetzt ? ' selected' : '' ?>><?= $e($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-name">Name</label>
                    <input type="text" id="<?= $rid ?>-b-name" name="b_name"
                           value="<?= $e((string)$bv('name', '')) ?>">
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-mail">E-Mail</label>
                    <input type="email" inputmode="email" autocapitalize="off" spellcheck="false"
                           id="<?= $rid ?>-b-mail" name="b_email"
                           value="<?= $e((string)$bv('email', '')) ?>">
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-tel">Telefon</label>
                    <input type="tel" inputmode="tel" id="<?= $rid ?>-b-tel" name="b_telefon"
                           value="<?= $e((string)$bv('telefon', '')) ?>">
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-anl">Anlass</label>
                    <select id="<?= $rid ?>-b-anl" name="b_anlass">
                      <?php
                      $anl_jetzt = (string)$bv('anlass', '');
                      $anl_liste = ANLAESSE;
                      if ($anl_jetzt !== '' && !in_array($anl_jetzt, $anl_liste, true)) {
                          $anl_liste[] = $anl_jetzt;
                      } ?>
                      <option value=""<?= $anl_jetzt === '' ? ' selected' : '' ?>>— keine Angabe —</option>
                      <?php foreach ($anl_liste as $opt): ?>
                        <option value="<?= $e($opt) ?>"<?= $opt === $anl_jetzt ? ' selected' : '' ?>><?= $e($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-anlt">Ergänzung zum Anlass</label>
                    <input type="text" id="<?= $rid ?>-b-anlt" name="b_anlass_text"
                           value="<?= $e((string)$bv('anlass_text', '')) ?>">
                  </div>

                  <div class="bf">
                    <label for="<?= $rid ?>-b-zeit">Wunschzeit</label>
                    <input type="text" id="<?= $rid ?>-b-zeit" name="b_wunschzeit"
                           value="<?= $e((string)$bv('wunschzeit', '')) ?>">
                    <span class="bf-klein">nur am Wochenende, sonst gelten die Öffnungszeiten</span>
                  </div>

                  <div class="bf weit">
                    <label for="<?= $rid ?>-b-msg">Nachricht</label>
                    <textarea id="<?= $rid ?>-b-msg" name="b_nachricht" rows="4"><?= $e((string)$bv('nachricht', '')) ?></textarea>
                  </div>

                </div>

                <p class="bearb-hinweis">
                  Termin steht auf <strong><?= $e(date('d.m.Y', strtotime((string)($a['datum'] ?? 'now')))) ?></strong>.
                  Das Datum änderst du mit <em>Verschieben</em>, den Status mit den Knöpfen darüber.
                </p>

                <div class="bearb-aktionen">
                  <button type="submit" class="gruen" name="bearbeiten"
                          value="<?= $e((string)($a['id'] ?? '')) ?>">Änderungen speichern</button>
                  <button type="button" onclick="bearbZu('<?= $rid ?>')">Abbrechen</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php
  /* ── BEWERTUNGEN ──────────────────────────────────────────
     Neue zuerst: das sind die, die auf eine Entscheidung warten.
     Nichts davon steht auf der Website, solange es nicht
     freigegeben wurde.                                       */
  $bw_neu  = [];
  $bw_frei = [];
  foreach (array_reverse($d['bewertungen']) as $b) {
      if (($b['status'] ?? 'neu') === 'frei') { $bw_frei[] = $b; } else { $bw_neu[] = $b; }
  }
  $sterne = fn(int $n) => str_repeat('★', max(0, min(5, $n))) . str_repeat('☆', 5 - max(0, min(5, $n)));
  ?>
  <div class="manuell">
    <h2>Bewertungen<?= $bw_neu ? ' — ' . count($bw_neu) . ' wartet' . (count($bw_neu) === 1 ? '' : 'en') . ' auf dich' : '' ?></h2>
    <p>
      Neue Bewertungen stehen <strong>nicht</strong> auf der Website. Sie erscheinen
      dort erst, wenn du sie hier freigibst.
    </p>

    <?php if (!$bw_neu && !$bw_frei): ?>
      <p style="color:#a8917a">Noch keine Bewertungen eingegangen.</p>
    <?php endif; ?>

    <?php foreach ([['Wartet auf Freigabe', $bw_neu, false], ['Steht auf der Website', $bw_frei, true]] as [$titel, $gruppe, $ist_frei]):
      if (!$gruppe) continue; ?>
      <h3 style="font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:#7a5230;margin:1.4rem 0 .6rem;">
        <?= $e($titel) ?> (<?= count($gruppe) ?>)
      </h3>
      <?php foreach ($gruppe as $b): ?>
        <div style="background:#fff;border-left:3px solid <?= $ist_frei ? '#4a7a3a' : '#a84c2a' ?>;padding:.8rem 1rem;margin-bottom:.6rem;">
          <div style="font-size:.85rem;">
            <strong><?= $e($b['name'] ?? '') ?></strong>
            <span style="color:#c8a24a;letter-spacing:.1em;"><?= $sterne((int)($b['sterne'] ?? 0)) ?></span>
            <span style="color:#a8917a;font-size:.75rem;">
              <?= $e(date('d.m.Y H:i', strtotime($b['erstellt'] ?? 'now'))) ?>
            </span>
          </div>
          <p style="font-size:.85rem;color:#5e4535;margin:.4rem 0 .6rem;white-space:pre-wrap;"><?= $e($b['text'] ?? '') ?></p>
          <form method="post" style="display:inline">
            <?php if ($ist_frei): ?>
              <button type="submit" name="bw_zurueck" value="<?= $e($b['id'] ?? '') ?>">Von der Website nehmen</button>
            <?php else: ?>
              <button type="submit" name="bw_frei" value="<?= $e($b['id'] ?? '') ?>" class="gruen">Freigeben</button>
            <?php endif; ?>
          </form>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Diese Bewertung endgültig löschen?')">
            <button type="submit" name="bw_loeschen" value="<?= $e($b['id'] ?? '') ?>">Löschen</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div class="manuell">
    <h2>Plätze von Hand blocken</h2>
    <p>
      Für Buchungen die nicht über die Website kamen — telefonisch, per E-Mail oder direkt im Laden.
      Diese Plätze zählen zusätzlich zu den Anfragen oben. Trage <strong>0</strong> ein, um einen Handeintrag zu entfernen.
    </p>
    <form method="post">
      <label style="display:block;margin-bottom:.3rem;font-size:.8rem;color:#7a5230;">Datum und Anzahl der Plätze</label>
      <input type="date" name="manuell_datum" required>
      <input type="number" name="manuell_anzahl" min="0" max="<?= MAX_PER_DAY ?>"
             value="<?= MAX_PER_DAY ?>" required style="width:90px">

      <div style="margin:.9rem 0 .2rem;">
        <label style="display:block;margin-bottom:.4rem;font-size:.8rem;color:#7a5230;">
          Wie soll der Tag auf der Website heißen, wenn kein Platz mehr frei ist?
        </label>
        <label style="display:inline-block;margin-right:1.2rem;font-size:.85rem;">
          <input type="radio" name="manuell_art" value="zu" checked> geschlossen
        </label>
        <label style="display:inline-block;margin-right:1.2rem;font-size:.85rem;">
          <input type="radio" name="manuell_art" value="voll"> ausgebucht
        </label>
      </div>
      <p style="font-size:.78rem;color:#a8917a;margin:.2rem 0 .9rem;line-height:1.6;">
        Das wirkt sich erst aus, wenn der Tag voll ist. Blockst du nur
        einzelne Plätze, bleibt der Tag normal buchbar.
      </p>

      <button type="submit">Speichern</button>
    </form>

    <?php
    /* Was gerade geblockt ist — sonst muss man raten, ob ein
       Eintrag noch steht und wie er nach aussen heisst. */
    $heute_str = date('Y-m-d');
    $offen = [];
    foreach ($d['manuell'] as $datum => $anzahl) {
        if ($datum >= $heute_str && (int)$anzahl > 0) { $offen[$datum] = (int)$anzahl; }
    }
    ksort($offen);
    if ($offen): ?>
      <h3 style="font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:#7a5230;margin:1.6rem 0 .6rem;">
        Aktuell geblockt (<?= count($offen) ?>)
      </h3>
      <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
        <?php foreach ($offen as $datum => $anzahl):
          $art = $d['manuell_art'][$datum] ?? '';
          /* Ob der Tag zu ist, entscheidet die GESAMTE Belegung —
             Handeintrag plus Anfragen. Ein Tag mit fuenf geblockten
             Plaetzen ist nicht zu, auch wenn beim Blocken
             "geschlossen" gewaehlt wurde. Das Wort greift erst,
             wenn kein Platz mehr frei ist.                        */
          $gesamt = belegt($d, $datum);
          $rest   = max(0, MAX_PER_DAY - $gesamt);
          $voll   = $rest <= 0;
          // Ohne Festlegung gilt die alte Regel: ganz geblockt = geschlossen
          $zeigt  = $art === 'voll' ? 'ausgebucht' : 'geschlossen';
          ?>
          <tr style="border-bottom:1px solid rgba(122,82,48,.12);">
            <td style="padding:.45rem .6rem .45rem 0;white-space:nowrap;">
              <strong><?= $e(date('d.m.Y', strtotime($datum))) ?></strong>
              <?php /* date('D') liefert Englisch — die Kuerzel stehen darum hier. */
              $kurz = ['So','Mo','Di','Mi','Do','Fr','Sa']; ?>
              <span style="color:#a8917a"><?= $kurz[(int)date('w', strtotime($datum))] ?></span>
            </td>
            <td style="padding:.45rem .6rem;white-space:nowrap;">
              <?= (int)$anzahl ?> von Hand<?= $gesamt > $anzahl ? ' · ' . ($gesamt - (int)$anzahl) . ' gebucht' : '' ?>
            </td>
            <td style="padding:.45rem .6rem;color:#5e4535;">
              <?php if ($voll): ?>
                zeigt: <strong><?= $e($zeigt) ?></strong>
              <?php else: ?>
                <span style="color:#a8917a">Tag bleibt buchbar — noch <?= $rest ?> frei</span>
              <?php endif; ?>
            </td>
            <td style="padding:.45rem 0;text-align:right;">
              <form method="post" style="display:inline">
                <input type="hidden" name="manuell_datum" value="<?= $e($datum) ?>">
                <input type="hidden" name="manuell_anzahl" value="0">
                <button type="submit">Freigeben</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
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

/* Aufklappen des Bearbeiten-Formulars */
function bearbAuf(id) {
  const z = document.getElementById(id + '-b');
  z.hidden = false;
  z.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  const f = document.getElementById(id + '-b-name');
  if (f) { f.focus(); }
}
function bearbZu(id) { document.getElementById(id + '-b').hidden = true; }

/* Wurde eine Aenderung abgelehnt, steht das Formular schon offen —
   dann dorthin springen, damit die Meldung nicht ins Leere geht. */
document.addEventListener('DOMContentLoaded', function () {
  const offen = document.querySelector('tr.bearbzeile:not([hidden])');
  if (offen) { offen.scrollIntoView({ block: 'center' }); }
});

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
