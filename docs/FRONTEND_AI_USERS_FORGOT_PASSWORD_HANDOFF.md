# Next.js frontend handoff: AI search, users, and forgot password

Paste the following message into the Codex task for the Next.js frontend project.

---

I have an existing Laravel backend for Medcon Database. Implement the Next.js frontend for the features below using the project's existing design system, API client, authentication/session approach, form library, and component conventions. Do not create mock APIs and do not expose any server secrets to client components.

## API conventions

- All paths below are relative to the Laravel API base URL and start with `/api`.
- Protected endpoints require `Authorization: Bearer <access_token>`.
- Normal validation errors return HTTP `422` with Laravel's `errors` object.
- Handle `401` as an expired/invalid session, `403` as insufficient permission, and `429` as rate limiting.
- Never put `OPENAI_API_KEY`, SMTP credentials, or refresh tokens in a `NEXT_PUBLIC_*` variable.

## 1. AI contact search

Create an AI search experience on the contacts page. It accepts natural language such as:

- `Find Ali cardiologist in UAE`
- `I need Dr Ahmad, dermatologist in Jordan`
- `Find Alo with the same last name; he may be a cardiologist`

Endpoint:

```http
POST /api/ai-search
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "query": "Find Ali cardiologist in UAE",
  "page": 1,
  "perPage": 30
}
```

Rules: `query` is required and must contain 2–500 characters; `page >= 1`; `perPage` is 1–100. The endpoint is limited to 30 requests per minute.

Response shape:

```json
{
  "data": [
    {
      "id": 123,
      "firstname": "Ali",
      "lastname": "Ahmad",
      "email": "ali@example.com",
      "phone": "+971500000000",
      "country": "United Arab Emirates",
      "speciality": "Cardiology",
      "deleted_at": null
    }
  ],
  "status_code": 200,
  "message": "Data retrieved successfully",
  "status": true,
  "perPage": 30,
  "currentPage": 1,
  "total": 1,
  "totalPages": 1,
  "is_next": false,
  "interpretation": {
    "original_query": "Find Ali cardiologist in UAE",
    "search_text": "Ali",
    "countries": ["United Arab Emirates"],
    "specialities": ["Cardiology"],
    "explanation": "...",
    "confidence": 0.95,
    "parser": "openai"
  }
}
```

`parser` can be `local` or `openai`; both are valid successful modes. Production defaults to Medcon's free smart local parser, which understands Arabic/English phrases and common country/speciality typos without an external API. Typesense performs typo-tolerant contact search, so names such as `Ali` can match `Alo`. Never call an AI provider directly from Next.js.

Frontend requirements:

- Add a clearly labeled “AI search” natural-language input, submit button, loading state, clear button, and example prompt.
- Do not send a request for fewer than two trimmed characters.
- Debouncing is optional; explicit submit is preferred because this endpoint interprets full questions.
- Render results in the existing contacts table and reuse its pagination.
- Show the interpretation as removable filter chips for name/search text, countries, and specialities.
- Optionally show the explanation in a compact “How this was interpreted” area. Do not display confidence as a scary warning; it is diagnostic metadata.
- If there are no results, keep the interpretation visible and offer to edit the query.
- Preserve normal fuzzy/filter search as a separate mode; make it obvious which mode is active.

Canonical filter values are available from protected `GET /api/countries` and `GET /api/specialties`. Display an empty value as “Not specified.”

## 2. User administration and invitations

Only users whose `access_type` is `FULL` can manage users. Hide admin UI for other roles, but still handle backend `403` responses.

Access values:

- `R`: read only
- `RW`: read and import
- `RWE`: read, import, and edit
- `RWED`: read, import, edit, delete, and restore
- `FULL`: administrator

List users:

```http
GET /api/get-users
Authorization: Bearer <access_token>
```

Create user:

```http
POST /api/user
Authorization: Bearer <access_token>
Content-Type: application/json
```

```json
{
  "name": "Employee Name",
  "email": "employee@example.com",
  "access_type": "RWE"
}
```

