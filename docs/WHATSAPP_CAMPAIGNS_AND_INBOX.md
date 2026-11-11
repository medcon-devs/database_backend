# WhatsApp campaigns, response attribution, and CMS inbox

This module turns WhatsApp template sending into an auditable campaign and
conversation workflow. All management endpoints require an authenticated
administrator (`access_type=FULL`).

## Data model

- `whatsapp_campaigns`: campaign definition, approved template identity,
  scheduling state, recipient/exclusion totals, and lifecycle timestamps.
- `whatsapp_campaign_recipients`: immutable recipient snapshot, personalized
  send components, delivery state, and response summary.
- `whatsapp_messages`: complete inbound/outbound timeline. Campaign replies are
  attributed using Meta's inbound `context.id`, never by guessing from text.

Marketing campaigns always enforce explicit `whatsapp_consents.status=opted_in`.
Contacts without a valid phone or required consent are excluded and counted.

## Create a campaign

Preview the final audience before creating the campaign:

```http
POST /api/whatsapp/campaigns/audience-preview
Content-Type: application/json

{
  "template_name": "test2",
  "template_language": "en_US",
  "contact_ids": [12, 18],
  "tag_ids": [4, 7],
  "require_opt_in": true
}
```

The backend builds a union of contacts and tags and returns:

```json
{
  "selected_contact_count": 410,
  "unique_valid_phone_count": 376,
  "eligible_recipient_count": 320,
  "excluded": {
    "invalid_or_missing_phone_count": 12,
    "duplicate_phone_count": 22,
    "missing_consent_count": 56
  },
  "tag_breakdown": [
    {"tag_id": 4, "contact_count": 210, "unique_valid_phone_count": 198}
  ]
}
```

`eligible_recipient_count` is the number the confirmation screen must show as
the final audience. Tag breakdown totals must not be added together because the
same contact or phone can belong to multiple tags.

```http
POST /api/whatsapp/campaigns
Authorization: Bearer {JWT}
Content-Type: application/json

{
  "name": "October surgery conference",
  "template_name": "test2",
  "template_language": "en_US",
  "contact_ids": [12, 18],
  "tag_ids": [4],
  "require_opt_in": true,
  "components": [
    {
      "type": "header",
      "parameters": [
        {"type": "image", "image": {"link": "https://example.com/conference.png"}}
      ]
    },
    {
      "type": "body",
      "parameters": [
        {"type": "text", "text": "{{contact.firstname}}"}
      ]
    }
  ]
}
```

At least one of `contact_ids` or `tag_ids` is required. The backend resolves
the union, removes duplicate phone numbers, verifies the template with Meta,
requires `APPROVED` status, and snapshots eligible recipients.

Personalization tokens:

```text
{{contact.id}}
{{contact.firstname}}
{{contact.lastname}}
{{contact.email}}
{{contact.phone}}
{{contact.country}}
{{contact.speciality}}
```

Add `scheduled_at` (ISO-8601 future datetime) to create a scheduled campaign.
Without it, the campaign is created as a draft.

## Campaign endpoints

```text
GET  /api/whatsapp/campaigns
POST /api/whatsapp/campaigns
POST /api/whatsapp/campaigns/audience-preview
GET  /api/whatsapp/campaigns/{id}
PATCH /api/whatsapp/campaigns/{id}/content
GET  /api/whatsapp/campaigns/{id}/recipients
GET  /api/whatsapp/campaigns/{id}/responses
POST /api/whatsapp/campaigns/{id}/send
POST /api/whatsapp/campaigns/{id}/cancel
```

Useful filters:

```text
GET /campaigns?status=completed&per_page=25
GET /campaigns/{id}/recipients?status=delivered&responded=1&per_page=50
```

The campaign response endpoint returns the individual replies plus a `summary`
containing total responses and counts grouped by selected button text.

## Cloudinary media

Cloudinary credentials stay in Laravel's `.env`. The frontend first requests a
short-lived signed upload payload:

```http
POST /api/whatsapp/campaigns/cloudinary-signature
Content-Type: application/json

{"campaign_name": "October Surgery Conference 2026"}
```

For an existing draft, send `campaign_id` instead. The backend derives and
signs the folder; the browser cannot choose it:

```text
database/campaigns/october-surgery-conference-2026
```

The response includes `upload_url`, public `api_key`, `signature`, `timestamp`,
and `upload_parameters`. It never includes the API secret. Upload the file plus
every returned upload parameter directly to `upload_url`, then put Cloudinary's
returned `secure_url` in the Meta send component:

```json
{
  "type": "header",
  "parameters": [
    {
      "type": "video",
      "video": {"link": "https://res.cloudinary.com/example/video/upload/conference.mp4"}
    }
  ]
}
```

Use `image`, `video`, or `document` to match the approved template header. The
URL must be publicly downloadable by Meta and must not require cookies or an
Authorization header.

Server configuration:

```env
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
CLOUDINARY_BASE_FOLDER=database
```

Draft or scheduled campaign media/components can be replaced before sending:

```http
PATCH /api/whatsapp/campaigns/{id}/content
Content-Type: application/json

{"components": [...]}
```

The backend regenerates personalized components for every recipient. Updates
are rejected with HTTP 409 after sending begins, preserving the audit record.

## CMS inbox

```text
GET  /api/whatsapp/conversations?search=97156&per_page=30
GET  /api/whatsapp/conversations/{phone}?per_page=50
POST /api/whatsapp/conversations/{phone}/messages
POST /api/whatsapp/conversations/{phone}/read
```

Send a free-form reply:

```json
{"message": "Thank you. Our team will send the conference details shortly."}
```

The API only permits free-form messages during the 24-hour customer service
window opened by the latest inbound user message. Outside it, the endpoint
returns HTTP 409 with `code=WHATSAPP_WINDOW_CLOSED` and
`requires_template=true`. The CMS must then offer an approved template.

Conversation results include `unread_count`,
`customer_service_window_expires_at`, and `can_send_free_form`.

## Webhook requirement

Configure Meta with the public callback and subscribe to the `messages` field:

```text
https://api-database.medcon.ae/api/webhooks/whatsapp
```

URL-button clicks are not returned by Meta. Quick replies, typed messages,
delivery states, and read states arrive through this webhook.

## Production deployment

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Set:

```env
QUEUE_CONNECTION=database
```

Run a persistent worker (Supervisor/systemd recommended):

```bash
php artisan queue:work database --queue=whatsapp,default --sleep=1 --tries=3 --timeout=90
```

Run Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/database_backend && php artisan schedule:run >> /dev/null 2>&1
```

Do not send a campaign until the queue worker and public webhook are both
operational. The worker performs sending; the webhook supplies the authoritative
delivery, read, failure, and response states.
