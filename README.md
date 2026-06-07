# Benzel KFZ – Website für ein KFZ-Sachverständigenbüro

Moderne, voll responsive Website für ein KFZ-Unternehmen, das Fahrzeuge nach Schäden
kontrolliert und Gutachten erstellt (Unfallschaden, Fahrzeugbewertung, Gebrauchtwagen-Check,
Oldtimer-Gutachten u. v. m.).

Reines **HTML / CSS / JavaScript** – kein Build-Schritt, keine Abhängigkeiten. Einfach zu
hosten (GitHub Pages, Netlify, Vercel, jeder Webspace) und leicht anzupassen.

## 📂 Projektstruktur

```
.
├── index.html          # Startseite (Hero, Leistungen, Ablauf, Soforthilfe, Über uns, FAQ, Kontakt)
├── impressum.html      # Impressum (Pflichtangaben – Platzhalter ausfüllen!)
├── datenschutz.html    # Datenschutzerklärung (Mustervorlage – anpassen!)
├── 404.html            # Fehlerseite (z. B. für GitHub Pages)
├── robots.txt          # Suchmaschinen-Hinweise
├── sitemap.xml         # Sitemap für Suchmaschinen
├── css/
│   └── style.css       # komplettes Design-System & Responsive-Layout
├── js/
│   └── main.js         # Navigation, Scroll-Animationen, Zähler, FAQ, Formular
└── .github/workflows/
    └── deploy-pages.yml # automatisches Deployment auf GitHub Pages
```

## ✨ Features

- Klebrige Navigation mit mobilem Burger-Menü
- Animierter Hero-Bereich mit SVG-Illustration (funktioniert auch offline)
- Leistungs-Karten, Ablauf in 4 Schritten, **Soforthilfe nach Unfall**, Vorteile, Bewertungen
- Animierte Kennzahlen (Counter) beim Scrollen
- FAQ-Akkordeon
- Kontaktformular mit Validierung (öffnet vorausgefüllte E-Mail)
- „Nach oben"-Button, Scroll-Reveal-Animationen
- Barrierearm (Skip-Link, ARIA-Labels) & `prefers-reduced-motion`-freundlich
- **Lokale SEO:** strukturierte Daten (JSON-LD `AutomotiveBusiness`), `robots.txt`, `sitemap.xml`, Open-Graph-Tags
- Inline-SVG-Favicon, eigene 404-Seite

## 🔧 Vor dem Livegang anpassen

Suchen & ersetzen Sie die folgenden Platzhalter (z. B. per Editor-Suche):

| Platzhalter | Bedeutung |
|---|---|
| `030 123 45 67` und `+49301234567` | Telefonnummer (Anzeige bzw. `tel:`-Links) |
| `info@benzel-kfz.de` | E-Mail-Adresse |
| `Musterstraße 12, 12345 Musterstadt` | Anschrift |
| `benzel-kfz.de` | Domain (in Canonical, Sitemap, robots.txt, JSON-LD) |
| `15+ / 8.000+ / 4,9` (in `index.html`) | echte Kennzahlen |
| JSON-LD-Block im `<head>` der `index.html` | Adresse, Telefon, Öffnungszeiten |
| Texte in `impressum.html` / `datenschutz.html` | **rechtlich verpflichtend** ausfüllen |

> ⚠️ **Wichtig:** Impressum und Datenschutzerklärung enthalten Platzhalter und ersetzen
> keine Rechtsberatung. Bitte vor Veröffentlichung mit echten Daten füllen und ggf. prüfen lassen.

### 🔒 Hinweis Datenschutz (Google Fonts)
Die Schriften werden aktuell von Google Fonts geladen. In Deutschland ist es datenschutz­freundlicher,
die Schriften **lokal einzubinden** (selbst hosten) oder auf eine System-Schriftart umzustellen.
Die CSS-Variablen enthalten bereits System-Fallbacks (`--font-body`, `--font-head`).

### Eigene Fotos einbinden (optional)
Die Seite nutzt bewusst SVG-Illustrationen, damit sie überall zuverlässig aussieht.
Möchten Sie echte Fotos verwenden, legen Sie sie in einen Ordner `assets/` und ersetzen
z. B. die Hero-Illustration oder hinterlegen ein Hintergrundbild in `.hero` (CSS).

### Kontaktformular „echt" versenden
Aktuell öffnet das Formular das E-Mail-Programm der Besucher mit vorausgefüllter Nachricht
(kein Server nötig). Für automatischen Versand können Sie einen Dienst anbinden, z. B.:

- **Formspree** – `action="https://formspree.io/f/DEINE_ID"` und `method="post"` im `<form>`
- **Netlify Forms** – Attribut `data-netlify="true"` ergänzen (bei Hosting auf Netlify)

Den entsprechenden Hinweis finden Sie im Code in `js/main.js`.

## 🚀 Lokal ansehen

Einfach `index.html` im Browser öffnen – oder ein kleiner lokaler Server:

```bash
python3 -m http.server 8000
# danach im Browser: http://localhost:8000
```

## 🌐 Veröffentlichen (GitHub Pages)

**Variante A – automatisch (empfohlen):** Der enthaltene Workflow `.github/workflows/deploy-pages.yml`
veröffentlicht die Seite bei jedem Push auf `main`. Einmalig in den Repo-Einstellungen unter
**Settings → Pages → Build and deployment → Source** „**GitHub Actions**" auswählen.

**Variante B – ohne Workflow:** **Settings → Pages → Deploy from a branch** → Branch `main`,
Ordner `/ (root)` → **Save**. Nach wenigen Minuten ist die Seite live.
