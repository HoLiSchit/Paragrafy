# Changelog

Alle nennenswerten Änderungen an Paragrafy werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/).
Die Versionsnummer folgt **CalVer** (`JAHR.MONAT.BUILD`) statt SemVer:
`BUILD` zählt die Releases innerhalb eines Kalendermonats hoch und startet
jeden Monat wieder bei `1`. Änderungen vor `2026.9.1` sind nicht rückwirkend
erfasst — siehe dafür die Git-Historie.

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
