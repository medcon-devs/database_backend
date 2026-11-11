<?php

namespace App\Services;

use App\Exceptions\MicrosoftAuthException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MicrosoftOpenIdService
{
    public function beginAuthorization(): array
    {
        $this->assertConfigured();

        $state = $this->randomToken(32);
        $nonce = $this->randomToken(32);
        $verifier = $this->randomToken(64);
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));

        Cache::put($this->stateKey($state), [
            'nonce' => $nonce,
            'code_verifier' => $verifier,
        ], now()->addMinutes((int) config('services.microsoft.state_ttl_minutes', 10)));

        $query = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.microsoft.redirect_uri'),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => $this->authority().'/oauth2/v2.0/authorize?'.$query,
            'state' => $state,
        ];
    }

    public function completeAuthorization(string $code, string $state, ?string $cookieState): array
    {
        $this->assertConfigured();

        if (
            ! is_string($cookieState)
            || $cookieState === ''
            || ! hash_equals($cookieState, $state)
        ) {
            throw new MicrosoftAuthException(
                'The Microsoft sign-in request could not be verified.',
                'MICROSOFT_STATE_MISMATCH'
            );
        }

        $storedState = Cache::pull($this->stateKey($state));
        if (
            ! is_array($storedState)
            || ! filled($storedState['nonce'] ?? null)
            || ! filled($storedState['code_verifier'] ?? null)
        ) {
            throw new MicrosoftAuthException(
                'The Microsoft sign-in request expired. Please try again.',
                'MICROSOFT_STATE_EXPIRED'
            );
        }

        $metadata = $this->metadata();
        $tokens = $this->exchangeCode(
            $metadata['token_endpoint'],
            $code,
            $storedState['code_verifier']
        );
        $idToken = $tokens['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new MicrosoftAuthException(
                'Microsoft did not return an identity token.',
                'MICROSOFT_ID_TOKEN_MISSING'
            );
        }

        return $this->verifyIdToken($idToken, $storedState['nonce'], $metadata);
    }

    private function exchangeCode(string $tokenEndpoint, string $code, string $verifier): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('services.microsoft.http_timeout_seconds', 12))
                ->post($tokenEndpoint, [
                    'client_id' => config('services.microsoft.client_id'),
                    'client_secret' => config('services.microsoft.client_secret'),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => config('services.microsoft.redirect_uri'),
                    'code_verifier' => $verifier,
                    'scope' => 'openid profile email',
                ]);
        } catch (ConnectionException $exception) {
            throw new MicrosoftAuthException(
                'Microsoft sign-in is temporarily unavailable.',
                'MICROSOFT_UNAVAILABLE',
                503
            );
        }

        if (! $response->successful()) {
            Log::warning('Microsoft authorization code exchange failed.', [
                'provider_error' => $response->json('error'),
                'http_status' => $response->status(),
            ]);

            throw new MicrosoftAuthException(
                'Microsoft could not complete the sign-in request.',
                'MICROSOFT_CODE_EXCHANGE_FAILED'
            );
        }

        $tokens = $response->json();
        if (! is_array($tokens)) {
            throw new MicrosoftAuthException(
                'Microsoft returned an invalid sign-in response.',
                'MICROSOFT_INVALID_TOKEN_RESPONSE'
            );
        }

        return $tokens;
    }

    private function verifyIdToken(string $idToken, string $expectedNonce, array $metadata): array
    {
        try {
            $segments = explode('.', $idToken);
            if (count($segments) !== 3) {
                throw new MicrosoftAuthException(
                    'Microsoft returned an invalid identity token.',
                    'MICROSOFT_INVALID_ID_TOKEN'
                );
            }

            $header = json_decode(
                $this->base64UrlDecode($segments[0]),
                true,
                16,
                JSON_THROW_ON_ERROR
            );
            if (
                ! is_array($header)
                || ($header['alg'] ?? null) !== 'RS256'
                || ! filled($header['kid'] ?? null)
            ) {
                throw new MicrosoftAuthException(
                    'Microsoft returned an unsupported identity token.',
                    'MICROSOFT_INVALID_ID_TOKEN'
                );
            }

            $jwks = $this->jwks($metadata['jwks_uri']);
            $originalLeeway = JWT::$leeway;
            JWT::$leeway = 60;

            try {
                $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
            } finally {
                JWT::$leeway = $originalLeeway;
            }
        } catch (MicrosoftAuthException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Microsoft ID token verification failed.', [
                'exception' => $exception::class,
            ]);

            throw new MicrosoftAuthException(
                'Microsoft identity verification failed.',
                'MICROSOFT_INVALID_ID_TOKEN'
            );
        }

        $tenantId = strtolower((string) config('services.microsoft.tenant_id'));
        $clientId = (string) config('services.microsoft.client_id');
        $audiences = is_array($claims['aud'] ?? null)
            ? $claims['aud']
            : [$claims['aud'] ?? null];

        if (! in_array($clientId, $audiences, true)) {
            throw new MicrosoftAuthException(
                'The Microsoft identity token was issued for another application.',
                'MICROSOFT_AUDIENCE_MISMATCH'
            );
        }

        if (($claims['iss'] ?? null) !== $metadata['issuer']) {
            throw new MicrosoftAuthException(
                'The Microsoft identity token has an invalid issuer.',
                'MICROSOFT_ISSUER_MISMATCH'
            );
        }

        if (strtolower((string) ($claims['tid'] ?? '')) !== $tenantId) {
            throw new MicrosoftAuthException(
                'Only the Medcon Microsoft organization can access this application.',
                'MICROSOFT_TENANT_MISMATCH',
                403
            );
        }

        if (! filled($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new MicrosoftAuthException(
                'The Microsoft identity token could not be matched to this sign-in request.',
                'MICROSOFT_NONCE_MISMATCH'
            );
        }

        if (
            ! isset($claims['exp'], $claims['iat'])
            || ! is_numeric($claims['exp'])
            || ! is_numeric($claims['iat'])
        ) {
            throw new MicrosoftAuthException(
                'The Microsoft identity token has invalid timestamps.',
                'MICROSOFT_INVALID_ID_TOKEN'
            );
        }

        $objectId = trim((string) ($claims['oid'] ?? ''));
        $email = strtolower(trim((string) (
            $claims['email']
            ?? $claims['preferred_username']
            ?? $claims['upn']
            ?? ''
        )));

        if ($objectId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MicrosoftAuthException(
                'Microsoft did not provide the required employee identity.',
                'MICROSOFT_IDENTITY_INCOMPLETE'
            );
        }

        return [
            'tenant_id' => $tenantId,
            'object_id' => $objectId,
            'email' => $email,
            'name' => trim((string) ($claims['name'] ?? '')),
        ];
    }

    private function metadata(): array
    {
        $tenantId = strtolower((string) config('services.microsoft.tenant_id'));

        return Cache::remember(
            'microsoft_oidc_metadata:'.hash('sha256', $tenantId),
            now()->addHours(6),
            function () {
                try {
                    $response = Http::acceptJson()
                        ->timeout((int) config('services.microsoft.http_timeout_seconds', 12))
                        ->get($this->authority().'/v2.0/.well-known/openid-configuration');
                } catch (ConnectionException $exception) {
                    throw new MicrosoftAuthException(
                        'Microsoft sign-in is temporarily unavailable.',
                        'MICROSOFT_UNAVAILABLE',
                        503
                    );
                }

                $metadata = $response->successful() ? $response->json() : null;
                if (
                    ! is_array($metadata)
                    || ! $this->isTrustedMicrosoftEndpoint($metadata['issuer'] ?? null)
                    || ! $this->isTrustedMicrosoftEndpoint($metadata['token_endpoint'] ?? null)
                    || ! $this->isTrustedMicrosoftEndpoint($metadata['jwks_uri'] ?? null)
                ) {
                    throw new MicrosoftAuthException(
                        'Microsoft returned invalid OpenID configuration.',
                        'MICROSOFT_INVALID_CONFIGURATION',
                        503
                    );
                }

                return $metadata;
            }
        );
    }

    private function jwks(string $jwksUri): array
    {
        return Cache::remember(
            'microsoft_oidc_jwks:'.hash('sha256', $jwksUri),
            now()->addHours(6),
            function () use ($jwksUri) {
                try {
                    $response = Http::acceptJson()
                        ->timeout((int) config('services.microsoft.http_timeout_seconds', 12))
                        ->get($jwksUri);
                } catch (ConnectionException $exception) {
                    throw new MicrosoftAuthException(
                        'Microsoft identity keys are temporarily unavailable.',
                        'MICROSOFT_UNAVAILABLE',
                        503
                    );
                }

                $jwks = $response->successful() ? $response->json() : null;
                if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
                    throw new MicrosoftAuthException(
                        'Microsoft returned invalid identity keys.',
                        'MICROSOFT_INVALID_CONFIGURATION',
                        503
                    );
                }

                return $jwks;
            }
        );
    }

    private function assertConfigured(): void
    {
        if (! config('services.microsoft.enabled')) {
            throw new MicrosoftAuthException(
                'Microsoft sign-in is disabled.',
                'MICROSOFT_AUTH_DISABLED',
                503
            );
        }

        foreach (['tenant_id', 'client_id', 'client_secret', 'redirect_uri', 'frontend_url'] as $key) {
            if (! filled(config("services.microsoft.{$key}"))) {
                throw new MicrosoftAuthException(
                    'Microsoft sign-in is not fully configured.',
                    'MICROSOFT_AUTH_NOT_CONFIGURED',
                    503
                );
            }
        }

        foreach (['tenant_id', 'client_id'] as $key) {
            if (! preg_match('/^[0-9a-f-]{36}$/i', (string) config("services.microsoft.{$key}"))) {
                throw new MicrosoftAuthException(
                    'Microsoft sign-in configuration is invalid.',
                    'MICROSOFT_AUTH_NOT_CONFIGURED',
                    503
                );
            }
        }

        $redirectUri = (string) config('services.microsoft.redirect_uri');
        if (
            ! filter_var($redirectUri, FILTER_VALIDATE_URL)
            || (! str_starts_with($redirectUri, 'https://') && ! str_starts_with($redirectUri, 'http://localhost'))
        ) {
            throw new MicrosoftAuthException(
                'The Microsoft redirect URI is invalid.',
                'MICROSOFT_AUTH_NOT_CONFIGURED',
                503
            );
        }

        $frontendUrl = rtrim((string) config('services.microsoft.frontend_url'), '/');
        if (
            ! filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || (! str_starts_with($frontendUrl, 'https://') && ! str_starts_with($frontendUrl, 'http://localhost'))
        ) {
            throw new MicrosoftAuthException(
                'The Microsoft frontend URL is invalid.',
                'MICROSOFT_AUTH_NOT_CONFIGURED',
                503
            );
        }
    }

    private function authority(): string
    {
        return 'https://login.microsoftonline.com/'.strtolower(
            trim((string) config('services.microsoft.tenant_id'))
        );
    }

    private function isTrustedMicrosoftEndpoint(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'login.microsoftonline.com';
    }

    private function stateKey(string $state): string
    {
        return 'microsoft_oauth_state:'.hash('sha256', $state);
    }

    private function randomToken(int $bytes): string
    {
        return $this->base64UrlEncode(random_bytes($bytes));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('Invalid base64url value.');
        }

        return $decoded;
    }
}
