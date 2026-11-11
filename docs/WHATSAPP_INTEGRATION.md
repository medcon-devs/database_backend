# WhatsApp Cloud API integration

This document covers the first integration phase: secure configuration,
test-template sending, webhook verification, delivery status tracking, and
WhatsApp keyword consent.

## Security first

Revoke any access token that has been pasted into chat, email, source control,
logs, or a shared terminal transcript. Generate a replacement token in Meta and
store it only in the local and production `.env` files.

Never put real values in `.env.example`.

## Environment

```env
WHATSAPP_ENABLED=true
WHATSAPP_GRAPH_VERSION=v25.0
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_APP_ID=
WHATSAPP_APP_SECRET=
WHATSAPP_WEBHOOK_VERIFY_TOKEN=
WHATSAPP_TIMEOUT_SECONDS=15
```

- `WHATSAPP_PHONE_NUMBER_ID` and `WHATSAPP_BUSINESS_ACCOUNT_ID` are shown on
  WhatsApp > API Setup.
- `WHATSAPP_APP_ID` and `WHATSAPP_APP_SECRET` are under App settings > Basic.
- `WHATSAPP_ACCESS_TOKEN` can be temporary for testing. Production must use a
  System User token with only the required WhatsApp permissions.
- Generate `WHATSAPP_WEBHOOK_VERIFY_TOKEN` yourself. It is a shared random value
  used only for the one-time callback verification.

## Deploy

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

## Configure the Meta webhook

In Meta Developers:

1. Open the Medcon app.
2. Open WhatsApp > Configuration.
3. Set Callback URL to:
   `https://api-database.medcon.ae/api/webhooks/whatsapp`
4. Set Verify token to the exact value of
   `WHATSAPP_WEBHOOK_VERIFY_TOKEN` from production `.env`.
5. Verify and save.
6. Subscribe the WhatsApp Business Account to the `messages` webhook field.

The callback rejects POST requests that do not contain a valid
`X-Hub-Signature-256` generated with the Meta App Secret.

## Test-template API

Only an authenticated `FULL` administrator can call:

```http
POST /api/whatsapp/test-message
Authorization: Bearer {Medcon JWT}
Content-Type: application/json

{
  "to": "+971501234567",
  "template_name": "hello_world",
  "language": "en_US",
  "header_parameters": ["Dr. Ahmad"],
  "body_parameters": ["Dr. Ahmad", "Medcon Event"],
  "header_image_url": "https://example.com/event-header.png"
}
```

`header_parameters` and `body_parameters` must be supplied in positional
order for `{{1}}`, `{{2}}`, and later parameters. A template with an image
header requires `header_image_url`, which must be a publicly accessible HTTP
or HTTPS image URL that Meta can fetch. Text-header parameters and
`header_image_url` are alternatives and should not be sent together.

For dynamic URL buttons, copy-code buttons, Flow actions, named parameters,
and other advanced approved-template values, send the Meta-native
`components` array instead. When present, it replaces the simplified header
and body parameter fields.

The endpoint stores the outbound record before sending and returns the local
record ID and Meta `wamid`.

## Template management APIs

Only authenticated `FULL` administrators can list or create templates:

```http
GET /api/whatsapp/templates?limit=50&after={meta-cursor}
```

```http
POST /api/whatsapp/templates
Content-Type: application/json

{
  "name": "medcon_event_invitation",
  "language": "en_US",
  "category": "MARKETING",
  "header": "Medcon Event",
  "body": "We would like to invite you to our upcoming medical event.",
  "footer": "Reply STOP to opt out.",
  "buttons": [
    {"type": "QUICK_REPLY", "text": "Interested"},
    {"type": "QUICK_REPLY", "text": "Not interested"},
    {"type": "URL", "text": "Event details", "url": "https://example.com/event"}
  ]
}
```

The template API supports `AUTHENTICATION`, `MARKETING`, and `UTILITY`
templates. Simplified fields support text, image, video, and document headers;
body parameters and examples; footers; and up to ten `QUICK_REPLY`, `URL`,
`PHONE_NUMBER`, `COPY_CODE`, `CATALOG`, or `FLOW` buttons.

Buttons may use `QUICK_REPLY` (the default when `type` is omitted) or `URL`.
A URL button requires an HTTP or HTTPS `url`. Meta does not send URL-button
clicks to the messages webhook, so URL clicks do not appear as responses.
Use a tracked redirect URL if click analytics are required.

