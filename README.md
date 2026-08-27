# § Paragrafy - Multi-Project Legal CMS & Compliance Engine

Paragrafy ist ein leichtgewichtiges, selbstgehostetes Content-Management-System und Headless-Backend zur zentralen Verwaltung, Übersetzung und Bereitstellung rechtlicher Pflichttexte (Impressum, Datenschutz, AGB, Cookie-Richtlinien etc.) für mehrere Web- und App-Projekte auf Basis von **PHP** und **SQLite**.

---

## 🚀 Kernfunktionen

- **Multi-Tenant / Multi-Domain Routing**:
  Erkennt die aufrufende Subdomain (`legal.deinedomain.de`, `legal.projekt-b.de`) automatisch und liefert die passenden Rechtstexte, Farben und Firmen-Stammdaten des jeweiligen Projekts aus.
- **Compliance-Matrix mit One-Click Toggle**:
  Das Admin-Dashboard visualisiert auf einen Blick, welche Pflichttexte in welchen Sprachen (`DE`, `EN`, `ES`, `FR` etc.) vorhanden, als Entwurf gespeichert oder noch unvollständig sind. Ein Klick auf den Status schaltet sofort zwischen `PFLICHTSEITE` und `Optional` um.
- **Vollwertiger WYSIWYG & Code-Editor**:
  Visuelle Formatierungsleiste (H2, H3, Fett, Kursiv, Listen, Links) mit 1-Klick-Umschaltung zum reinen HTML-Quellcode.
- **Bidirektionale DeepL-Übersetzung**:
  Übersetze jeden beliebigen Quelltext mit geschützten Platzhaltern (z. B. `DE → EN`, `EN → DE`, `ES → DE`) direkt im Side-by-Side-Editor.
- **Automatische Versions-Synchronisation (Hash-Diff)**:
  Wird ein Quelltext inhaltlich geändert, markiert Paragrafy alle anderen Sprachfassungen automatisch als `⚠ Veraltet`. Ein integrierter Diff-Viewer hebt Änderungen optisch hervor (Grün = Neu, Rot = Gelöscht).
- **Audit- & Fristenkontrolle mit SMTP-E-Mail**:
  Warnt bei Texten, die länger als das konfigurierte Intervall (z. B. 12 Monate) nicht überprüft wurden, und sendet auf Wunsch Prüfberichte per E-Mail.
- **Echtzeit-Webhooks**:
  Benachrichtigt verbundene Web-Apps per `POST`-Webhook inklusive HMAC-SHA256-Signatur, sobald Rechtstexte aktualisiert oder neu veröffentlicht werden.
- **Headless JSON-API & In-App Embed Drawer**:
  Stellt Endpunkte unter `/api/:lang/:slug` bereit und liefert ein modales Sheet-Script (`/embed.js`) für das direkte Einbinden in Web-Apps.
- **DSGVO Cookie-Consent-Banner**:
  Integriertes, leichtgewichtiges Consent-Skript (`/consent.js`) ohne externe Abhängigkeiten.
- **Notion/Stripe-Style Public Viewer**:
  Mitscrollendes Inhaltsverzeichnis (Sticky TOC mit Scroll-Spy), Lesezeit-Berechnung, Direktlink-Anker (`#`) und Live-Textfilter.

---

## 📁 Projektstruktur

```text
/var/www/paragrafy/
├── index.php             # Öffentlicher Router, Viewer, JSON-API & Cron-Handler
├── admin.php             # Admin-Dashboard, Compliance-Matrix & Einstellungen
├── editor.php            # Side-by-Side WYSIWYG- & Übersetzungs-Editor
├── install.php           # Interaktiver Setup-Wizard für die Erstinstallation
├── db.php                # SQLite-Datenbankanbindung, Migrationen & SMTP-Client
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
# Verzeichnis erstellen & Berechtigungen für Webserver (Apache / PHP-FPM) setzen
sudo chown -R www-data:www-data /var/www/paragrafy
sudo find /var/www/paragrafy -type d -exec chmod 755 {} +
sudo find /var/www/paragrafy -type f -exec chmod 644 {} +
```

