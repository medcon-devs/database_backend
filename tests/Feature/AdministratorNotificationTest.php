<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdministratorNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_active_verified_opted_in_administrators_are_notification_recipients(): void
    {
        $eligible = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => true,
            'is_banned' => false,
        ]);
        $disabled = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => false,
        ]);
        $unverified = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => null,
            'receive_notification' => true,
        ]);
        $banned = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => true,
            'is_banned' => true,
        ]);
        $nonAdministrator = User::factory()->create([
            'access_type' => 'RWE',
            'email_verified_at' => now(),
            'receive_notification' => true,
        ]);

        $recipients = User::notificationAdministrators()->pluck('id')->all();

        $this->assertContains($eligible->id, $recipients);
        $this->assertNotContains($disabled->id, $recipients);
        $this->assertNotContains($unverified->id, $recipients);
        $this->assertNotContains($banned->id, $recipients);
        $this->assertNotContains($nonAdministrator->id, $recipients);
    }

    public function test_sign_in_notification_excludes_the_administrator_who_logged_in(): void
    {
        config(['mail.default' => 'array']);
        app('mail.manager')->purge('array');
        User::query()->update(['receive_notification' => false]);

        $signedInAdministrator = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => true,
            'is_banned' => false,
        ]);
        $otherAdministrator = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => true,
            'is_banned' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $signedInAdministrator->email,
            'password' => 'password',
        ])->assertOk();

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $recipientAddresses = array_map(
            fn ($address) => $address->getAddress(),
            $messages[0]->getOriginalMessage()->getTo()
        );

        $this->assertSame([$otherAdministrator->email], $recipientAddresses);
        $this->assertNotContains($signedInAdministrator->email, $recipientAddresses);
    }

    public function test_user_can_disable_notification_preference_from_account(): void
    {
        Mail::fake();
        $administrator = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => true,
        ]);
        $token = JWTAuth::fromUser($administrator);

        $this->withToken($token)
            ->patchJson('/api/user/notification-preference', [
                'receive_notification' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.receive_notification', false);

        $this->assertFalse($administrator->fresh()->receive_notification);
    }

    public function test_enabling_administrator_notifications_updates_the_preference(): void
    {
        Mail::fake();
        $administrator = User::factory()->create([
            'access_type' => 'FULL',
            'email_verified_at' => now(),
            'receive_notification' => false,
        ]);
        $token = JWTAuth::fromUser($administrator);

        $this->withToken($token)
            ->patchJson('/api/user/notification-preference', [
                'receive_notification' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.receive_notification', true);

        $this->assertTrue($administrator->fresh()->receive_notification);
    }
}
