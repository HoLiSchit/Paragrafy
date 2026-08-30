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
| `legal_text.scheduled` | Eine Textänderung wurde für einen zukünftigen Zeitpunkt geplant. | Interne Vorankündigung planen (z. B. Erinnerung für dein Team), dass sich ein Rechtstext zum Stichtag ändert. Es gibt keine öffentliche Vorschau-URL für die geplante Fassung — der aktuelle Stand bleibt bis zum Stichtag live. |
| `legal_text.updated` | Ein Rechtstext wurde sofort live veröffentlicht, ein geplanter Stichtag wurde erreicht, oder eine frühere Version wurde wiederhergestellt. | Neue AGB-Zustimmung im User-Account erzwingen, App-Cache invalidieren. |

---

## 3. Payload-Spezifikation

### A. Event: `legal_text.scheduled` (Vorankündigung)

Wird gefeuert, wenn im Editor eine zeitgesteuerte Live-Schaltung für die Zukunft geplant wird. `url`/`api_url` verweisen dabei weiterhin auf die aktuell live sichtbare Fassung — es gibt keine separate Vorschau-URL für die geplante Neufassung, diese wird erst zum Stichtag live geschaltet.

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
    "change_note": "Aktualisierung der Zahlungsbedingungen zum 31.08.",
    "scheduled_at": "2026-08-31T00:00:00+02:00",
    "effective_date": "2026-08-31T00:00:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "was_scheduled": false
  }
}
```

---

### B. Event: `legal_text.updated` (Live-Veröffentlichung)

Wird gefeuert, sobald ein Rechtstext aktiv geschaltet wurde (sofort oder nach Ablauf des Stichtags).

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
    "was_scheduled": true,
    "effective_date": "2026-08-30T15:45:00+02:00",
    "url": "https://legal.deinedomain.de/de/agb-b2c",
    "api_url": "https://legal.deinedomain.de/api/de/agb-b2c",
    "updated_at": "2026-08-30T15:45:00+02:00"
  }
}
```

---

## 4. Felddefinitionen (Daten-Mapping)

| Feldname | Typ | Bedeutung |
| :--- | :--- | :--- |
| `data.title` | `string` | Der Titel in der jeweiligen Zielsprache. |
| `data.slug` | `string` | Eindeutiger Bezeichner (`agb-b2c`, `datenschutz`, `impressum`). |
| `data.lang` | `string` | 2-stelliger Sprachcode (`de`, `en`, `es`, `fr` etc.). |
| `data.effective_date` | `string` (ISO 8601) | **Inkrafttretungsdatum** (bei Live sofort, bei Scheduled der Stichtag). |
| `data.scheduled_at` | `string` (ISO 8601) | Nur bei `scheduled`: Der geplante Umschaltzeitpunkt. |
| `data.url` | `string` | URL der aktuell gültigen Live-Version. |
| `data.api_url` | `string` | JSON-API URL der aktuell gültigen Live-Version. |
| `data.was_scheduled` | `boolean` | `true`, falls diese Veröffentlichung aus einer Planung hervorging. |
| `data.change_note` | `string` | Die vom Admin vergebene Revisionsnotiz. |

---

## 5. Implementierungsbeispiel in TypeScript / Node.js

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

  // 2. Event Routing
  switch (payload.event) {
    case 'legal_text.scheduled':
      // Interne Vorankündigung: der aktuelle Text bleibt bis zum Stichtag live.
      console.log(`[Vorankündigung] ${payload.data.title} ändert sich zum ${payload.data.scheduled_at}`);
      break;

    case 'legal_text.updated':
      // Neue Version ist aktiv: User-Consent anfordern
      console.log(`[Live] ${payload.data.title} ist jetzt in Kraft.`);
      break;
  }

  return res.status(200).json({ success: true });
});
```
