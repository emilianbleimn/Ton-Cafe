# Benzel KFZ – Website für ein KFZ-Sachverständigenbüro

Moderne, voll responsive Website für ein KFZ-Unternehmen, das Fahrzeuge nach Schäden
kontrolliert und Gutachten erstellt (Unfallschaden, Fahrzeugbewertung, Gebrauchtwagen-Check,
Oldtimer-Gutachten u. v. m.).

Reines **HTML / CSS / JavaScript** – kein Build-Schritt, keine Abhängigkeiten. Einfach zu
hosten (GitHub Pages, Netlify, Vercel, jeder Webspace) und leicht anzupassen.

## 📂 Projektstruktur

```
.
├── index.html          # Startseite (Hero, Leistungen, Ablauf, Über uns, Bewertungen, FAQ, Kontakt)
├── impressum.html      # Impressum (Pflichtangaben – Platzhalter ausfüllen!)
├── datenschutz.html    # Datenschutzerklärung (Mustervorlage – anpassen!)
├── css/
│   └── style.css       # komplettes Design-System & Responsive-Layout
└── js/
    └── main.js         # Navigation, Scroll-Animationen, Zähler, FAQ, Formular
```

## ✨ Features

- Klebrige Navigation mit mobilem Burger-Menü
- Animierter Hero-Bereich mit SVG-Illustration (funktioniert auch offline)
- Leistungs-Karten, Ablauf in 4 Schritten, Vorteile, Bewertungen
- Animierte Kennzahlen (Counter) beim Scrollen
- FAQ-Akkordeon
- Kontaktformular mit Validierung (öffnet vorausgefüllte E-Mail)
- „Nach oben“-Button, Scroll-Reveal-Animationen
- Barrierearm (Skip-Link, ARIA-Labels) & `prefers-reduced-motion`-freundlich
- SEO-/Open-Graph-Meta-Tags, Inline-SVG-Favicon

## 🔧 Vor dem Livegang anpassen

Suchen & ersetzen Sie die folgenden Platzhalter (z. B. per Editor-Suche):

| Platzhalter | Bedeutung |
|---|---|
| `Benzel KFZ` | Firmenname / Markenname |
| `030 123 45 67` und `+49301234567` | Telefonnummer (Anzeige bzw. `tel:`-Links) |
| `info@benzel-kfz.de` | E-Mail-Adresse |
| `Musterstraße 12, 12345 Musterstadt` | Anschrift |
| `15+ / 8.000+ / 4,9` (in `index.html`) | echte Kennzahlen |
| Texte in `impressum.html` / `datenschutz.html` | **rechtlich verpflichtend** ausfüllen |

> ⚠️ **Wichtig:** Impressum und Datenschutzerklärung enthalten Platzhalter und ersetzen
> keine Rechtsberatung. Bitte vor Veröffentlichung mit echten Daten füllen und ggf. prüfen lassen.

### Eigene Fotos einbinden (optional)
Die Seite nutzt bewusst SVG-Illustrationen, damit sie überall zuverlässig aussieht.
Möchten Sie echte Fotos verwenden, legen Sie sie in einen Ordner `assets/` und ersetzen
z. B. die Hero-Illustration oder hinterlegen ein Hintergrundbild in `.hero` (CSS).

### Kontaktformular „echt“ versenden
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

Repository-Einstellungen → **Pages** → Branch auswählen → speichern.
Die Seite ist anschließend unter der angezeigten URL erreichbar.
