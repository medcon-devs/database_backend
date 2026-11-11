# Microsoft Entra ID authentication

Medcon uses a single-tenant OpenID Connect authorization-code flow with PKCE.
Microsoft authenticates the employee and applies the organization's MFA and
Conditional Access policies. Laravel remains responsible for provisioning,
account suspension, Medcon access levels, activity logs, and local sessions.

## Configuration

Never place the client secret in frontend code, `NEXT_PUBLIC_*`, source
control, screenshots, or chat.

```env
MICROSOFT_AUTH_ENABLED=true
MICROSOFT_TENANT_ID=
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI=http://localhost:8000/api/auth/microsoft/callback
MICROSOFT_FRONTEND_URL=http://localhost:3000
```

The App Registration platform must be **Web**, single tenant, and its redirect
URI must exactly match `MICROSOFT_REDIRECT_URI`.

Local:

```text
http://localhost:8000/api/auth/microsoft/callback
```

Production:

```text
https://api-database.medcon.ae/api/auth/microsoft/callback
```

Use separate development and production App Registrations before production
rollout.

## Endpoints

### Public configuration

```http
GET /api/auth/microsoft/config
```

Returns only whether Microsoft authentication is enabled and the backend login
URL. It never returns tenant credentials.

### Start sign-in

The browser must navigate directly to:

```http
GET /api/auth/microsoft/redirect
```

Do not fetch this endpoint with XHR. It creates a short-lived, HttpOnly,
SameSite=Lax state cookie and redirects the browser to Microsoft.

### Callback

```http
GET /api/auth/microsoft/callback
```

This route is called by Microsoft. Laravel verifies state, nonce, PKCE, token
signature, timestamps, audience, issuer, and Medcon tenant. It links only an
existing Medcon user and never auto-creates an employee.

On success, Laravel redirects to:

```text
{MICROSOFT_FRONTEND_URL}/auth/microsoft/callback?code={one-time-code}
```

On failure:

```text
{MICROSOFT_FRONTEND_URL}/auth/microsoft/callback?error={safe-error-code}
```

### Exchange the one-time code

```http
POST /api/auth/microsoft/exchange
Content-Type: application/json

{
  "code": "one-time-code-from-callback"
}
```

The code expires after 90 seconds and can be used only once. A successful
response has the same Medcon access token, refresh token, expiration, and user
shape as password login, plus:

```json
{
  "authentication_provider": "microsoft"
}
```

The frontend must exchange the code immediately, remove it from browser
history with `history.replaceState`, and never log it.

## User matching and authorization

On first Microsoft sign-in Laravel matches the verified tenant email to an
existing `users.email`, then stores the stable Microsoft `tid + oid` identity.
Later sign-ins use that stable identity. Conflicting identities are rejected.

Laravel always enforces:

- The configured Medcon tenant.
- Existing Medcon user provisioning.
- `is_banned = false`.
- The existing `R`, `RW`, `RWE`, `RWED`, or `FULL` access level.

Password login remains available during the migration period. It can be
disabled for Microsoft-linked employees only after the rollout is verified.

## Deployment

```bash
php ../composer.phar install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Configure the production values only in the server `.env`.

## MFA

The application does not implement its own second-factor prompt. Configure MFA
or a phishing-resistant authentication strength in Microsoft Entra
Conditional Access for the Medcon Enterprise Application. Start the policy in
report-only mode, test selected users, and exclude emergency access accounts
before enabling it for everyone.