### 2. Apache VirtualHost Konfiguration

Leite alle gewünschten Legal-Domains auf denselben DocumentRoot:

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

Rufe deine Subdomain im Browser auf (z. B. `https://legal.deinedomain.de`). Der **Paragrafy Setup-Wizard** startet automatisch und führt durch die Konfiguration von Admin-Passwort, Sprachen, Pflichttexten und Stammdaten.

---

## 🔧 Konfiguration & Integration

### Platzhalter (Variablen in Texten)

In allen Rechtstexten können dynamische Platzhalter verwendet werden. Diese werden beim Aufruf automatisch durch die in den Projekt-Einstellungen hinterlegten Stammdaten ersetzt:

- `{{company_name}}`: Firmenname / Inhaber
- `{{representative}}`: Vertretungsberechtigte Personen
- `{{address}}`: Anschrift
- `{{email}}`: Kontakt-E-Mail
- `{{phone}}`: Telefonnummer
- `{{register_info}}`: Registergericht & Registernummer
- `{{year}}`: Aktuelles Kalenderjahr

### Headless JSON-API

Rufe Rechtstexte im JSON-Format für Mobile-Apps, Modals oder Backend-Checks ab:

```http
GET https://legal.deinedomain.de/api/de/datenschutz
GET https://legal.deinedomain.de/api/agb-b2c
```

**JSON-Response:**
```json
{
  "project": "DemoProjekt",
  "title": "Datenschutzerklärung",
  "slug": "datenschutz",
  "lang": "de",
  "updated_at": "2026-08-27T11:00:00+02:00",
  "change_note": "Zahlungsdienstleister aktualisiert",
  "html": "<h2>1. Datenschutz auf einen Blick</h2>..."
}
```

### In-App Embed-Drawer (`/embed.js`)

Binde Rechtstexte als Slide-Over-Sheet direkt in deine Webanwendungen ein:

```html
<!-- Script im Head oder Footer laden -->
<script src="https://legal.deinedomain.de/embed.js"></script>

<!-- Button zum Öffnen des Drawers -->
<button data-paragrafy-slug="datenschutz" data-paragrafy-lang="de">Datenschutz anzeigen</button>
<button data-paragrafy-slug="agb-b2c" data-paragrafy-lang="en">Terms (EN)</button>
```

### DSGVO Cookie-Consent-Banner (`/consent.js`)

```html
<script src="https://legal.deinedomain.de/consent.js"></script>
```

### Webhook-Anbindung

Bei Veröffentlichung eines Dokuments sendet Paragrafy einen `POST`-Request an deine Webhook-URL mit dem Header `X-Paragrafy-Signature` (SHA256-HMAC):

```json
{
  "event": "legal_text.updated",
  "timestamp": "2026-08-27T11:00:00+02:00",
  "project": {
    "id": 1,
    "name": "MeinProjekt",
    "domain": "legal.deinedomain.de"
  },
  "data": {
    "document_id": 3,
    "slug": "agb-b2c",
    "lang": "de",
    "title": "AGB (Endkunden / B2C)",
    "status": "published",
    "change_note": "Zahlungsanbieter ergänzt",
    "updated_at": "2026-08-27T11:00:00+02:00"
  }
}
```

### Automatisierter Audit-Cronjob

```bash
# Täglicher Check um 08:00 Uhr via Crontab
0 8 * * * curl -s https://legal.deinedomain.de/api/cron/audit > /dev/null
```

---

## 🔒 Sicherheit & Backups

- **Datenbankschutz**: Direkte Zugriffe auf `.sqlite`, `.env` und `.config` über den Webserver werden durch die `.htaccess` blockiert.
- **1-Klick-Backup**: Über das Admin-Dashboard unter **Backups & Exporte** kann die aktuelle Datenbankdatei jederzeit heruntergeladen werden.

---

## 📄 Lizenz

Paragrafy ist Open-Source-Software und steht für eigene und kommerzielle Webprojekte zur Verfügung.
