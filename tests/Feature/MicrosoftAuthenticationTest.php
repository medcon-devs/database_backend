<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MicrosoftAuthenticationTest extends TestCase
{
    private const TENANT_ID = '11111111-2222-3333-4444-555555555555';
    private const CLIENT_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private string $privateKey;
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'mail.default' => 'array',
            'services.microsoft.enabled' => true,
            'services.microsoft.tenant_id' => self::TENANT_ID,
            'services.microsoft.client_id' => self::CLIENT_ID,
            'services.microsoft.client_secret' => 'microsoft-test-secret',
            'services.microsoft.redirect_uri' => 'http://localhost:8000/api/auth/microsoft/callback',
            'services.microsoft.frontend_url' => 'http://localhost:3000',
            'services.microsoft.state_ttl_minutes' => 10,
            'services.microsoft.exchange_ttl_seconds' => 90,
            'services.microsoft.http_timeout_seconds' => 3,
        ]);

        DB::purge();
        Cache::flush();
        $this->createSchema();
        $this->createSigningKey();
    }

    public function test_microsoft_login_links_existing_user_and_issues_medcon_tokens(): void
    {
        $user = User::create([
            'name' => 'Majd Ahmad',
            'email' => 'majd@medcon.ae',
            'password' => Hash::make('unused-local-password'),
            'access_type' => 'FULL',
            'is_banned' => false,
        ]);

        $redirect = $this->get('/api/auth/microsoft/redirect')->assertRedirect();
        $authorizationUrl = $redirect->headers->get('Location');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);

        $this->assertSame(self::CLIENT_ID, $authorizationQuery['client_id']);
        $this->assertSame('S256', $authorizationQuery['code_challenge_method']);
        $this->assertNotEmpty($authorizationQuery['nonce']);
        $this->assertNotEmpty($authorizationQuery['state']);

        $stateCookie = collect($redirect->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'medcon_microsoft_oauth_state');
        $this->assertNotNull($stateCookie);
        $this->assertSame($authorizationQuery['state'], $stateCookie->getValue());

        $this->fakeMicrosoft($authorizationQuery['nonce'], 'majd@medcon.ae');

        $callback = $this->withUnencryptedCookie(
            'medcon_microsoft_oauth_state',
            $authorizationQuery['state']
        )->get('/api/auth/microsoft/callback?'.http_build_query([
            'code' => 'microsoft-authorization-code',
            'state' => $authorizationQuery['state'],
        ]))->assertRedirect();

        $frontendLocation = $callback->headers->get('Location');
        $this->assertStringStartsWith(
            'http://localhost:3000/auth/microsoft/callback?',
            $frontendLocation
        );
        parse_str((string) parse_url($frontendLocation, PHP_URL_QUERY), $frontendQuery);
        $this->assertNotEmpty($frontendQuery['code']);

        $exchange = $this->postJson('/api/auth/microsoft/exchange', [
            'code' => $frontendQuery['code'],
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.authentication_provider', 'microsoft')
            ->assertJsonPath('data.user.access_type', 'FULL');

        $this->assertNotEmpty($exchange->json('data.access_token'));
        $this->assertNotEmpty($exchange->json('data.refresh_token'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'auth_provider' => 'microsoft',
            'microsoft_tenant_id' => self::TENANT_ID,
            'microsoft_oid' => 'microsoft-object-id',
        ]);
        $this->assertDatabaseCount('refresh_tokens', 1);

        $this->postJson('/api/auth/microsoft/exchange', [
            'code' => $frontendQuery['code'],
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'MICROSOFT_EXCHANGE_CODE_INVALID');
    }

    public function test_microsoft_login_rejects_unknown_medcon_user(): void
    {
        $redirect = $this->get('/api/auth/microsoft/redirect')->assertRedirect();
        parse_str(
            (string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY),
            $authorizationQuery
        );
        $this->fakeMicrosoft($authorizationQuery['nonce'], 'unknown@medcon.ae');

        $callback = $this->withUnencryptedCookie(
            'medcon_microsoft_oauth_state',
            $authorizationQuery['state']
        )->get('/api/auth/microsoft/callback?'.http_build_query([
            'code' => 'microsoft-authorization-code',
            'state' => $authorizationQuery['state'],
        ]))->assertRedirect();

        parse_str(
            (string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY),
            $frontendQuery
        );

        $this->assertSame('MICROSOFT_ACCOUNT_NOT_PROVISIONED', $frontendQuery['error']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_microsoft_callback_rejects_state_without_matching_browser_cookie(): void
    {
        $redirect = $this->get('/api/auth/microsoft/redirect')->assertRedirect();
        parse_str(
            (string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY),
            $authorizationQuery
        );

        $callback = $this->get('/api/auth/microsoft/callback?'.http_build_query([
            'code' => 'microsoft-authorization-code',
            'state' => $authorizationQuery['state'],
        ]))->assertRedirect();

        parse_str(
            (string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY),
            $frontendQuery
        );

        $this->assertSame('MICROSOFT_STATE_MISMATCH', $frontendQuery['error']);
        Http::assertNothingSent();
    }

    public function test_banned_user_cannot_refresh_a_local_session(): void
    {
        $user = User::create([
            'name' => 'Suspended User',
            'email' => 'suspended@medcon.ae',
            'password' => Hash::make('unused-local-password'),
            'access_type' => 'R',
            'is_banned' => true,
        ]);
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => 'active-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/refresh', [
            'refresh_token' => 'active-refresh-token',
        ])->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->assertDatabaseMissing('refresh_tokens', [
            'token' => 'active-refresh-token',
        ]);
    }

    public function test_password_login_and_forgot_password_routes_are_disabled(): void
    {
        $this->postJson('/api/login', [
            'email' => 'local@medcon.ae',
            'password' => 'LocalPassword1!',
        ])->assertNotFound();

        $this->postJson('/api/forgot-password', [
            'email' => 'local@medcon.ae',
        ])->assertNotFound();
    }

    private function fakeMicrosoft(string $nonce, string $email): void
    {
        $issuer = 'https://login.microsoftonline.com/'.self::TENANT_ID.'/v2.0';
        $tokenEndpoint = 'https://login.microsoftonline.com/'.self::TENANT_ID.'/oauth2/v2.0/token';
        $jwksUri = 'https://login.microsoftonline.com/'.self::TENANT_ID.'/discovery/v2.0/keys';
        $idToken = JWT::encode([
            'aud' => self::CLIENT_ID,
            'iss' => $issuer,
            'iat' => time(),
            'nbf' => time() - 1,
            'exp' => time() + 300,
            'nonce' => $nonce,
            'tid' => self::TENANT_ID,
            'oid' => 'microsoft-object-id',
            'sub' => 'microsoft-subject',
            'name' => 'Majd Ahmad',
            'preferred_username' => $email,
        ], $this->privateKey, 'RS256', 'microsoft-test-key');

        Http::fake([
            'login.microsoftonline.com/*/.well-known/openid-configuration' => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => $issuer.'/oauth2/v2.0/authorize',
                'token_endpoint' => $tokenEndpoint,
                'jwks_uri' => $jwksUri,
            ]),
            $tokenEndpoint => Http::response([
                'token_type' => 'Bearer',
                'id_token' => $idToken,
            ]),
            $jwksUri => Http::response([
                'keys' => [$this->jwk],
            ]),
        ]);
    }

    private function createSigningKey(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        $this->privateKey = $privateKey;
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => 'microsoft-test-key',
            'alg' => 'RS256',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('auth_provider', 20)->default('local');
            $table->string('microsoft_tenant_id', 64)->nullable();
            $table->string('microsoft_oid', 64)->nullable();
            $table->timestamp('last_microsoft_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->string('access_type')->default('R');
            $table->boolean('receive_notification')->default(false);
            $table->timestamps();
            $table->unique(['microsoft_tenant_id', 'microsoft_oid']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
}
