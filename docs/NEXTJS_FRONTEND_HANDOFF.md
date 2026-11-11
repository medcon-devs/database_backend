# Medcon Database — Next.js Frontend Handoff

Last verified: 2026-07-15

This document is the source of truth for replacing the current Flutter Web frontend with a new Next.js application. It describes the product, the existing Laravel API, data model, access control, search, user flows, recommended design system, security constraints, implementation structure, and acceptance criteria.

> Important: the Laravel API is usable, but the security findings in section 13 must be fixed before the new frontend is considered production-ready. Hiding a button in Next.js is not authorization; Laravel must enforce every permission.

## 1. Product summary

Medcon Database is an internal contact-management system for medical professionals. Its core data is a doctor/contact record:

- first name
- last name
- email
- phone (recently added; the old Flutter model does not contain it)
- country
- speciality (the database and API use the British spelling `speciality`)
- uploader/owner user ID
- active or soft-deleted state
- created and updated timestamps

The current database contains approximately 38,137 active contacts. The application supports fuzzy search, country and speciality filters, editing, soft deletion and restoration, CSV import/export, an audit trail, and user/permission administration.

The new frontend is an authenticated internal dashboard, not a public marketing website.

## 2. Current systems

### Backend

- Laravel 9 / PHP
- MariaDB/MySQL database named `medcon_database`
- JWT access tokens using `tymon/jwt-auth`
- database-backed refresh tokens
- Laravel Scout + Typesense 30.2 for fuzzy/typo-tolerant search
- MariaDB `LIKE` search as automatic fallback if Typesense is unavailable
- Laravel Excel for CSV import/export behavior
- application timezone: `Asia/Dubai`

Local Laravel API base URL:

```text
http://127.0.0.1:8000/api
```

Current production Laravel API base URL:

```text
https://api-database.medcon.ae/api
```

The former `https://database.medcon.ae/backend/public/api` URL is retired and
must not be used by the frontend or deployment configuration.

Do not hardcode either URL. The Next.js server must read it from `LARAVEL_API_URL`.

### Existing Flutter frontend

The supplied `lib.zip` is a Flutter Web/GetX application with these routes:

- `/` — login
- `/home` — contact search/table, import, and desktop activity panel
- `/userActivity` — mobile activity view
- `/admin` — users and permissions

Existing features that must be preserved:

- login, logout, and session restoration
- fuzzy text search
- multi-select country and speciality filters
- paginated contact table
- edit contact
- soft delete and restore contact
- file picker and drag-and-drop CSV import
- filtered CSV export
- activity log with paging and date/user/contact filters
- list, create, edit, ban, and unban users
- responsive desktop/mobile layouts

The Flutter code refreshes the access token before almost every API call and stores the refresh token in browser-accessible shared preferences. Do not repeat this design in Next.js.

## 3. Recommended Next.js architecture

Use the current stable Next.js version with TypeScript and the App Router. Do not enable static export because authentication and the Backend-for-Frontend layer require a Next.js server runtime.

### Backend-for-Frontend (BFF)

The browser should communicate with Next.js endpoints, and Next.js should communicate with Laravel:

```text
Browser -> Next.js Route Handler / Server Function -> Laravel API -> MariaDB / Typesense
```

Use the BFF for these reasons:

- store access and refresh tokens in `HttpOnly`, `Secure`, `SameSite=Lax` cookies
- never expose the refresh token to client JavaScript or `localStorage`
- centralize refresh/retry logic
- normalize Laravel errors into one frontend error shape
- keep the Laravel origin and credentials server-side
- add CSRF protection for state-changing requests

