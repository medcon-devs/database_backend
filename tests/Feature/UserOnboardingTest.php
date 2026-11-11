<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_full_user_can_create_user_without_a_password_and_setup_email_is_prepared(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['access_type' => 'FULL']);
        $token = JWTAuth::fromUser($admin);
        $email = 'invited-'.Str::lower(Str::random(12)).'@example.com';

        $response = $this->withToken($token)->postJson('/api/user', [
            'name' => 'Invited Employee',
            'email' => $email,
            'access_type' => 'RWE',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('email', $email)
            ->assertJsonPath('access_type', 'RWE')
            ->assertJsonPath('setup_email_sent', true)
            ->assertJsonMissingPath('password');

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'access_type' => 'RWE',
        ]);
        $this->assertDatabaseHas('password_resets', ['email' => $email]);
    }

    public function test_invited_user_can_set_password_once_and_is_redirected_to_login(): void
    {
        config(['app.frontend_login_url' => 'https://database.medcon.test/login']);

        $user = User::factory()->create([
            'email' => 'setup-'.Str::lower(Str::random(12)).'@example.com',
            'email_verified_at' => null,
            'receive_notification' => false,
            'password' => Hash::make(Str::random(64)),
        ]);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
        ]))->assertOk()->assertSee('Set password and continue');

        $response = $this->post(route('password.setup.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Valid123',
            'password_confirmation' => 'Valid123',
        ]);

        $response->assertRedirect('https://database.medcon.test/login?password_set=1');
        $user = $user->fresh();
        $this->assertTrue(Hash::check('Valid123', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->receive_notification);
        $this->assertDatabaseMissing('password_resets', ['email' => $user->email]);

        $this->get(route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
        ]))->assertStatus(410);
    }

    public function test_setup_page_requires_eight_character_mixed_case_password_with_a_number(): void
    {
        $user = User::factory()->create([
            'email' => 'password-policy-'.Str::lower(Str::random(12)).'@example.com',
            'password' => Hash::make(Str::random(64)),
        ]);
        $originalPassword = $user->password;
        $token = Password::broker()->createToken($user);

        $this->get(route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
        ]))
            ->assertOk()
            ->assertSee('minlength="8"', false)
            ->assertSee('At least 8 characters');

        $this->from(route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
        ]))->post(route('password.setup.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Short1A',
            'password_confirmation' => 'Short1A',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseHas('password_resets', ['email' => $user->email]);
    }

    public function test_invalid_password_setup_link_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'invalid-'.Str::lower(Str::random(12)).'@example.com',
        ]);

        $this->get(route('password.setup.show', [
            'token' => 'invalid-token',
            'email' => $user->email,
        ]))->assertStatus(410)->assertSee('link is no longer valid');
    }

    public function test_verified_administrator_enables_activity_notifications(): void
    {
        Mail::fake();
        config(['app.frontend_login_url' => 'https://database.medcon.test/login']);

        $user = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => null,
            'receive_notification' => false,
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.setup.update'), [
            'token' => $token,
            'email' => $user->email,
            'mode' => 'setup',
            'password' => 'AdminSecurePassword123',
            'password_confirmation' => 'AdminSecurePassword123',
        ])->assertRedirect('https://database.medcon.test/login?password_set=1');

        $user = $user->fresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->receive_notification);
    }
}
