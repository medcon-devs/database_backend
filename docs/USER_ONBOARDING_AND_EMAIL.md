# User onboarding and email configuration

## Account creation flow

1. A FULL administrator calls `POST /api/user` with `name`, `email`, and optional `access_type`.
2. The API creates the account without accepting or returning a password.
3. Laravel creates a single-use password reset token and emails the user a welcome invitation.
4. The email button opens Laravel's `/set-password/{token}` page.
5. The user sets a password of at least 8 characters with uppercase, lowercase, and numeric characters.
6. Laravel consumes the token, invalidates existing refresh tokens, and redirects to `FRONTEND_LOGIN_URL?password_set=1`.

The invitation expires after 60 minutes. A FULL administrator can call `POST /api/users/{user}/resend-password-setup` to issue a new link.

Completing the invitation also sets `email_verified_at` and changes `receive_notification` from `false` to `true`. For a FULL administrator, Laravel sends a confirmation explaining that administrator activity emails are enabled and can be disabled from account settings.

Authenticated users can update the preference with:

```http
PATCH /api/user/notification-preference
Authorization: Bearer <access-token>
Content-Type: application/json
```

```json
{ "receive_notification": false }
```

## Forgot password flow

The public `POST /api/forgot-password` endpoint accepts an `email`. It always returns the same HTTP 200 response for valid email syntax, regardless of whether an account exists, so callers cannot enumerate registered users. It is rate-limited to five requests per minute.

For existing accounts, Laravel sends the professional password-reset email. Its single-use link opens the Laravel password page and expires after 60 minutes. After a successful reset, Laravel invalidates existing refresh tokens and redirects to `FRONTEND_LOGIN_URL?password_reset=1`.

## Required environment values

```dotenv
APP_URL=https://api-database.medcon.ae
FRONTEND_LOGIN_URL=https://database.medcon.ae/login

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Medcon Database"
MAIL_SUPPORT_ADDRESS=support@example.com
MAIL_FOOTER_CONTACT_ADDRESS=majd.a@medcon-me.com
MAIL_APPLICATION_URL=https://database.medcon.ae
```

`APP_URL` must be the public Laravel URL because it is used to generate the button link. `FRONTEND_LOGIN_URL` must point to the actual Next.js login page. After changing environment values, run:

```bash
php artisan config:clear
```

## Email behavior

- The welcome email goes directly to the new user.
- Forgot-password emails go directly to the account owner.
- Operational emails such as login, upload, export, update, restore, delete, and user changes go separately to every user who is `FULL`, verified, not banned, and has `receive_notification = true`.
- Recipient addresses are never exposed to one another; Laravel sends an individual message to each eligible administrator.
- Replies go to `MAIL_SUPPORT_ADDRESS`.
- The footer support hyperlink goes to `MAIL_FOOTER_CONTACT_ADDRESS` and can differ from the SMTP sender/reply-to address.
- All messages use the shared professional layout at `resources/views/emails/layouts/medcon.blade.php`.
- `POST /api/test-email` sends a diagnostic message to the authenticated FULL administrator and is rate-limited.
