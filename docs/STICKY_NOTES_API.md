# Sticky Notes API

Sticky notes are a unified feature: one note object anchored **either** to a
contact profile **or** to a single document folder (`contact_media_groups`),
with optional @mentions that send the same notification email as the legacy
`/mentions` flow.

There are **no sticky-note permissions**. Any user who can view the contact
(`contacts.view`) can list, create, edit, change status, archive, and restore
its notes. Tenant scoping (`microsoft_tenant_id`) is always enforced.

## Endpoints

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/api/contacts/{contact}/sticky-notes` | Active notes by default. |
| `GET` | `/api/contacts/{contact}/sticky-notes?status=archived` | Archived notes (for the "Archived" button). |
| `GET` | `/api/contacts/{contact}/sticky-notes?media_group_id={id}` | Notes anchored to that folder. |
| `GET` | `/api/contacts/{contact}/sticky-notes?media_group_id=none` | Profile-level notes only. |
| `POST` | `/api/contacts/{contact}/sticky-notes` | Create. Honours `Idempotency-Key`. |
| `PATCH` | `/api/sticky-notes/{note}` | Edit `content` and/or mentions. |
| `PATCH` | `/api/sticky-notes/{note}/status` | Manual archive / restore. |

`GET` also accepts `per_page` (1–100, default 25); response carries a
`pagination` block `{current_page, per_page, total, last_page}`.
`status` filter and `media_group_id` filter compose.

## Create — `POST /contacts/{contact}/sticky-notes`

```json
{
  "content": "Please add the doctor's bank details.",
  "media_group_id": 42,
  "mentioned_user_ids": [7, 12]
}
```

- `content` — required, max `10000`.
- `media_group_id` — optional; omit or `null` for a profile-level note. Must
  belong to the same contact, else `422` with `errors.media_group_id`.
- `mentioned_user_ids` — optional array, max `100`. Each user must be able to
  view this contact (same tenant, not banned). Any ineligible id →
  `422 { "code": "INVALID_MENTIONED_USERS" }` and the note is not created.
- `Idempotency-Key` header (≤255 chars) — a repeat with the same key by the
  same user returns the original note with `"idempotent_replay": true` and
  HTTP `200`.

## Edit — `PATCH /sticky-notes/{note}`

```json
{ "content": "Updated text", "mentioned_user_ids": [7, 20] }
```

`mentioned_user_ids` is the **full desired set**: users added get a
notification, users removed are detached, unchanged users are left alone (no
re-send). Omit the key entirely to leave mentions untouched.

## Status — `PATCH /sticky-notes/{note}/status`

```json
{ "status": "archived" }   // or "active"
```

Archiving stamps `archived_at` + `archived_by`. Restoring clears both and
**resets `archive_due_at` to now + 7 days**.

## Note shape

```json
{
  "id": 1,
  "contact_id": 10,
  "media_group_id": 42,
  "media_group": { "id": 42, "title": "Passport & ID" },
  "content": "…",
  "status": "active",
  "archive_due_at": "2026-09-10T09:00:00.000000Z",
  "archived_at": null,
  "archived_by": null,
  "created_by": 7,
  "updated_by": 7,
  "created_at": "2026-09-03T09:00:00.000000Z",
  "updated_at": "2026-09-03T09:00:00.000000Z",
  "creator":  { "id": 7,  "name": "…", "email": "…" },
  "updater":  { "id": 7,  "name": "…", "email": "…" },
  "archiver": null,
  "mentions": [
    { "user": { "id": 12, "name": "…", "email": "…" }, "email_status": "queued" }
  ]
}
```

- `media_group` is `null` for profile-level notes.
- `mentions[].user` (`id`, `name`, `email`) is present on **all** responses.
- `mentions[].email_status` (`queued` / `sending` / `sent` / `failed`) is
  included on create / update / status responses; **omitted on the list
  response**.
- `archived_by` is `null` when the note was auto-archived by the 7-day rule.

## Archiving lifecycle

- A note is `active` for **7 days** from creation (or last manual restore),
  tracked by `archive_due_at` (configurable via `STICKY_NOTE_ACTIVE_DAYS`).
- Expired active notes are moved to `archived` by the `sticky-notes:archive-expired`
  scheduled command (every minute) and, defensively, on any `GET` of the list.
- Auto-archive sets `archived_at` and leaves `archived_by` null.

## Mention notifications

The mention email links to:

```
{FRONTEND_URL}/contacts/{contact_id}?tab=profile&note={note_id}
{FRONTEND_URL}/contacts/{contact_id}?tab=profile&note={note_id}&folder={media_group_id}
```

`FRONTEND_URL` resolves as `FRONTEND_URL` → `MICROSOFT_FRONTEND_URL` →
`http://localhost:3000` (`config/mentions.php`). Confirm it is set on staging
and production.

## Mention user search

Reuse the existing endpoint with the `contact` context type:

```
GET /api/mentions/users?context_type=contact&context_id={contact_id}&search=...
```

Returns same-tenant, non-banned users who can view the contact. This route is
still gated by `permission:mentions.create`.

## Migration from the per-folder mention thread

- `GET`/`POST /api/mentions/messages?context_type=contact_media_group` still
  work for one release as a read fallback.
- Existing folder-thread messages were copied into `sticky_notes` by
  `2026_09_04_010000_backfill_media_group_threads_into_sticky_notes`
  (content, `media_group_id`, sender, timestamps, mentions and their email
  status preserved). Copies older than 7 days are `archived` with
  `archived_by = null`.
