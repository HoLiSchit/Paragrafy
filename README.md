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
- **Vollwertige Webhooks mit Warteschlange, Retry & Delivery-Logs**:
  Benachrichtigt verbundene Web-Apps per `POST`-Webhook bei Live-Schaltungen (`legal_text.updated`) und Vorankündigungen (`legal_text.scheduled`, inkl. `preview_url`) mit vollständigen Feldern (`effective_date`, `url`, `api_url`, `status`, `was_scheduled`). Zustellung läuft asynchron über eine Warteschlange (per Cron `/api/cron/webhooks` abzuarbeiten) mit automatischem Retry (bis zu 5 Versuche, steigender Abstand) — ein langsamer Empfänger blockiert nie das Speichern. Ein integriertes Protokoll im Admin-Bereich zeigt HTTP-Statuscodes, Server-Antworten und Latenzen an. *(Siehe [WEBHOOKS.md](WEBHOOKS.md) für die Spezifikation).*
- **Öffentliche Vorschau geplanter Änderungen**:
  Unter `/{lang}/{slug}/preview` (und als JSON-API) ist eine geplante, noch nicht live geschaltete Fassung schon vor dem Stichtag einsehbar — z. B. um Nutzer:innen vorab über anstehende AGB-Änderungen zu informieren.
- **Automatische rollierende Backups (7 Tage)**:
  Ein Cron-Endpunkt (`/api/cron/backup`) sichert die Datenbank täglich und löscht ältere Stände automatisch. Die letzten Backups lassen sich einzeln in den Einstellungen herunterladen.
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
- **Backups & Exporte**:
  Lade in den Einstellungen eine Sicherungskopie der kompletten Datenbank oder alle veröffentlichten Rechtstexte als Textdateien (ZIP, nach Sprache/Slug sortiert) herunter.
- **Beliebig viele eigene Rechtstext-Typen**:
  Über die Pflichtseiten hinaus lassen sich projektübergreifend zusätzliche Dokumente anlegen (z. B. AGB B2B, Sponsoring-Vereinbarung, Lizenzbedingungen) und optional als Pflichtseite markieren.
- **Multi-User-Verwaltung mit E-Mail-Einladung**:
  Lade beliebig viele Personen per E-Mail ein; sie legen über einen Aktivierungslink ihr eigenes Passwort fest. Jede eingeladene Person hat vollen Zugriff auf das gesamte Admin-Panel — es gibt keine Rollen oder Rechte einzustellen. Ein "Passwort vergessen"-Link ermöglicht das eigenständige Zurücksetzen.
- **Änderungsprotokoll (Audit-Trail) mit CSV-Export**:
  Ein eigener Tab "Protokoll" zeigt, wer wann was geändert hat — Projekteinstellungen, Rechtstext-Typen, Veröffentlichungen und Benutzerverwaltung. Lässt sich als CSV-Datei herunterladen.
- **Versionshistorie mit Diff & Wiederherstellen**:
  Jede Veröffentlichung eines Rechtstexts legt eine neue Version an. Der Editor zeigt den vollständigen Versionsverlauf pro Sprache inklusive Diff-Ansicht gegen den aktuellen Stand und einer nicht-destruktiven "Wiederherstellen"-Funktion.
- **Sprachen-Tabs im Editor**:
  Aktive Sprachen erscheinen als Tabs; eine optionale Vergleichsansicht blendet die Referenzsprache bei Bedarf side-by-side ein.
- **Dunkelmodus**:
  Hell/Dunkel/Automatisch-Umschalter in den Einstellungen, pro Browser gespeichert — ohne Auswirkung auf andere Personen oder die öffentlichen Rechtstext-Seiten.
- **Login-Schutz**:
  Fehlgeschlagene Anmeldeversuche werden pro IP-Adresse gedrosselt (5 Versuche / 15 Minuten), um Brute-Force-Angriffe zu erschweren.

---

## 📁 Projektstruktur