Do not show or send a password field. The backend creates the account with an unusable random password and emails the user a secure invitation to a Laravel password page.

The success response is HTTP `201` and keeps the user fields at the top level for compatibility:

```json
{
  "id": 25,
  "name": "Employee Name",
  "email": "employee@example.com",
  "access_type": "RWE",
  "is_banned": false,
  "created_at": "...",
  "updated_at": "...",
  "setup_email_sent": true
}
```

If `setup_email_sent` is `false`, the account was created but email delivery failed. Do not submit the create request again. Show a warning and a “Resend invitation” action.

Resend invitation:

```http
POST /api/users/{userId}/resend-password-setup
Authorization: Bearer <access_token>
```

```json
{
  "data": { "setup_email_sent": true },
  "status_code": 200,
  "message": "Password setup email sent successfully.",
  "status": true
}
```

This endpoint is limited to five requests per minute. Disable the action while pending and show the API message in a toast.

Invitation/password page behavior:

- The email opens a Laravel-rendered page; do not build the password setup form in Next.js.
- The link expires after 60 minutes and is single-use.
- Password rules are at least 8 characters with uppercase, lowercase, and a number.
- Laravel redirects to the configured Next.js login URL with `?password_set=1` after success.
- On the login page, detect `password_set=1`, show a success alert such as “Your account is ready. You can sign in now,” then optionally remove the query parameter without reloading.

User management UI:

- Create-user dialog: name, email, access level; no password.
- Table: name, email, access level, active/banned status, created date, actions.
- Include resend invitation, edit, and ban/unban actions according to existing endpoints and permissions.
- Use confirmation dialogs for destructive or access-changing actions.

Administrator notification preference:

- User objects now include `email_verified_at` and boolean `receive_notification`.
- New accounts initially have `receive_notification: false`.
- Completing the Laravel invitation verifies the account and changes the preference to `true`.
- Only verified, active `FULL` users with this preference enabled receive operational activity emails.
- Add an administrator-only switch to Account Settings labeled “Email activity notifications.”
- Update it using `PATCH /api/user/notification-preference` with `{ "receive_notification": true | false }`.
- Use the returned `data.receive_notification` as the saved value and show the API message in a toast.
- When a verified administrator enables notifications, the backend sends a professional confirmation email explaining the preference.

## 3. Forgot password

Add a “Forgot password?” link to the login page and create a public forgot-password page.

Endpoint:

```http
POST /api/forgot-password
Content-Type: application/json
```

Request:

```json
{
  "email": "employee@example.com"
}
```

Valid requests always return HTTP `200` with the same response, whether or not the email exists. This prevents account discovery:

```json
{
  "data": null,
  "status_code": 200,
  "message": "If an account exists for this email, a password reset link has been sent.",
  "status": true
}
```

The endpoint is public and limited to five requests per minute. Invalid email syntax returns `422`; too many attempts return `429`.

Frontend requirements:

- Public route with email input, submit button, loading state, inline validation, and link back to login.
- After HTTP `200`, always show the generic API message. Never say that an email/account was or was not found.
- Disable repeated submission briefly and handle `429` with a friendly retry-later message.
- The email opens Laravel's secure reset-password page; do not build the reset form in Next.js.
- After the user changes the password, Laravel redirects to login with `?password_reset=1`.
- On login, show “Your password was reset successfully. You can sign in now” for `password_reset=1`.

## Acceptance checks

- AI search handles loading, empty, error, pagination, interpretation chips, typo-tolerant results, and both `openai`/`local` parsers.
- Non-FULL users cannot see user administration; backend `403` is still handled.
- Create-user submits no password and handles both values of `setup_email_sent` correctly.
- Resend invitation cannot be double-submitted.
- Forgot-password success looks identical for existing and unknown emails.
- Login displays correct success feedback for both `password_set=1` and `password_reset=1`.
- All forms are keyboard accessible, responsive, and use the existing project's visual language rather than introducing a second design system.

---
