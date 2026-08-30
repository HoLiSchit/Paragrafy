# § Paragrafy Webhook Referenz & Spezifikation

Dieses Dokument beschreibt die Webhook-Schnittstelle von Paragrafy (v1.6.2) zur automatisierten Synchronisation von Rechtstexten (AGB, Datenschutzerklärung, Impressum etc.) mit angebundenen Web- und Mobile-Anwendungen.

---

## 1. HTTP-Header & Authentifizierung

Jeder von Paragrafy versendete Webhook wird als `POST`-Request mit folgendem Header-Schema übermittelt:

| Header | Beschreibung | Beispiel |
| :--- | :--- | :--- |
| `Content-Type` | MIME-Type des Payloads | `application/json` |
| `User-Agent` | Client-Identifikator | `Paragrafy-Webhook/1.6.2` |
| `X-Paragrafy-Event` | Event-Typ | `legal_text.updated` / `legal_text.scheduled` |
| `X-Paragrafy-Signature` | HMAC-SHA256 Signatur des rohen Body-Strings | `a3f8e... (hex)` *(nur wenn Secret gesetzt)* |

---

## 2. Event-Typen im Überblick

| Event | Auslöser | Einsatzzweck in deiner App |
| :--- | :--- | :--- |
| `legal_text.updated` | Ein Rechtstext wurde sofort live veröffentlicht oder ein geplanter Stichtag wurde erreicht. | Neue AGB-Zustimmung im User-Account erzwingen, App-Cache invalidieren. |
| `legal_text.scheduled` | Eine Textänderung wurde für einen zukünftigen Zeitpunkt geplant. | Vorankündigungs-Banner für Nutzer anzeigen (*„AGB ändern sich zum 31.08.“*). |

---

## 3. Payload-Spezifikation

### A. Event: `legal_text.updated` (Live-Veröffentlichung)

Wird gefeuert, sobald ein Rechtstext aktiv geschaltet wurde (sofort oder nach Ablauf eines Stichtags).

#### JSON-Payload:
```json
{
  "event": "legal_text.updated",
  "timestamp": "2026-08-30T15:45:00+02:00",
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
    "change_note": "Aktualisierung der Zahlungsbedingungen zum Monatsende",
    "was_scheduled": false,
    "effective_date": "2026-08-30T15:45:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "updated_at": "2026-08-30T15:45:00+02:00"
  }
}
```

---

### B. Event: `legal_text.scheduled` (Vorankündigung für Stichtag)

Wird gefeuert, wenn im Editor eine zeitgesteuerte Live-Schaltung für die Zukunft geplant wird.

#### JSON-Payload:
```json
{
  "event": "legal_text.scheduled",
  "timestamp": "2026-08-30T15:30:00+02:00",
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
    "status": "scheduled",
    "change_note": "Inkrafttreten neuer Zahlungsdienstleister zum 31.08.2026",
    "scheduled_at": "2026-08-31T00:00:00+02:00",
    "effective_date": "2026-08-31T00:00:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "was_scheduled": false
  }
}
```

---

## 4. Felddefinitionen (Daten-Mapping)

### Root-Objekt

| Feld | Typ | Beschreibung |
| :--- | :--- | :--- |
| `event` | `string` | Der Name des Ereignisses (`legal_text.updated` oder `legal_text.scheduled`). |
| `timestamp` | `string` (ISO 8601) | Zeitpunkt des Webhook-Versands. |
| `project` | `object` | Stammdaten des betroffenen Projekts (`id`, `name`, `domain`). |
| `data` | `object` | Die inhaltlichen Details des betroffenen Dokuments. |

### `data`-Objekt (Dokumentendetails)

| Feld | Typ | Beschreibung |
| :--- | :--- | :--- |
| `document_id` | `integer` | Eindeutige interne ID des Dokuments. |
| `slug` | `string` | URL-Slug des Dokuments (z. B. `agb-b2c`, `datenschutz`, `impressum`). |
| `lang` | `string` (2-stellig) | Sprachcode der geänderten Version (z. B. `de`, `en`, `es`, `fr`). |
| `title` | `string` | Der vom Admin vergebene Titel in der jeweiligen Sprache. |
| `status` | `string` | Aktueller Veröffentlichungsstatus (`published` oder `scheduled`). |
| `change_note` | `string` | Optionale Revisionsnotiz des Admins (z. B. Grund der Änderung). |
| `was_scheduled` | `boolean` | `true`, falls diese Veröffentlichung zuvor zeitgesteuert geplant war. |
| `scheduled_at` | `string` (ISO 8601) | Nur bei `scheduled`: Der geplante Zeitpunkt des Inkrafttretens. |
| `effective_date`| `string` (ISO 8601) | Datum des Inkrafttretens (bei Live sofort, bei Scheduled der Stichtag). |
| `url` | `string` (HTTPS) | Öffentliche Web-URL der entsprechenden Sprachfassung. |
| `api_url` | `string` (HTTPS) | Headless JSON-API URL zum direkten Abruf des gerenderten HTML-Inhalts. |
| `updated_at` | `string` (ISO 8601) | Zeitstempel der letzten Speicherung in der Datenbank. |

---

## 5. Implementierungsbeispiel für Empfänger

### In TypeScript / Node.js (Express)

```typescript
import express, { Request, Response } from 'express';
import crypto from 'crypto';

interface ParagrafyWebhookPayload {
  event: 'legal_text.updated' | 'legal_text.scheduled';
  timestamp: string;
  project: {
    id: number;
    name: string;
    domain: string;
  };
  data: {
    document_id: number;
    slug: string;
    lang: string;
    title: string;
    status: string;
    change_note?: string;
    was_scheduled?: boolean;
    scheduled_at?: string;
    effective_date: string;
    url: string;
    api_url: string;
    updated_at?: string;
  };
}

const app = express();
const WEBHOOK_SECRET = process.env.PARAGRAFY_WEBHOOK_SECRET || 'mein-webhook-secret';

app.post('/api/legal-webhook', express.raw({ type: 'application/json' }), (req: Request, res: Response) => {
  const signature = req.headers['x-paragrafy-signature'] as string;
  const rawBody = req.body.toString('utf8');

  // 1. Signatur validieren
  if (WEBHOOK_SECRET) {
    const expected = crypto.createHmac('sha256', WEBHOOK_SECRET).update(rawBody).digest('hex');
    const valid = signature && crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
    if (!valid) {
      return res.status(401).json({ error: 'Ungültige Signatur' });
    }
  }

  const payload: ParagrafyWebhookPayload = JSON.parse(rawBody);

  // 2. Mapping & Logik ausführen
  switch (payload.event) {
    case 'legal_text.scheduled':
      // z.B. In-App-Hinweis: "Neue AGB ab dem 31.08." planen
      console.log(`[Vorankündigung] ${payload.data.title} (${payload.data.lang}) geht live am ${payload.data.scheduled_at}`);
      break;

    case 'legal_text.updated':
      // z.B. User-Consent-Flag in DB zurücksetzen, wenn es die AGB betrifft
      if (payload.data.slug.startsWith('agb')) {
        console.log(`[AGB Aktiv] Neue Version ${payload.data.effective_date} ist jetzt live.`);
        // await db.users.updateMany({ data: { must_accept_terms: true } });
      }
      break;
  }

  return res.status(200).json({ success: true });
});
```
