# Changelog

Alle nennenswerten Änderungen an Paragrafy werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).
Die Versionsnummer folgt **CalVer** (`JAHR.MONAT.BUILD`) statt SemVer:
`BUILD` zählt die Releases innerhalb eines Kalendermonats hoch und startet
jeden Monat wieder bei `1`. Änderungen vor `2026.9.1` sind nicht rückwirkend
erfasst — siehe dafür die Git-Historie.

## [2026.9.4] - 2026-09-04

### Added
- **Einlesemodus für Rechtstexte (BETA)**: Im Editor der 6 Standard-Rechtstexte (Impressum,
  Datenschutzerklärung, AGB B2C/B2B, Cookie-Richtlinie, Widerrufsbelehrung) steht jetzt ein
  neuer Button „Alten Text einlesen (BETA)" zur Verfügung. Er liest eine bestehende
  Rechtstext-Seite per URL oder Datei-Upload (`.html`, `.htm`, `.txt`, `.pdf`) ein, lässt sie
  von einem KI-Provider (Anthropic Claude oder OpenAI, konfigurierbar in den
  Projekteinstellungen) in die passende Zielstruktur überführen und schlägt dabei sowohl den
  generierten Inhalt (mit korrekt gesetzten `{{platzhaltern}}`) als auch die im Text erkannten
  Firmendaten (Name, Adresse, E-Mail, Telefon, Vertretung, Registereintrag) zur Bestätigung vor.
  Nichts wird automatisch übernommen — weder der Text noch die Firmendaten landen ohne
  ausdrückliche Bestätigung im System. URL-Abrufe sind gegen SSRF abgesichert (keine
  privaten/lokalen Adressen), Uploads sind auf zulässige Dateitypen und eine Maximalgröße
  begrenzt. Neue Projekteinstellung „KI-Einlesemodus" für Provider-Auswahl und API-Key,
  analog zum bestehenden DeepL-Key-Pattern inkl. `.env.local`-Fallback.

## [2026.9.3] - 2026-09-04

### Changed
- **Admin-Dashboard & Editor auf das "§ — Ink & Paper, quiet"-Design umgestellt**: neue Farbpalette
  (warmes Ink/Paper statt Indigo, sowohl Dark- als auch Light-Variante), self-hosted Fraunces/
  Inter/JetBrains-Mono-Fonts statt Google-Fonts-CDN, durchgängig eckige 3px/2px-Radien statt der
  bisherigen 7–20px-Rundungen, eckige Badges (Rand statt Füllfarbe, kein Farbpunkt) statt voller
  Pillen, wachsender Unterstrich-Hover in der Sidebar-Navigation, sichtbarer Fokusring. Die vom
  Kunden gewählte Akzentfarbe (`brand_color`) bleibt unverändert der Primärakzent; Button-Text
  wird jetzt serverseitig per Kontrastberechnung (WCAG-Luminanz) automatisch hell oder dunkel
  gewählt, damit auch sehr helle oder sehr dunkle Kundenfarben lesbar bleiben. Der bestehende
  Hell/Dunkel/Automatisch-Umschalter bleibt unverändert erhalten. Das öffentlich eingebettete
  Consent-Banner (`index.php`) ist von dieser Umstellung bewusst nicht betroffen.
- Neues Logo (`paragrafy.svg`).

## [2026.9.2] - 2026-09-04

### Added
- **Consent-Nachweise (DSGVO-Nachweispflicht) für `/consent.js`**: Optionales, projektweit
  aktivierbares serverseitiges Protokoll jeder Consent-Entscheidung (akzeptiert/abgelehnt),
  gedacht als Nachweis bei Prüfungen. Gespeichert werden Zeitpunkt, Aktion, eine anonymisierte
  IP-Adresse (letztes Oktett bzw. bei IPv6 die letzten 80 Bit genullt — nie die vollständige
  IP), der Browser (User-Agent), eine Consent-ID sowie ein Hash des zum Zeitpunkt der
  Einwilligung angezeigten Banner-Texts. Neue Admin-Seite „Consent-Nachweise" mit CSV-Export,
  neuer Endpunkt `/api/consent-log`, konfigurierbare Aufbewahrungsdauer (Einstellungen →
  Consent-Nachweise) — abgelaufene Einträge werden automatisch zusammen mit dem täglichen
  rollierenden Backup bereinigt.

## [2026.9.1] - 2026-09-02

### Changed
- Versionsschema von SemVer (`1.6.2`) auf CalVer (`JAHR.MONAT.BUILD`)
  umgestellt. Einzige Quelle bleibt `PARAGRAFY_VERSION` in `db.php`.
- Veraltete, hartcodierte Versionsnummern in Datei-Kopfkommentaren
  (`admin.php`, `editor.php`, `index.php`, `install.php`) entfernt, die
  teils erheblich von der tatsächlich ausgelieferten Version abwichen.

### Added
- Dieses Changelog.
- Warnung vor Datenverlust im Editor: Beim Verlassen der Seite mit
  ungespeicherten Änderungen erscheint jetzt eine Bestätigungsabfrage,
  zusätzlich zeigt ein Hinweis neben dem Speichern-Button den
  ungespeicherten Zustand an (`editor.php`).
