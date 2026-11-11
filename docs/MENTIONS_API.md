# Mentions and notifications API

All endpoints require `Authorization: Bearer <token>`, an active user, and JSON responses. The only currently supported context is `contact_media_group`.

## Permissions

- `mentions.create`: search eligible recipients and create a message.
- `mentions.read`: retrieve message history.
- `contact_media.view`: required in addition to either mention permission to access the underlying media group.

These permissions are returned by `GET /api/get-user`. Tenant isolation uses exact `users.microsoft_tenant_id` matching; tenantless users are only visible to other tenantless users.

## Search eligible users

`GET /api/mentions/users?context_type=contact_media_group&context_id=123&search=maj&page=1&per_page=20`

```json
{
  "data": [{"id": 7, "name": "Majd Ahmad", "email": "majd@example.com", "avatar_url": null}],
  "pagination": {"current_page": 1, "per_page": 20, "total": 1, "last_page": 1}
}
```

The backend returns only non-banned users in the sender's tenant who currently have `contact_media.view`. Search matches name or email. `per_page` is limited to 50.

## Create a message

`POST /api/mentions/messages`

Send a unique `Idempotency-Key` header for each user submission. Reusing the same key for the same sender returns the original message without creating or emailing duplicates.

```json
{
  "context_type": "contact_media_group",
  "context_id": 123,
  "message": "Please review the updated license.",
  "mentioned_user_ids": [7, 14]
}
```

Success (`201`):

```json
{
  "data": {
    "id": 456,
    "context_type": "contact_media_group",
    "context_id": 123,
    "message": "Please review the updated license.",
    "sender": {"id": 2, "name": "Administrator"},
    "mentions": [{
      "user": {"id": 7, "name": "Majd Ahmad", "email": "majd@example.com"},
      "email_status": "queued"
    }],
    "created_at": "2026-08-27T12:00:00.000000Z"
  },
  "message": "Message sent successfully."
}
```

An idempotent replay returns `200`, the same data, `"message": "Message already sent."`, and `"idempotent_replay": true`.

Messages are stored as escaped plain text with a maximum of 5,000 characters. Duplicate recipient IDs collapse to one mention. The sender may be included if they are eligible. Email delivery rows begin as `queued` and are updated to `sending`, `sent`, or `failed` by the queue worker.

## Retrieve history

`GET /api/mentions/messages?context_type=contact_media_group&context_id=123&page=1&per_page=20`

```json
{
  "data": [{
    "id": 456,
    "context_type": "contact_media_group",
    "context_id": 123,
    "message": "Please review the updated license.",
    "sender": {"id": 2, "name": "Administrator"},
    "mentions": [{"user": {"id": 7, "name": "Majd Ahmad"}}],
    "created_at": "2026-08-27T12:00:00.000000Z"
  }],
  "pagination": {"current_page": 1, "per_page": 20, "total": 1, "last_page": 1}
}
```

## Errors

- `401`: missing or invalid authentication.
- `403`: missing mention permission or no access to the underlying resource.
- `404`: context resource does not exist.
- `422`: validation failure, unsupported context, empty message, or no recipients.
- `429`: rate limit exceeded. Create is limited to 20 requests/minute; reads and user search to 60/minute.

An ineligible recipient response intentionally does not identify which user failed:

```json
{
  "code": "INVALID_MENTIONED_USERS",
  "message": "One or more selected users cannot be mentioned in this file.",
  "fieldErrors": {
    "mentioned_user_ids": ["One or more users are inactive or do not have access to this resource."]
  },
  "status": 422
}
```

## Email and queue operation

The direct link is generated from `FRONTEND_URL` and has this shape:

`{FRONTEND_URL}/contacts/{contact_id}?media_group={group_id}&message={message_id}`

It never contains tokens or file URLs. Run the notification queue in production:

```bash
php artisan queue:work database --queue=notifications,default --sleep=1 --tries=3
```

Email failures are recorded on `notification_deliveries` and retried without rolling back or duplicating the saved message.