Next.js officially supports the BFF pattern with Route Handlers and explicitly notes that public handlers still require authentication and authorization. See [Next.js Backend for Frontend](https://nextjs.org/docs/app/guides/backend-for-frontend) and [Next.js cookies](https://nextjs.org/docs/app/api-reference/functions/cookies).

Implementation rules:

1. Login calls Laravel `POST /login` on the server.
2. Next.js stores the access and refresh tokens only in HttpOnly cookies. Do not return them to the browser.
3. A server-only Laravel client attaches the access token.
4. On one `401`, it calls Laravel `POST /refresh`, updates the access cookie, and retries once.
5. If refresh fails, clear both cookies and redirect to `/login?reason=session-expired`.
6. Logout calls Laravel `POST /logout`, then always clears local cookies.
7. The Laravel refresh-token behavior must be fixed as described in section 13.

Server Components should fetch Laravel directly through the shared server-only client when rendering. Client-side interactive tables may call protected Next.js Route Handlers. This avoids unnecessary server-to-self HTTP calls while retaining the BFF for browser requests.

## 4. Recommended design system

### Primary choice

Use this stack:

- **shadcn/ui**, configured with a compact/dense visual style
- **Radix Primitives** as the accessible behavior layer
- **Tailwind CSS** and CSS custom-property design tokens
- **TanStack Table** with manual/server-side pagination
- **TanStack Query** for interactive server state, caching, invalidation, and mutations
- **React Hook Form + Zod** for forms and shared runtime validation
- **Lucide React** for icons
- **Sonner** for toasts
- **date-fns** for dates, always formatted in `Asia/Dubai`
- **next-intl** if Arabic is required; prepare RTL from the first layout even if version one ships in English

Why this is the best fit:

- shadcn provides accessible, open component code rather than locking the app into an opinionated packaged theme, so the Medcon identity can be applied cleanly. See [shadcn/ui introduction](https://ui.shadcn.com/docs).
- Radix implements focus management, keyboard navigation, and WAI-ARIA patterns for dialogs, menus, selects, and other complex controls. See [Radix accessibility](https://www.radix-ui.com/primitives/docs/overview/accessibility).
- TanStack Table supports controlled server pagination, filtering, sorting, column visibility, and virtualization without loading 38,000 records into the browser. See [TanStack Table features](https://tanstack.com/table/latest/docs/guide/features) and [manual pagination](https://tanstack.com/table/latest/docs/api/features/pagination).
- TanStack Query supports the Next.js App Router/Server Components hydration pattern and is appropriate for interactive search and mutations. See [TanStack Query advanced SSR](https://tanstack.com/query/latest/docs/framework/react/guides/advanced-ssr).
- shadcn's compact interface variants are particularly suitable for dense internal tools.

### Alternative

MUI + MUI X Data Grid is a reasonable alternative if the priority is the fastest possible table implementation with more behavior built in. The Community grid is MIT-licensed and free, but advanced features such as multi-filtering, column pinning, and some export/grouping features require Pro or Premium licenses. See [MUI X licensing](https://mui.com/x/introduction/licensing/). Do not mix MUI and shadcn in the same application.

### Visual direction and tokens

Preserve the existing Medcon colors, but modernize their use:

```css
--brand-primary: #1aabe3;   /* existing Flutter primary */
--brand-navy: #030372;      /* existing Flutter secondary */
--background: #f6f8fb;
--surface: #ffffff;
--foreground: #111827;
--muted: #64748b;
--border: #dbe3ec;
--success: #15803d;
--warning: #b45309;
--danger: #b91c1c;
```

Use navy for navigation and high-emphasis headings, blue for the main action/focus state, and semantic colors only for statuses. Never rely on color alone to communicate deleted, banned, or permission states.

Suggested typography:

- English: Inter
- Arabic: IBM Plex Sans Arabic or Noto Sans Arabic
- minimum body size: 14px for dense tables, 16px elsewhere
- tabular numerals for phone numbers, row counts, and dates

Layout rules:

- desktop sidebar width: 240–264px; collapsible at tablet size
- content max width: none for the contact table; it needs the viewport
- 8px spacing system
- 40px default control height, 36px compact table controls
- table header remains sticky
- row actions use a menu plus one obvious primary action, not many icons with no labels
- dialogs for focused edits; a right-side sheet for contact detail/history
- destructive actions require a confirmation dialog naming the contact
- meet WCAG 2.2 AA contrast and keyboard behavior

## 5. Information architecture

Recommended App Router structure:

```text
app/
  [locale]/
    (auth)/
      login/page.tsx
    (dashboard)/
      layout.tsx
      contacts/page.tsx
      contacts/[id]/page.tsx
      imports/page.tsx
      activity/page.tsx
      users/page.tsx
      settings/page.tsx
    api/
      auth/login/route.ts
      auth/logout/route.ts
      backend/[...path]/route.ts
components/
  ui/                 shadcn components
  layout/             sidebar, header, breadcrumbs
  contacts/           table, filters, forms, detail sheet
  imports/            dropzone, preview, import result
  activity/           timeline, diff, filters
  users/              table and forms
features/              domain queries, mutations, schemas
lib/
  api/                 server-only Laravel client and typed errors
  auth/                session, cookies, authorization helpers
  permissions/         capability map
  types/
  utils/
messages/              en.json and ar.json
```

Recommended navigation:

- Contacts — all users
- Import — users with `contacts.import`
- Activity — users with activity access
- Users — administrators only
- Settings/Profile — all users

Do not create a separate dashboard page unless it contains useful operational information. After login, route directly to `/contacts`.

## 6. Core TypeScript domain types

Use these as the starting contract. Validate external API data with Zod; TypeScript types alone do not validate runtime data.

```ts
export type AccessType = 'R' | 'RW' | 'RWE' | 'RWED' | 'FULL'

export interface User {
  id: number
  name: string
  email: string
  is_banned: boolean
  access_type: AccessType
  created_at: string
  updated_at: string
}

export interface Contact {
  id: number
  firstname: string | null
  lastname: string | null
  email: string
  phone: string | null
  country: string | null
  speciality: string | null
  user_id: number
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export type ActivityStatus = 'update' | 'delete' | 'restore'

export interface ContactSnapshot {
  id: number
  firstname: string | null
  lastname: string | null
  email: string
  phone: string | null
  country: string | null
  speciality: string | null
  user_id: number
  created_at: string
  updated_at: string
}

export interface Activity {
  id: number
  user: {
    user_id: number
    user_name: string
    user_email: string
  } | null
  data_id: number
  status: ActivityStatus
  created_at: string
  updated_at: string
  csv_data: {
    from: ContactSnapshot | null
    to: ContactSnapshot | null
  }
}

export interface ApiEnvelope<T> {
  data: T
  status_code: number
  message: string
  status: boolean
}

export interface PaginatedEnvelope<T> extends ApiEnvelope<T[]> {
  perPage: number
  currentPage: number
  total: number
  totalPages: number
  is_next: boolean
}
```

The Laravel API is not perfectly consistent. Error responses may omit `data`, and file downloads do not use the JSON envelope. The BFF should normalize errors to:

```ts
interface AppError {
  code: string
  message: string
  fieldErrors?: Record<string, string[]>
  status: number
}
```

## 7. Permissions

Translate legacy access types to named capabilities in one shared frontend module. Do not scatter checks such as `access_type === 'RWED'` through components.

| Access | Meaning | Capabilities |
|---|---|---|
| `R` | Read only | view/search contacts |
| `RW` | Read + write | read + import/export |
| `RWE` | Read + write + edit | RW + update contacts + activity view |
| `RWED` | Read + write + edit + delete | RWE + delete/restore |
| `FULL` | Administrator | all capabilities + user administration |

Suggested capability names:

```ts
type Capability =
  | 'contacts.read'
  | 'contacts.import'
  | 'contacts.export'
  | 'contacts.edit'
  | 'contacts.delete'
  | 'contacts.restore'
  | 'activity.read'
  | 'activity.readAll'
  | 'users.manage'
```

Frontend permission checks improve the user experience only. Laravel policies/middleware must independently enforce the same matrix.

## 8. Laravel API contract (current implementation)

All protected endpoints require:

```http
Authorization: Bearer <access-token>
Accept: application/json
```

Laravel validation failures normally return HTTP 422 with `message` and `errors`, which differs from the application's normal envelope.

### Authentication

#### `POST /login` — public

Request:

```json
{ "email": "user@example.com", "password": "secret" }
```

Success `200`:

```json
{
  "data": {
    "user": { "id": 1, "name": "...", "email": "...", "is_banned": false, "access_type": "FULL" },
    "access_token": "...",
    "refresh_token": "...",
    "expires_in": 3600
  },
  "status_code": 200,
  "message": "Login successful",
  "status": true
}
```

Errors: `401` invalid credentials, `403` banned.

#### `POST /refresh` — public

Request:

```json
{ "refresh_token": "..." }
```

Returns a new access token and the same refresh token. Current refresh tokens expire after 60 minutes.

#### `POST /logout` — authenticated

Invalidates the current JWT access token. Currently it does not revoke the refresh token; this must be fixed.

#### `GET /get-user` — authenticated

Returns the authenticated `User` in `data`. The current response also includes `password: ""`; ignore it in the frontend and remove it from Laravel.

### Contacts and search

#### `POST /query-row` — authenticated

This is the main contact list/search endpoint.

Request:

```json
{
  "text": "Ali cardiologist",
  "countries": ["United Arab Emirates", "Jordan"],
  "specialties": ["Cardiology"],
  "page": 1,
  "perPage": 30
}
```

Validation:

- `text`: optional string, maximum 150
- `countries`: optional array
- `specialties`: optional array (request spelling is `specialties`)
- `page`: integer >= 1
- `perPage`: 1–100, default 30

Success returns `PaginatedEnvelope<Contact>`.

Behavior:

- returns active and soft-deleted contacts
- non-empty text normally uses Typesense fuzzy search
- text searches first name, last name, speciality, email, phone, and country
- country and speciality are exact facet filters
- blank filter value `""` represents rows containing null/empty values and forces MariaDB fallback
- without search text, results are newest first
- a deleted row is detected by non-null `deleted_at`

Next.js search behavior:

- debounce input by 250–350ms
- do not send queries for every keystroke immediately
- keep `q`, `country`, `speciality`, `page`, and `perPage` in URL search parameters
- reset to page 1 when the query or filter changes
- cancel obsolete requests
- show a skeleton only on first load; retain the current table during subsequent fetching
- render “No matches” separately from a network/server error
- highlight matched text when available; do not invent highlight metadata if Laravel does not send it

#### `POST /update-row` — RWE, RWED, FULL

Request:

```json
{
  "id": 123,
  "firstname": "Ali",
  "lastname": "Ahmad",
  "email": "ali@example.com",
  "phone": "+971501234567",
  "country": "United Arab Emirates",
  "speciality": "Cardiology"
}
```

All fields other than `id` are optional in the current API. The UI should submit a validated complete form. Email must be valid; phone max length is 32. Success returns the updated contact. A history snapshot and `update` activity are created.

#### `POST /delete-row` — RWED, FULL

```json
{ "id": 123 }
```

Soft-deletes the contact and creates history/activity records. Ask for confirmation in the UI.

#### `POST /undelete` — RWED, FULL

Request with either ID or email:

```json
{ "id": 123 }
```

Restores only a soft-deleted contact and creates history/activity records.

#### `POST /get_data` — authenticated, legacy

```json
{ "user_id": 1 }
```

Returns all active contacts originally uploaded by a user, without pagination. Do not use it in the first Next.js version; it is unbounded and its authorization is insufficient.

### Filter options

#### `GET /countries` — authenticated

Returns an envelope whose `data` is an array of distinct active country strings, possibly including `null` or `""`.

#### `GET /specialties` — authenticated

Returns an envelope whose `data` is an array of distinct active speciality strings, possibly including `null` or `""`.

The frontend should display blank values as “Not specified,” sort options using the active locale, and never mutate the database value just for display.

Both endpoints now normalize historical aliases before returning them. For
example, `UAE`, `Dubai`, and `Abu Dhabi` are returned once as
`United Arab Emirates`; `GP` and `General Practice` are returned once as
`General Practitioner`. When a canonical option is sent back to search or
export, Laravel expands it to all matching raw aliases, so old records remain
filterable without rewriting their audit history. The editable mapping is in
`config/reference_data.php`.

Specialities use a strict 44-option medical taxonomy. Known aliases and typos
are mapped to their canonical discipline, unclassified legacy text is grouped
under `Other`, and missing/placeholder values are represented once by the empty
value (display it as `Not specified`).

### CSV import/export

#### `POST /upload-csv` — intended RW, RWE, RWED, FULL

Content type: `multipart/form-data`; field name: `file`.

Allowed extension/MIME behavior: CSV or text. Header comparison is case-insensitive and whitespace-trimmed. Columns may be in any order.

Required columns:

```text
FIRST NAME,LAST NAME,EMAIL,COUNTRY,SPECIALITY
```

Optional column:

```text
PHONE
```

No unknown columns are currently accepted. Email is the upsert key:

- a row with a new email creates a contact
- a row whose email already exists updates only its non-empty first name, last
  name, phone, country, and speciality cells
- an empty cell never erases the existing database value
- updates create the same history snapshot and `update` activity as manual edits
- a matching row with no actual changes is counted as unchanged
- a row with no email is skipped
- matching soft-deleted contacts are updated but remain soft-deleted

Success:

```json
{
  "data": {
    "rows_added": 125,
    "rows_updated": 40,
    "rows_unchanged": 7,
    "rows_skipped": 2
  },
  "status_code": 200,
  "message": "CSV data uploaded successfully.",
  "status": true
}
```

The import page should provide:

- drag/drop and file picker
- a downloadable template generated by the frontend
- visible header requirements
- selected file name and size
- upload progress/pending state
- result summary
- protection from double submit

The current API cannot provide a row-by-row preview or rejected-row report. Add the recommended import-preview API in section 14 before building an advanced import wizard.

Known inconsistency: Laravel currently excludes `RWE` from upload even though the permission definition says RWE includes write. Fix Laravel rather than reproducing the bug.

#### `POST /export-data` — authenticated in current code

Request:

```json
{
  "text": "Ali",
  "countries": ["Jordan"],
  "specialities": ["Cardiology"]
}
```

Note that export uses `specialities`, while search uses `specialties`. Response is a CSV file attachment named similar to `csv_data_20260713_120000.csv`, with columns:

```text
FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY
```

The Next.js proxy must stream the response and forward `Content-Type` and `Content-Disposition`. Do not parse it as JSON. Export should use exactly the currently visible text/filters.

### Activity/audit log

Activity states:

- `update`
- `delete`
- `restore`

#### `GET /user-activity?page=1&perPage=10`

Returns all activities for FULL users and the authenticated user's own activities for other users.

#### `POST /user-activity-dates`

```json
{
  "start_date": "2026-07-01",
  "end_date": "2026-07-13",
  "page": 1,
  "perPage": 10
}
```

Dates must be `YYYY-MM-DD` and are interpreted as a full-day range.

#### `GET /data-activity/{data_id}?page=1&perPage=10`

Returns activity for one contact.

#### `GET /emp-activity/{user_id}?page=1&perPage=10` — FULL

Returns activity performed by one employee.

All activity endpoints use the paginated activity envelope. `csv_data.from` is the historical snapshot and `csv_data.to` is the inferred next/current value.

Activity UI requirements:

- full-page timeline/table rather than the old narrow side panel
- status badge and icon
- actor name/email
- Dubai date/time
- contact identity and link to its detail
- human-readable field diff: only show values that changed
- filters: date range, action, employee (admin only), and contact
- deleted records remain inspectable
- loading, empty, and error states

### User administration

#### `GET /get-users` — FULL

Returns `ApiEnvelope<User[]>`.

#### `POST /user` — FULL

```json
{
  "name": "Employee Name",
  "email": "employee@example.com",
  "access_type": "RWE"
}
```

Creates a user without accepting a password. The backend assigns an unusable random password and emails the new user a one-time link to the Laravel password setup page. The response keeps the user fields at the top level for compatibility and adds:

```json
{
  "setup_email_sent": true
}
```

If this value is `false`, the user was still created. Show an admin action to resend the invitation.

#### `POST /users/{id}/resend-password-setup` — FULL

Resends a fresh one-time password setup link. This endpoint is limited to five requests per minute. A successful response is:

```json
{
  "data": { "setup_email_sent": true },
  "status_code": 200,
  "message": "Password setup email sent successfully.",
  "status": true
}
```

The email link opens Laravel at `GET /set-password/{token}?email=...`. The user chooses a password of at least 8 characters containing uppercase and lowercase letters and a number. The token expires after 60 minutes and is single-use. After success, Laravel redirects to `FRONTEND_LOGIN_URL?password_set=1`; the Next.js login page should show an account-ready success message when that query parameter is present.

Completing account setup also sets `email_verified_at` and enables `receive_notification`. Only verified, non-banned FULL administrators with `receive_notification: true` receive operational activity emails. Account settings should expose an administrator-only toggle using:

```http
PATCH /user/notification-preference
```

```json
{ "receive_notification": false }
```

#### `POST /users/{id}/ban` — FULL

```json
{ "is_banned": true }
```

Banning removes that user's stored refresh tokens.

#### `POST /user/update-profile/{id}` — should be self or FULL

```json
{ "name": "New Name", "email": "new@example.com" }
```

#### `POST /user/update-password/{id}` — should be self or FULL

```json
{ "password": "new secure password" }
```

#### `POST /user/update-name/{id}` — should be FULL when changing access

```json
{ "name": "New Name", "access_type": "RWED" }
```

The user page needs a table with name, email, status, role/access label, created date, and actions. The create dialog must not contain a password field. Use dialogs for create/edit, a switch with confirmation for ban/unban, a resend-invitation action, and plain-language permission descriptions. Prevent an admin from accidentally banning or demoting their own last FULL account.

### Endpoints not to use

- `POST /test-email` — protected FULL-only diagnostic endpoint; do not expose in the normal UI
- `GET /test-timezone` — public debug endpoint; remove
- Sanctum `GET /user` — unused; remove if Sanctum is not adopted

## 9. Search system

Typesense is already integrated and all current contacts have been indexed.

Indexed fields:

- `id`
- `firstname`
- `lastname`
- `email`
- `phone`
- `country`
- `speciality`
- `user_id`
- `created_at`
- `__soft_deleted`

Search weighting:

```text
firstname 10
lastname 10
speciality 8
email 6
phone 5
country 3
```

One typo is allowed per searched field, and exact matches are prioritized. This addresses cases such as searching for “Ali” when a contact is stored as “Alo,” especially when the last name or speciality also agrees.

Country and speciality are facets/filters. Soft-deleted rows stay searchable so authorized users can restore them. An Eloquent observer keeps the Typesense document synchronized after create, update, delete, and restore. Observer failures do not block MariaDB writes; use the repair command after an outage:

```bash
php artisan scout:import 'App\Models\CSVData'
```

Start the local search service with:

```bash
./scripts/start-typesense.sh
```

See `docs/typesense.md` in the Laravel repository for local setup and operations.

This is fuzzy search, not a trained AI model. It is the correct first solution for misspelled names. AI-based normalization of countries and specialities should be a separate reviewed ingestion workflow; it must not silently rewrite production data.

## 10. Screen specifications

### Login

- Medcon logo, email, password, show/hide password, submit
- clear invalid-credentials and banned-account messages
- loading state and double-submit prevention
- redirect authenticated users to `/contacts`
- redirect back to the originally requested protected page after login
- no public registration
- a “Forgot password?” link to a public email form using `POST /forgot-password`
- success alerts for `password_set=1` and `password_reset=1` after Laravel redirects back

### Contacts

Header:

- page title and total result count
- global fuzzy search
- country multi-select
- speciality multi-select
- “More filters” only when real extra filters exist
- clear-all filters
- Import and Export actions according to permission

Table columns:

- Name (first + last)
- Speciality
- Country
- Email
- Phone
- Status (Active/Deleted)
- Updated
- Actions

Behavior:

- server-side pagination; default 30, options 30/50/100
- horizontal scrolling only as a last resort
- email uses `mailto:`, phone uses `tel:` after safe formatting
- preserve original phone string; do not assume every number is UAE
- deleted rows use a status badge and subtle tinted background, not reduced readability
- row opens a detail sheet; edit/delete/restore depend on permission
- optimistic UI may be used for edit/status only if rollback and error messaging are correct
- invalidate contacts, facets, and activity queries after relevant mutations

Mobile:

- replace the wide table with compact contact cards or a deliberately reduced table
- display name, speciality, country, phone/email, status, and an action menu
- filters open in a bottom sheet

### Contact detail

- contact fields and status
- created/updated dates
- uploader ID/name when available
- Edit button
- Delete or Restore button
- Activity tab/timeline for this contact

### Import

- requirements/template panel
- dropzone
- confirmation and result summary
- no activity timeline squeezed beside the importer

### Activity

- filter bar and paginated timeline/table
- semantic diff with From/To values
- link back to the contact
- admin employee filter

### Users

- FULL only
- list and status/role filters
- create/edit user dialogs
- password update separated from profile edit
- ban/unban confirmation

### Profile/settings

- self name/email edit
- separate password form requiring current password after backend support is added
- active access level shown read-only
- logout

## 11. Data validation and normalization

Frontend validation improves feedback; Laravel remains authoritative.

Contact form:

- email required and normalized by trimming whitespace and lowercasing the domain
- first and last name trimmed; allow Unicode and accents
- phone optional, maximum 32; preserve leading `+`; do not convert to a number type
- country optional
- speciality optional
- show duplicate email (`409`) next to the email field

Normalization roadmap:

1. Create canonical `countries` and `specialties` tables with aliases.
2. During import, normalize whitespace/case and match aliases deterministically.
3. Put unresolved values into a review queue.
4. Optionally let an AI model suggest a canonical value with a confidence score.
5. A user approves low-confidence changes; never silently overwrite uncertain data.
6. Keep original input and normalization audit metadata.

Examples:

- `UAE`, `U.A.E.`, `United Arab Emirates` -> `United Arab Emirates`
- `Cardio`, `Cardiology`, `cardiologist` -> a reviewed canonical speciality

AI is useful for suggestions and alias discovery. Database constraints and canonical IDs must remain the source of truth.

## 12. Database model

### `users`

- `id`
- `name`
- `email` unique
- `password` hash
- `is_banned` boolean
- `access_type`: R/RW/RWE/RWED/FULL
- timestamps

### `csv_data`

- `id`
- `firstname`
- `lastname`
- `email` unique
- `phone` nullable varchar(32)
- `country` nullable
- `speciality` nullable
- `user_id` foreign key to users
- timestamps
- `deleted_at` for soft deletion

### `history_editing`

Stores a contact snapshot: first name, last name, email, phone, country, speciality, acting user ID, contact/data ID, and timestamps.

### `user_activity`

- actor `user_id`
- contact `data_id`
- `history_editing_id`
- status update/delete/restore
- timestamps

### `refresh_tokens`

- user ID
- token
- expiry

Current tokens are stored in plaintext; hash them as part of the auth repair.

## 13. Required backend fixes before production

### Critical

1. Add authorization middleware/policies consistently. User creation is protected, but some profile, password, name, access, and data routes still rely on controller checks or need stricter ownership rules.
2. `POST /user` is restricted to FULL; preserve this rule when introducing policies.
3. Restrict profile/password updates to the same user or FULL; restrict role changes to FULL.
4. SMTP configuration now uses environment variables. Rotate any credentials that were previously present in source control.
5. `test-email` is protected and rate-limited; remove/protect the remaining public `test-timezone` route.
6. Re-check `is_banned` during token refresh.
7. Revoke the refresh token on logout; rotate refresh tokens when used; store only a hash; use a substantially longer intended refresh lifetime than the access token.
8. Add login/refresh rate limits.
9. Keep invitation and reset forms aligned with the backend policy: at least 8 characters with uppercase, lowercase, and a number. Require the current password for self-service password changes.

### High priority correctness

1. Return `403`, not `404`/`400`, for permission denial.
2. Fix upload permission to include RWE.
3. Define and enforce export permission; the check is currently commented out.
4. Fix `updateProfile` unique-email validation to ignore only the current user's ID.
5. Check duplicate contact emails against active and soft-deleted rows consistently.
6. Wrap delete and restore history/activity/contact changes in database transactions.
7. Remove `password: ""` from `GET /get-user`.
8. Stop identifying admins by the literal name/email in date activity; use `access_type === FULL`.
9. Add authorization to contact activity and `get_data` access.
10. Delete notification wording has been corrected; keep a regression test around notification content when the mail system is moved to Mailables.
11. Add RWE to the login email's access-label map.

### Performance and maintainability

1. Remove N+1 history queries from activity endpoints.
2. Extract the duplicated activity formatter into an API Resource/service.
3. Use Form Request validation and policies instead of repeated controller checks.
4. Standardize the JSON envelope and Laravel validation errors.
5. Paginate or remove `get_data`.
6. Queue email notifications and large imports/exports.
7. Import CSV in validated chunks/batches instead of one insert/check per row.
8. Add automated feature tests for the complete permission matrix and search fallback.

## 14. Recommended API v2 improvements

Do not block the first UI on all of these, but design toward a conventional `/api/v2` contract:

```text
POST   /auth/login
POST   /auth/refresh
POST   /auth/logout
GET    /me
PATCH  /me
PATCH  /me/password

GET    /contacts?q=&country[]=&speciality[]=&status=&page=&per_page=
GET    /contacts/{id}
PATCH  /contacts/{id}
DELETE /contacts/{id}
POST   /contacts/{id}/restore
GET    /contacts/{id}/activities

GET    /facets/countries
GET    /facets/specialities

POST   /imports/preview
POST   /imports
GET    /imports/{id}
GET    /imports/{id}/errors.csv
POST   /exports
GET    /exports/{id}

GET    /activities
GET    /users
POST   /users
PATCH  /users/{id}
PATCH  /users/{id}/status
```

Specific improvements:

- use GET for idempotent search/list operations
- use `PATCH` for partial updates and `DELETE` for deletion
- use one spelling externally: `speciality` or `specialty`; document it and keep it consistent
- return filter facets with value and count
- return explicit `status: active|deleted`
- support activity filtering in one endpoint
- return an import preview with accepted, duplicate, invalid, and unresolved-normalization counts
- run large imports/exports as queued jobs and poll job status
- publish an OpenAPI 3.1 schema and generate the frontend API types/client
- include stable error codes, not only human messages

## 15. State, caching, and forms

Use Server Components for the initial authenticated shell/current user and TanStack Query for interactive lists and mutations.

Suggested query keys:

```ts
['me']
['contacts', { q, countries, specialities, page, perPage }]
['contact', id]
['contact-activity', id, filters]
['activity', filters, page]
['countries']
['specialities']
['users']
```

Caching guidance:

- current user: 5 minutes, invalidate on profile change
- facets: 5–15 minutes, invalidate after successful import/edit
- search results: 30–60 seconds with previous data retained
- activity: short cache; invalidate after edit/delete/restore
- users: invalidate after any user mutation
- never cache authenticated responses publicly

Forms:

- one Zod schema per request type
- map Laravel 422 errors to field errors
- disable submit while pending
- keep dialog open on recoverable errors
- show success toast and update/invalidate data only after success
- use an alert dialog for destructive actions

## 16. Error, loading, and empty states

Every data area must distinguish:

- initial loading: skeleton matching the final layout
- background refresh: subtle progress without replacing content
- empty database/list: explain the next action
- no search result: preserve filters and offer Clear filters
- authorization failure: show a clear 403 page/message
- expired session: redirect to login and explain why
- backend unavailable: retry action and non-technical message
- Typesense unavailable: normally invisible because Laravel falls back to MariaDB
- validation failure: inline field feedback

Do not show raw PHP exception text to users.

## 17. Security checklist for Next.js

- tokens only in HttpOnly Secure cookies
- CSRF protection for mutations
- no secrets in variables prefixed `NEXT_PUBLIC_`
- validate every Route Handler input with Zod and set upload size limits
- authorization in Laravel, with matching UI capability checks
- safe response headers: CSP, frame restrictions, no sniffing, referrer policy
- no sensitive request/response body logging
- sanitize CSV cells that begin with `=`, `+`, `-`, or `@` to prevent spreadsheet formula injection
- do not render user data as HTML
- rate limit auth and expensive export/import endpoints
- record administrative changes in the audit log
- tests must verify that each legacy access type cannot exceed its capability set

## 18. Testing and acceptance criteria

### Automated tests

- Vitest for schemas, permission mapping, API client, and formatters
- React Testing Library for forms, dialogs, filters, and permission visibility
- Playwright for end-to-end login, search, edit, delete/restore, import, export, activity, and admin
- Laravel feature tests for endpoint authorization and validation
- test Typesense success and MariaDB fallback paths

### Definition of done

- all current Flutter features listed in section 2 exist in Next.js
- phone is visible/searchable/editable/importable/exportable/audited
- query/filter/page state survives refresh and browser back/forward
- typo search finds expected close-name matches
- 38,137 contacts are never loaded into the browser at once
- all screens work at desktop, tablet, and mobile widths
- keyboard-only operation works for search, filters, table actions, dialogs, and pagination
- no access or refresh token is accessible to client JavaScript
- permission matrix is enforced by Laravel tests, not only hidden UI
- deleted contacts are visibly distinct and restorable only by authorized users
- export receives the same filters shown in the UI
- all dates are clearly presented in Dubai time
- English and Arabic layouts are RTL/LTR safe if both languages are enabled
- no public debug endpoint or hardcoded SMTP credential remains

## 19. Suggested build phases

1. Backend security repair and API contract tests.
2. Next.js foundation: App Router, shadcn theme/tokens, locale-ready shell, BFF auth.
3. Contacts read path: fuzzy search, facets, URL state, server pagination, responsive table/cards.
4. Contact mutations: edit, delete, restore, detail sheet, activity invalidation.
5. CSV import/export.
6. Activity page and field-diff presentation.
7. User administration and profile.
8. Accessibility, E2E testing, performance, security headers, deployment.
9. Later: canonical country/speciality normalization and AI-assisted review queue.

## 20. Copy-ready prompt for the new Next.js project

Copy the block below into the new project conversation and attach this full document as well:

```text
Build a production-quality Next.js replacement for the Medcon Flutter Web contact database.

Read and follow the attached “Medcon Database — Next.js Frontend Handoff” completely before changing files. Treat it as the product and backend source of truth. Inspect the new repository first and preserve unrelated work.

Use the current stable Next.js App Router with TypeScript. Use shadcn/ui with a compact/dense Medcon design system, Radix primitives, Tailwind CSS, TanStack Table with server-side pagination, TanStack Query, React Hook Form + Zod, Lucide, Sonner, and date-fns. Prepare the layout for English and Arabic/RTL using next-intl. Use the Medcon colors #1aabe3 and #030372 through semantic CSS tokens; do not scatter hex colors through components.

Laravel remains the backend. Set its server-only base URL through LARAVEL_API_URL. Implement a Next.js Backend-for-Frontend layer: access and refresh tokens must live only in HttpOnly Secure SameSite cookies, never localStorage or browser JavaScript. Implement one automatic refresh-and-retry after a 401, session-expired handling, CSRF protection, normalized errors, and streamed CSV downloads. Never rely on frontend button hiding for authorization.

Implement in phases and verify each phase:
1. authenticated application shell and BFF login/logout/session handling
2. responsive Contacts page with fuzzy search, country/speciality multi-filters, URL state, server-side pagination, Active/Deleted state, phone, email, and permission-based actions
3. contact detail, edit, soft delete, restore, and contact activity
4. CSV import and filtered CSV export
5. Activity page with date/contact/employee filters and readable field diffs
6. FULL-only user administration with create/edit/access/ban actions
7. profile and password screens
8. Vitest/RTL unit tests and Playwright critical flows

Do not load all contacts client-side. Do not omit phone. Do not invent backend endpoints silently: if the handoff marks a needed API improvement, explain the dependency and either use the documented current endpoint or implement the Laravel change only when that backend is also in scope. Start by producing a short implementation plan and then execute it, not just a mockup.
```

## 21. Repository references

Important backend files:

- `routes/api.php` — endpoint registration
- `app/Http/Controllers/AuthController.php` — login, refresh, logout, user mutation
- `app/Http/Controllers/CSVController.php` — contacts, search, import, export
- `app/Http/Controllers/UserController.php` — activity, facets, users
- `app/Models/CSVData.php` — contact model and Typesense search settings
- `config/scout.php` — Typesense schema
- `app/Observers/CSVDataSearchObserver.php` — search synchronization
- `database/migrations/2026_07_13_000000_add_phone_to_contact_tables.php` — phone migration
- `docs/typesense.md` — search operations
- `scripts/start-typesense.sh` — local Typesense startup
