<?php

namespace Tests\Feature;

use App\Jobs\SendMentionEmail;
use App\Models\CSVData;
use App\Models\ContactMediaGroup;
use App\Models\MentionMessage;
use App\Models\NotificationDelivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class MentionSystemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_eligible_user_search_is_permission_and_tenant_scoped(): void
    {
        [$sender, $group] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        $eligible = $this->userWithPermissions(['contact_media.view'], 'tenant-a', ['name' => 'Majd Eligible']);
        $this->userWithPermissions(['contact_media.view'], 'tenant-b', ['name' => 'Majd Other Tenant']);
        $this->userWithPermissions(['contact_media.view'], 'tenant-a', ['name' => 'Majd Banned', 'is_banned' => true]);
        $this->userWithPermissions([], 'tenant-a', ['name' => 'Majd No Access']);

        $response = $this->withToken(JWTAuth::fromUser($sender))->getJson(
            "/api/mentions/users?context_type=contact_media_group&context_id={$group->id}&search=Majd"
        );

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $eligible->id);
        $this->assertSame(['id', 'name', 'email', 'avatar_url'], array_keys($response->json('data.0')));
    }

    public function test_message_collapses_duplicate_mentions_and_creates_delivery_and_audit_rows(): void
    {
        Queue::fake();
        [$sender, $group] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');

        $response = $this->withToken(JWTAuth::fromUser($sender))
            ->withHeader('Idempotency-Key', 'mention-'.Str::uuid())
            ->postJson('/api/mentions/messages', [
                'context_type' => 'contact_media_group', 'context_id' => $group->id,
                'message' => '<b>Please review</b> the license.',
                'mentioned_user_ids' => [$recipient->id, $recipient->id],
            ]);

        $response->assertCreated()->assertJsonPath('data.message', 'Please review the license.')
            ->assertJsonCount(1, 'data.mentions')->assertJsonPath('data.mentions.0.email_status', 'queued');
        $this->assertDatabaseCount('mention_messages', 1);
        $this->assertDatabaseCount('message_mentions', 1);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'email', 'status' => 'queued']);
        $this->assertDatabaseHas('contact_media_group_activities', ['group_id' => $group->id, 'action' => 'mention_created']);
    }

    public function test_cross_tenant_banned_and_unauthorized_recipients_are_rejected_without_disclosure(): void
    {
        [$sender, $group] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        foreach ([
            $this->userWithPermissions(['contact_media.view'], 'tenant-b'),
            $this->userWithPermissions(['contact_media.view'], 'tenant-a', ['is_banned' => true]),
            $this->userWithPermissions([], 'tenant-a'),
        ] as $recipient) {
            $this->withToken(JWTAuth::fromUser($sender))->postJson('/api/mentions/messages', [
                'context_type' => 'contact_media_group', 'context_id' => $group->id,
                'message' => 'Please review.', 'mentioned_user_ids' => [$recipient->id],
            ])->assertStatus(422)->assertJsonPath('code', 'INVALID_MENTIONED_USERS');
        }
        $this->assertDatabaseCount('mention_messages', 0);
    }

    public function test_sender_requires_mention_and_underlying_context_permissions(): void
    {
        [$noMentionPermission, $group] = $this->contextUser(['contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');
        $payload = ['context_type' => 'contact_media_group', 'context_id' => $group->id, 'message' => 'Review.', 'mentioned_user_ids' => [$recipient->id]];
        $this->withToken(JWTAuth::fromUser($noMentionPermission))->postJson('/api/mentions/messages', $payload)->assertForbidden();

        $noContextPermission = $this->userWithPermissions(['mentions.create'], 'tenant-a');
        $this->withToken(JWTAuth::fromUser($noContextPermission))->postJson('/api/mentions/messages', $payload)->assertForbidden();
    }

    public function test_invalid_context_type_and_resource_are_rejected(): void
    {
        [$sender] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');
        $base = ['message' => 'Review.', 'mentioned_user_ids' => [$recipient->id]];
        $this->withToken(JWTAuth::fromUser($sender))->postJson('/api/mentions/messages', $base + [
            'context_type' => 'unknown', 'context_id' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('context_type');
        $this->withToken(JWTAuth::fromUser($sender))->postJson('/api/mentions/messages', $base + [
            'context_type' => 'contact_media_group', 'context_id' => 999999,
        ])->assertNotFound();
    }

    public function test_email_job_marks_success_and_failure_without_changing_saved_message(): void
    {
        Mail::fake();
        [$sender, $group] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');
        $message = MentionMessage::create(['sender_id' => $sender->id, 'context_type' => 'contact_media_group', 'context_id' => $group->id, 'message' => 'Review this file.']);
        $mention = $message->mentions()->create(['mentioned_user_id' => $recipient->id]);
        $delivery = NotificationDelivery::create(['message_mention_id' => $mention->id, 'channel' => 'email', 'status' => 'queued']);

        (new SendMentionEmail($delivery->id))->handle(app(\App\Services\MentionContextService::class));
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'sent', 'attempts' => 1]);
        $this->assertDatabaseHas('mention_messages', ['id' => $message->id]);
    }

    public function test_idempotency_key_prevents_double_click_duplicates_and_history_is_readable(): void
    {
        Queue::fake();
        [$sender, $group] = $this->contextUser(['mentions.create', 'mentions.read', 'contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');
        $payload = ['context_type' => 'contact_media_group', 'context_id' => $group->id, 'message' => 'Review once.', 'mentioned_user_ids' => [$recipient->id]];
        $token = JWTAuth::fromUser($sender);

        $this->withToken($token)->withHeader('Idempotency-Key', 'stable-key')->postJson('/api/mentions/messages', $payload)->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', 'stable-key')->postJson('/api/mentions/messages', $payload)
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('mention_messages', 1);

        $this->withToken($token)->getJson("/api/mentions/messages?context_type=contact_media_group&context_id={$group->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'Review once.');
    }

    public function test_email_failure_is_recorded_without_deleting_the_saved_message(): void
    {
        [$sender, $group] = $this->contextUser(['mentions.create', 'contact_media.view'], 'tenant-a');
        $recipient = $this->userWithPermissions(['contact_media.view'], 'tenant-a');
        $message = MentionMessage::create(['sender_id' => $sender->id, 'context_type' => 'contact_media_group', 'context_id' => $group->id, 'message' => 'Persist me.']);
        $mention = $message->mentions()->create(['mentioned_user_id' => $recipient->id]);
        $delivery = NotificationDelivery::create(['message_mention_id' => $mention->id, 'channel' => 'email', 'status' => 'queued']);
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail transport unavailable'));

        try {
            (new SendMentionEmail($delivery->id))->handle(app(\App\Services\MentionContextService::class));
            $this->fail('The job should throw so the queue retries it.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mail transport unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'failed', 'attempts' => 1]);
        $this->assertDatabaseHas('mention_messages', ['id' => $message->id, 'message' => 'Persist me.']);
    }

    private function contextUser(array $permissions, ?string $tenant): array
    {
        $user = $this->userWithPermissions($permissions, $tenant);
        $contact = CSVData::create(['firstname' => 'Test', 'lastname' => 'Doctor', 'email' => Str::uuid().'@example.test', 'user_id' => $user->id]);
        $group = ContactMediaGroup::create(['contact_id' => $contact->id, 'title' => 'Medical License', 'created_by' => $user->id]);
        return [$user, $group];
    }

    private function userWithPermissions(array $permissions, ?string $tenant, array $attributes = []): User
    {
        $role = Role::create(['name' => 'mention_test_'.Str::lower(Str::random(12)), 'label' => 'Mention Test']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        return User::factory()->create($attributes + ['access_type' => 'R', 'role_id' => $role->id, 'microsoft_tenant_id' => $tenant]);
    }
}