## Template responses API

Quick-reply and list selections received by the webhook are stored in
`whatsapp_messages`. Administrators can retrieve them with:

```http
GET /api/whatsapp/responses?per_page=50&phone=+971501234567&contact_id=123&from=2026-07-01&to=2026-07-31
Authorization: Bearer {Medcon JWT}
```

All filters are optional. Each result contains the displayed selection in
`content`; stable identifiers are available in `metadata.button_payload`,
`metadata.button_reply_id`, or `metadata.list_reply_id`.

Text parameters use sequential positional syntax such as `{{1}}` and `{{2}}`.
Send `header_example` for a text-header `{{1}}` parameter and
`body_examples` in matching numerical order for body parameters.

For an image header, submit the request as `multipart/form-data` with
`header_type=IMAGE` and a `header_image` JPEG or PNG of up to 5 MB. The backend
uses Meta's resumable upload API and sends the resulting private handle when
the template is created. The file is never exposed to the browser after upload.

Video and document headers use `header_type=VIDEO` or `DOCUMENT` and the
`header_media` field. Supported files are MP4, PDF, DOC/DOCX, XLS/XLSX, and
PPT/PPTX up to 100 MB (subject to PHP/server upload limits).

### Advanced Meta-native mode

To use every Meta component without waiting for a backend schema change, send
`components` exactly in Meta's message-template format. This mode supports
authentication OTP templates, catalog and multi-product templates, location
headers, Flows, carousels, limited-time offers, named parameters, and newer
components introduced by Meta. The backend authenticates and forwards this
array; Meta remains the final validator.

```json
{
  "name": "authentication_code_autofill",
  "language": "en_US",
  "category": "AUTHENTICATION",
  "components": [
    {"type": "BODY", "add_security_recommendation": true},
    {"type": "FOOTER", "code_expiration_minutes": 10},
    {
      "type": "BUTTONS",
      "buttons": [
        {
          "type": "OTP",
          "otp_type": "ONE_TAP",
          "text": "Copy Code",
          "autofill_text": "Autofill",
          "package_name": "com.example.app",
          "signature_hash": "YOUR_APP_SIGNATURE_HASH"
        }
      ]
    }
  ]
}
```

Optional top-level fields include `parameter_format` (`POSITIONAL` or `NAMED`),
`message_send_ttl_seconds`, and `allow_category_change`.

Meta returns a review status such as `PENDING`, `APPROVED`, or `REJECTED`.
Only approved templates should be offered by the frontend for sending.

## Consent keywords

Exact inbound messages recognized as opt-in include:

```text
JOIN, YES, START, SUBSCRIBE, موافق, تأكيد, تاكيد, اشتراك, اشترك
```

Exact inbound messages recognized as opt-out include:

```text
STOP, UNSUBSCRIBE, CANCEL, إلغاء, الغاء, توقف, وقف, لا ترسل
```

Each change updates `whatsapp_consents` and adds an immutable audit row to
`whatsapp_consent_events`. Duplicate Meta webhook deliveries do not duplicate
the message or consent event.

## External consent API

Registration sites can record an explicit WhatsApp choice without sending the
user to WhatsApp:

```http
POST /api/v1/whatsapp/consents
Content-Type: application/json
```

```json
{
  "phone": "+966501234567",
  "action": "opt_in",
  "source_domain": "webinar.medcon.ae",
  "consent_text": "I agree to receive Medcon messages through WhatsApp.",
  "consent_version": "whatsapp-marketing-v1",
  "evidence_reference": "teams-registration:123"
}
```

`action` accepts `opt_in` and `opt_out`. `consent_text` is required for opt-in.
The API records the request IP, user agent, origin, source domain, server time,
and an immutable consent event. An `evidence_reference` should uniquely identify
the source registration; retrying it does not create another event.

International numbers retain their country code. Local numbers without a
country code use the configured UAE default (`971`). For example,
`+966501234567` becomes `966501234567`, while `0566167139` becomes
`971566167139`.

## Tables

- `whatsapp_messages`: outbound/inbound messages and status timestamps.
- `whatsapp_consents`: current consent state by normalized phone number.
- `whatsapp_consent_events`: immutable opt-in and opt-out evidence.

Campaign creation, recipient snapshots, scheduling, queue workers, template
management, and frontend screens belong to the next implementation phase.
