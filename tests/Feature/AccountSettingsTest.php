<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AccountSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_account_settings_cannot_change_the_email_address(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson("/api/user/update-profile/{$user->id}", [
            'name' => 'Updated Name',
            'email' => 'different@example.com',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertNotSame('different@example.com', $user->email);
    }

    public function test_user_must_confirm_current_password_before_changing_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPassword1!')]);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson("/api/user/update-password/{$user->id}", [
            'current_password' => 'WrongPassword1!',
            'password' => 'NewSecurePassword1!',
            'password_confirmation' => 'NewSecurePassword1!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson("/api/user/update-password/{$user->id}", [
            'current_password' => 'CurrentPassword1!',
            'password' => 'NewSecurePassword1!',
            'password_confirmation' => 'NewSecurePassword1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecurePassword1!', $user->fresh()->password));
    }
}
