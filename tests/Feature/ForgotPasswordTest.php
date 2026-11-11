<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use DatabaseTransactions;

    public function test_existing_user_can_request_a_password_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'forgot-'.Str::lower(Str::random(12)).'@example.com',
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson($this->genericResponse());

        $this->assertDatabaseHas('password_resets', ['email' => $user->email]);
    }

    public function test_unknown_email_receives_the_same_response_without_creating_a_token(): void
    {
        Mail::fake();

        $email = 'unknown-'.Str::lower(Str::random(12)).'@example.com';

        $this->postJson('/api/forgot-password', ['email' => $email])
            ->assertOk()
            ->assertExactJson($this->genericResponse());

        $this->assertDatabaseMissing('password_resets', ['email' => $email]);
    }

    public function test_user_can_reset_password_and_is_redirected_to_login(): void
    {
        Mail::fake();
        config(['app.frontend_login_url' => 'https://database.medcon.test/login']);

        $user = User::factory()->create([
            'email' => 'reset-'.Str::lower(Str::random(12)).'@example.com',
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        // Generate a readable token for the browser part of the flow. The API
        // token itself is intentionally only available inside the email.
        $token = Password::broker()->createToken($user);

        $this->get(route('password.setup.show', [
            'token' => $token,
            'email' => $user->email,
            'mode' => 'reset',
        ]))->assertOk()->assertSee('Reset password and continue');

        $this->post(route('password.setup.update'), [
            'token' => $token,
            'email' => $user->email,
            'mode' => 'reset',
            'password' => 'ResetSecurePassword123',
            'password_confirmation' => 'ResetSecurePassword123',
        ])->assertRedirect('https://database.medcon.test/login?password_reset=1');

        $this->assertTrue(Hash::check('ResetSecurePassword123', $user->fresh()->password));
    }

    private function genericResponse(): array
    {
        return [
            'data' => null,
            'status_code' => 200,
            'message' => 'If an account exists for this email, a password reset link has been sent.',
            'status' => true,
        ];
    }
}