```text
/var/www/paragrafy/
├── index.php             # Öffentlicher Router, Viewer, JSON-API & Cron-Handler
├── admin.php             # Admin-Dashboard, Compliance-Matrix, Webhook-Logs & Einstellungen
├── editor.php            # Sprachen-Tabs-Editor mit Scheduled Publishing & Versionshistorie
├── install.php           # Interaktiver Setup-Wizard für die Erstinstallation
├── db.php                # SQLite-Datenbankanbindung, Migrationen, Webhooks, SMTP-Client & Theme
├── Dockerfile            # Container-Image-Definition
├── docker-compose.yaml   # Docker-Compose-Setup für den Betrieb via Container
├── docker-entrypoint.sh  # Setzt beim Container-Start Dateirechte auf das Daten-Volume
├── WEBHOOKS.md           # Detaillierte Webhook-Dokumentation, Spezifikation & Payloads
├── paragrafy.svg         # Vektor-Logo
├── .htaccess             # Apache Routing & Schutz sensibler Dateien
├── .gitignore            # Git-Ausschlussregeln
├── config.php            # Admin-Passwort-Hash & Cron-Secret (wird bei Setup generiert)
├── .env.local            # Optional: DEEPL_API_KEY als Fallback
├── backups/              # Rollierende 7-Tage-Backups (automatisch angelegt)
└── paragrafy_data.sqlite # SQLite-Datenbank (wird automatisch angelegt)
```

Bei Docker liegen `config.php`, `.env.local`, `backups/` und `paragrafy_data.sqlite` stattdessen unter `PARAGRAFY_DATA_DIR` (`/var/www/html/data`, auf `./data` gemountet).

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

## 🚀 Docker Setup & Deployment

Paragrafy lässt sich am schnellsten und saubersten über Docker und Docker Compose betreiben.

### Voraussetzungen
* Docker & Docker Compose auf dem Server installiert.

### Schnellanleitung

1. Repository **komplett klonen** (nicht nur die Docker-Dateien!): `git clone https://github.com/mineco-de/Paragrafy.git && cd Paragrafy`. Das Image wird aus diesem lokalen Checkout gebaut (`COPY . /var/www/html/` in der Dockerfile) — es holt den Code nicht mehr selbst von GitHub, damit `docker compose up -d --build` zuverlässig deinen aktuellen Stand verwendet und nicht an einem alten Docker-Layer-Cache-Stand hängen bleibt.
2. Container im Hintergrund starten:
   ```bash
   docker compose up -d --build
   ```

**Persistenz:** `docker-compose.yaml` mountet `./data` nach `/var/www/html/data` und setzt `PARAGRAFY_DATA_DIR=/var/www/html/data` — dort liegen `paragrafy_data.sqlite`, `config.php`, `/backups` und ein optionales `.env.local`. Ohne dieses Volume gehen Datenbank und Admin-Zugangsdaten bei jedem `--build` verloren. Der Container setzt beim Start automatisch die richtigen Dateirechte auf diesen Ordner (per `docker-entrypoint.sh`), auch wenn das Host-Verzeichnis vorher nicht existierte.

Für einen Bare-Metal-/Apache-Betrieb (siehe unten) ist `PARAGRAFY_DATA_DIR` nicht nötig — dann liegen Datenbank und Config wie gewohnt direkt im Projektordner.

---

## 🔑 Erstinstallation & Cron-Jobs

Gilt unabhängig davon, ob Apache oder Docker verwendet wird.

### Erstinstallation

Rufe deine Subdomain im Browser auf (z. B. `https://legal.deinedomain.de`). Der **Paragrafy Setup-Wizard** startet automatisch und legt Datenbank, Admin-Passwort und ein zufälliges Cron-Secret an.

### Cron-Jobs einrichten (empfohlen)

Vier Endpunkte sollten von außen regelmäßig aufgerufen werden, damit geplante Veröffentlichungen live gehen, Backups entstehen und Webhooks zugestellt werden. Alle vier sind mit einem geheimen Schlüssel geschützt (Query-Parameter `?secret=...`), den du fertig zusammengesetzt in **Einstellungen → Automatisierung (Cron)** findest — dort lässt er sich bei Bedarf auch neu generieren.

```cron
# Geplante Veröffentlichungen live schalten (jede Minute, projektübergreifend)
* * * * * curl -fsS "https://legal.deinedomain.de/api/cron/publish?secret=DEIN_CRON_SECRET" > /dev/null

# Webhook-Warteschlange abarbeiten (alle 5 Minuten)
*/5 * * * * curl -fsS "https://legal.deinedomain.de/api/cron/webhooks?secret=DEIN_CRON_SECRET" > /dev/null

# Tägliches rollierendes Backup (7 Tage)
0 3 * * * curl -fsS "https://legal.deinedomain.de/api/cron/backup?secret=DEIN_CRON_SECRET" > /dev/null

# Prüfbericht per E-Mail, falls Rechtstexte überfällig sind (täglich)
0 8 * * * curl -fsS "https://legal.deinedomain.de/api/cron/audit?secret=DEIN_CRON_SECRET" > /dev/null
```

Alternativ eignet sich auch ein externer Uptime-Monitor (z. B. Uptime Kuma, healthchecks.io) als "Cron", der diese URLs im gewünschten Intervall abruft.

