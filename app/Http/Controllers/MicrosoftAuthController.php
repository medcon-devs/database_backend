<?php

namespace App\Http\Controllers;

use App\Exceptions\MicrosoftAuthException;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\MicrosoftOpenIdService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MicrosoftAuthController extends Controller
{
    private const STATE_COOKIE = 'medcon_microsoft_oauth_state';

    public function configuration()
    {
        $redirectUri = (string) config('services.microsoft.redirect_uri');
        $parts = parse_url($redirectUri);
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;

        return response()->json([
            'data' => [
                'enabled' => (bool) config('services.microsoft.enabled'),
                'login_url' => $origin ? $origin.'/api/auth/microsoft/redirect' : null,
            ],
            'status_code' => 200,
            'message' => 'Microsoft sign-in configuration retrieved successfully.',
            'status' => true,
        ]);
    }

    public function redirectToMicrosoft(MicrosoftOpenIdService $microsoft)
    {
        try {
            $authorization = $microsoft->beginAuthorization();
        } catch (MicrosoftAuthException $exception) {
            return $this->jsonError($exception);
        }

        return redirect()->away($authorization['url'])->withCookie(cookie(
            self::STATE_COOKIE,
            $authorization['state'],
            (int) config('services.microsoft.state_ttl_minutes', 10),
            '/',
            null,
            str_starts_with((string) config('services.microsoft.redirect_uri'), 'https://'),
            true,
            false,
            'lax'
        ));
    }

    public function callback(Request $request, MicrosoftOpenIdService $microsoft)
    {
        if ($request->filled('error')) {
            Log::notice('Microsoft sign-in was declined or failed.', [
                'provider_error' => $request->string('error')->limit(100)->toString(),
            ]);

            return $this->frontendErrorRedirect('MICROSOFT_ACCESS_DENIED')
                ->withoutCookie(Cookie::forget(self::STATE_COOKIE));
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'state' => ['required', 'string', 'max:256'],
        ]);

        try {
            $identity = $microsoft->completeAuthorization(
                $validated['code'],
                $validated['state'],
                $request->cookie(self::STATE_COOKIE)
            );
            $user = $this->resolveUser($identity);

            if ($user->is_banned) {
                throw new MicrosoftAuthException(
                    'Your Medcon account has been suspended.',
                    'ACCOUNT_SUSPENDED',
                    403
                );
            }

            $exchangeCode = $this->randomToken(48);
            Cache::put($this->exchangeKey($exchangeCode), [
                'user_id' => $user->id,
                'microsoft_oid' => $user->microsoft_oid,
            ], now()->addSeconds((int) config('services.microsoft.exchange_ttl_seconds', 90)));

            $url = $this->frontendCallbackUrl(['code' => $exchangeCode]);

            return redirect()->away($url)
                ->withoutCookie(Cookie::forget(self::STATE_COOKIE));
        } catch (MicrosoftAuthException $exception) {
            return $this->frontendErrorRedirect($exception->errorCode())
                ->withoutCookie(Cookie::forget(self::STATE_COOKIE));
        } catch (QueryException $exception) {
            Log::warning('Microsoft identity linking encountered a database conflict.', [
                'sql_state' => $exception->errorInfo[0] ?? null,
            ]);

            return $this->frontendErrorRedirect('MICROSOFT_IDENTITY_CONFLICT')
                ->withoutCookie(Cookie::forget(self::STATE_COOKIE));
        } catch (Throwable $exception) {
            Log::error('Unexpected Microsoft sign-in callback failure.', [
                'exception' => $exception::class,
            ]);

            return $this->frontendErrorRedirect('MICROSOFT_AUTH_FAILED')
                ->withoutCookie(Cookie::forget(self::STATE_COOKIE));
        }
    }

    public function exchange(Request $request, AuthSessionService $sessions)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $exchange = Cache::pull($this->exchangeKey($validated['code']));
        if (! is_array($exchange) || ! isset($exchange['user_id'], $exchange['microsoft_oid'])) {
            return response()->json([
                'code' => 'MICROSOFT_EXCHANGE_CODE_INVALID',
                'data' => null,
                'status_code' => 401,
                'message' => 'The Microsoft sign-in code is invalid or expired.',
                'status' => false,
            ], 401);
        }

        $user = User::find($exchange['user_id']);
        if (! $user || ! hash_equals((string) $exchange['microsoft_oid'], (string) $user->microsoft_oid)) {
            return response()->json([
                'code' => 'MICROSOFT_IDENTITY_NOT_FOUND',
                'data' => null,
                'status_code' => 401,
                'message' => 'The Microsoft identity is no longer available.',
                'status' => false,
            ], 401);
        }

        if ($user->is_banned) {
            return response()->json([
                'code' => 'ACCOUNT_SUSPENDED',
                'data' => null,
                'status_code' => 403,
                'message' => 'Your Medcon account has been suspended.',
                'status' => false,
            ], 403);
        }

        $tokens = $sessions->issue($user);
        $sessions->notifyAdministratorsOfLogin($user);

        return response()->json([
            'data' => array_merge([
                'user' => $user,
                'authentication_provider' => 'microsoft',
            ], $tokens),
            'status_code' => 200,
            'message' => 'Microsoft sign-in completed successfully.',
            'status' => true,
        ]);
    }

    private function resolveUser(array $identity): User
    {
        return DB::transaction(function () use ($identity) {
            $linkedUser = User::where('microsoft_tenant_id', $identity['tenant_id'])
                ->where('microsoft_oid', $identity['object_id'])
                ->lockForUpdate()
                ->first();

            $emailUser = User::whereRaw('LOWER(email) = ?', [$identity['email']])
                ->lockForUpdate()
                ->first();

            if ($linkedUser && $emailUser && $linkedUser->id !== $emailUser->id) {
                throw new MicrosoftAuthException(
                    'The Microsoft identity conflicts with another Medcon account.',
                    'MICROSOFT_IDENTITY_CONFLICT',
                    409
                );
            }

            $user = $linkedUser ?: $emailUser;
            if (! $user) {
                throw new MicrosoftAuthException(
                    'Your Microsoft account has not been added to Medcon.',
                    'MICROSOFT_ACCOUNT_NOT_PROVISIONED',
                    403
                );
            }

            if ($user->is_banned) {
                throw new MicrosoftAuthException(
                    'Your Medcon account has been suspended.',
                    'ACCOUNT_SUSPENDED',
                    403
                );
            }

            if (
                filled($user->microsoft_oid)
                && (
                    ! hash_equals((string) $user->microsoft_oid, $identity['object_id'])
                    || ! hash_equals(
                        strtolower((string) $user->microsoft_tenant_id),
                        strtolower($identity['tenant_id'])
                    )
                )
            ) {
                throw new MicrosoftAuthException(
                    'This Medcon user is linked to another Microsoft identity.',
                    'MICROSOFT_IDENTITY_CONFLICT',
                    409
                );
            }

            $user->forceFill([
                'auth_provider' => 'microsoft',
                'microsoft_tenant_id' => strtolower($identity['tenant_id']),
                'microsoft_oid' => $identity['object_id'],
                'last_microsoft_login_at' => now(),
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();

            return $user->fresh();
        });
    }

    private function frontendErrorRedirect(string $errorCode)
    {
        return redirect()->away($this->frontendCallbackUrl(['error' => $errorCode]));
    }

    private function frontendCallbackUrl(array $query): string
    {
        $base = rtrim((string) config('services.microsoft.frontend_url'), '/');

        return $base.'/auth/microsoft/callback?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private function jsonError(MicrosoftAuthException $exception)
    {
        return response()->json([
            'code' => $exception->errorCode(),
            'data' => null,
            'status_code' => $exception->statusCode(),
            'message' => $exception->getMessage(),
            'status' => false,
        ], $exception->statusCode());
    }

    private function exchangeKey(string $code): string
    {
        return 'microsoft_login_exchange:'.hash('sha256', $code);
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
