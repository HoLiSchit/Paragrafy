# § Paragrafy - Multi-Project Legal CMS & Compliance Engine

Paragrafy ist ein leichtgewichtiges, selbstgehostetes Content-Management-System und Headless-Backend zur zentralen Verwaltung, Übersetzung und Bereitstellung rechtlicher Pflichttexte (Impressum, Datenschutz, AGB, Cookie-Richtlinien etc.) für mehrere Web- und App-Projekte auf Basis von **PHP** und **SQLite**.

---

## 🚀 Kernfunktionen

- **Multi-Tenant / Multi-Domain Routing**:
  Erkennt die aufrufende Subdomain (`legal.deinedomain.de`, `legal.projekt-b.de`) automatisch und liefert die passenden Rechtstexte, Farben und Firmen-Stammdaten des jeweiligen Projekts aus.
- **Compliance-Matrix mit One-Click Toggle & Copy-URL**:
  Das Admin-Dashboard visualisiert auf einen Blick, welche Pflichttexte in welchen Sprachen (`DE`, `EN`, `ES`, `FR` etc.) vorhanden, als Entwurf gespeichert oder noch unvollständig sind. Mit Instant-Toggles und 1-Klick-Kopierbuttons für alle Sprach-URLs.
- **Zeitgesteuerte Veröffentlichung (Scheduled Publishing)**:
  Änderungen an AGB oder Datenschutzerklärungen können im Voraus mit einem Stichtag (z. B. `31.08. 00:00`) geplant werden. Die bestehende Fassung bleibt bis zum Stichtag live und wird zum Zielzeitpunkt automatisch abgelöst.
- **Echtzeit-Webhooks mit HMAC-Signatur**:
  Benachrichtigt verbundene Web-Apps per `POST`-Webhook bei Live-Schaltungen (`legal_text.updated`) und Vorankündigungen (`legal_text.scheduled`). *(Siehe [WEBHOOKS.md](WEBHOOKS.md) für die vollständige Spezifikation).*
- **Vollwertiger WYSIWYG & Code-Editor**:
  Visuelle Formatierungsleiste (H2, H3, Fett, Kursiv, Listen, Links) mit 1-Klick-Umschaltung zum reinen HTML-Quellcode.
- **Bidirektionale DeepL-Übersetzung**:
  Übersetze jeden beliebigen Quelltext mit geschützten Platzhaltern (z. B. `DE → EN`, `EN → DE`, `ES → DE`) direkt im Side-by-Side-Editor.
- **Automatische Versions-Synchronisation (Hash-Diff)**:
  Wird ein Quelltext inhaltlich geändert, markiert Paragrafy alle anderen Sprachfassungen automatisch als `⚠ Veraltet`. Ein integrierter Diff-Viewer hebt Änderungen optisch hervor (Grün = Neu, Rot = Gelöscht).
- **Audit- & Fristenkontrolle mit SMTP-E-Mail**:
  Warnt bei Texten, die länger als das konfigurierte Intervall (z. B. 12 Monate) nicht überprüft wurden, und sendet auf Wunsch Prüfberichte per E-Mail.
- **Headless JSON-API & In-App Embed Drawer**:
  Stellt Endpunkte unter `/api/:lang/:slug` bereit und liefert ein modales Sheet-Script (`/embed.js`) für das direkte Einbinden in Web-Apps.
- **DSGVO Cookie-Consent-Banner**:
  Integriertes, leichtgewichtiges Consent-Skript (`/consent.js`) ohne externe Abhängigkeiten.
- **Notion/Stripe-Style Public Viewer**:
  Mitscrollendes Inhaltsverzeichnis (Sticky TOC mit Scroll-Spy), Lesezeit-Berechnung, Direktlink-Anker (`#`) und Live-Textfilter in allen Zielsprachen.

---

## 📁 Projektstruktur

```text
/var/www/paragrafy/
├── index.php             # Öffentlicher Router, Viewer, JSON-API & Cron-Handler
├── admin.php             # Admin-Dashboard, Compliance-Matrix & Einstellungen
├── editor.php            # Side-by-Side WYSIWYG- & Übersetzungs-Editor
├── install.php           # Interaktiver Setup-Wizard für die Erstinstallation
├── db.php                # SQLite-Datenbankanbindung, Migrationen & SMTP-Client
├── WEBHOOKS.md           # Detaillierte Webhook-Dokumentation & Payloads
├── paragrafy.svg         # Vektor-Logo
├── .htaccess             # Apache Routing & Schutz sensibler Dateien
├── .gitignore            # Git-Ausschlussregeln
├── config.php            # Admin-Zugangsdaten (wird bei Setup generiert)
└── paragrafy_data.sqlite # SQLite-Datenbank (wird automatisch angelegt)
```

---

## 🛠️ Installation & Webserver-Setup

### 1. Dateien hochladen & Berechtigungen setzen

```bash
sudo chown -R www-data:www-data /var/www/paragrafy
sudo find /var/www/paragrafy -type d -exec chmod 755 {} +
sudo find /var/www/paragrafy -type f -exec chmod 644 {} +
```

### 2. Apache VirtualHost Konfiguration

```apache
<VirtualHost *:80>
    ServerName legal.deinedomain.de
    ServerAlias legal.projekt-b.de
    DocumentRoot /var/www/paragrafy

    <Directory /var/www/paragrafy>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/paragrafy_error.log
    CustomLog ${APACHE_LOG_DIR}/paragrafy_access.log combined
</VirtualHost>
```

### 3. Erstinstallation

Rufe deine Subdomain im Browser auf (z. B. `https://legal.deinedomain.de`). Der **Paragrafy Setup-Wizard** startet automatisch.

---

## 🔧 Webhooks & Integrationen

### Webhooks

Paragrafy unterstützt zwei Event-Typen für automatisierte Workflows in deinen Frontends:
- `legal_text.scheduled`: Vorankündigung einer geplanten Änderung (inkl. Stichtag).
- `legal_text.updated`: Live-Schaltung eines Dokuments (sofort oder am Stichtag).

Ausführliche Payloads, HMAC-Signaturprüfungen und Code-Beispiele findest du in der Datei **[WEBHOOKS.md](WEBHOOKS.md)**.

### Headless JSON-API

```http
GET https://legal.deinedomain.de/api/de/datenschutz
GET https://legal.deinedomain.de/api/agb-b2c
```

### In-App Embed-Drawer (`/embed.js`)

```html
<script src="https://legal.deinedomain.de/embed.js"></script>
<button data-paragrafy-slug="datenschutz" data-paragrafy-lang="de">Datenschutz anzeigen</button>
```

### DSGVO Cookie-Consent-Banner (`/consent.js`)

```html
<script src="https://legal.deinedomain.de/consent.js"></script>
```

---

## 📄 Lizenz

Paragrafy ist Open-Source-Software und steht für eigene und kommerzielle Webprojekte zur Verfügung.