Ohne eingerichteten Cron ist Paragrafy dennoch nutzbar: Geplante Veröffentlichungen werden zusätzlich automatisch geprüft, sobald jemand die jeweilige Projekt-Domain besucht (Zero-Config-Fallback) — bei sehr wenig Traffic kann das aber verzögert live gehen. Backup und Webhook-Warteschlange lassen sich in den Einstellungen jederzeit manuell anstoßen; ein aufgerufener Endpunkt ohne oder mit falschem `secret` antwortet mit HTTP 403.

---

## ⚙️ Konfiguration & Umgebungsvariablen

Die meisten Einstellungen (SMTP-Zugangsdaten, Webhook-URL/-Secret, DeepL-API-Key, Firmendaten, Cookie-Banner-Text, Akzentfarbe etc.) sind **projektbezogen** und liegen in der SQLite-Datenbank — sie werden ausschließlich über die Einstellungen-Oberfläche im Admin-Bereich gepflegt, nicht über Umgebungsvariablen oder Config-Dateien.

Nur folgende Werte kommen tatsächlich aus Dateien statt aus der Datenbank:

| Datei / Variable | Zweck |
| :--- | :--- |
| `config.php` (auto-generiert) | Admin-Passwort-Hash (Legacy-Login) und das Cron-Secret. Wird beim Setup-Wizard angelegt, nicht manuell bearbeiten. |
| `.env` / `.env.local` (optional) | `DEEPL_API_KEY=...` als projektübergreifender Fallback, falls im jeweiligen Projekt kein eigener DeepL-Key hinterlegt ist. Beide Dateien sind optional — ohne sie funktioniert alles außer diesem Fallback. |
| `PARAGRAFY_DATA_DIR` (Umgebungsvariable) | Nur für Docker relevant: verlegt `config.php`, die SQLite-Datenbank, `/backups` und `.env.local` in ein persistentes Verzeichnis. Siehe Abschnitt „Docker Setup & Deployment" weiter oben. |

---

## 🔐 API-Zugriff & Authentifizierung

- **Öffentliche JSON-API** (`/api/:lang/:slug`, siehe unten) ist bewusst **unauthentifiziert und rein lesend** — Rechtstexte sollen von jeder verbundenen Website ohne Zugangsdaten abrufbar sein. Es gibt keine Möglichkeit, Inhalte über diese API zu schreiben oder zu ändern.
- **Bearbeiten von Rechtstexten** ist ausschließlich über die eingeloggte `/admin`-Session möglich (Passwort- bzw. Multi-User-Login) — es existiert keine separate API mit Bearer-Token oder API-Keys für schreibende Zugriffe.
- **Cron-Endpunkte** (`/api/cron/...`) erfordern das oben beschriebene `?secret=`-Query-Parameter (oder eine aktive Admin-Session) und lösen Server-Aktionen aus (Backup, Webhook-Versand, Live-Schaltung, Audit-Mail) — sie geben aber keine Inhalte oder Zugangsdaten preis.

---

## ⬆️ Upgrade-Guide

Ein Update ist unkompliziert, da Schema-Änderungen automatisch beim ersten Request nach dem Update laufen:

1. **Vor dem Update:** Sicherung erstellen (Einstellungen → Sicherung & Export, oder `/backups` bei Docker sichern).
2. **Apache:** Neue Dateien über die alten kopieren bzw. `git pull` — `config.php`, `paragrafy_data.sqlite` und `/backups` dabei **nicht** überschreiben/löschen.
   **Docker:** Zuerst im lokalen Checkout `git pull`, dann `docker compose up -d --build` — das Image wird aus dem lokalen Code gebaut, ein reines `--build` ohne vorheriges `git pull` verwendet weiterhin den alten Stand. `config.php`, `paragrafy_data.sqlite`, `/backups` und `.env.local` bleiben durch das `data`-Volume automatisch erhalten.
3. Beim nächsten Aufruf einer beliebigen Seite legt `ensure_schema_migrations()` fehlende Tabellen und Spalten automatisch an (z. B. `users`, `audit_log`, `translation_versions`, `webhook_queue`, neue Spalten in `projects`) — kein manuelles Migrationsskript nötig.
4. Bestehende Installationen ohne `cron_secret` in `config.php` bekommen beim ersten Aufruf eines `/api/cron/...`-Endpunkts automatisch eines generiert (sichtbar unter Einstellungen → Automatisierung).

Es gab bislang keine Breaking Changes, die manuelles Eingreifen über die automatische Migration hinaus erfordern.

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
