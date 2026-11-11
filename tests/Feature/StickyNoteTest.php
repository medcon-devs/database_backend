<?php

namespace Tests\Feature;

use App\Jobs\SendStickyNoteMentionEmail;
use App\Models\ContactMediaGroup;
use App\Models\CSVData;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StickyNote;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class StickyNoteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_notes_default_to_active_and_can_be_filtered_by_folder(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $group = ContactMediaGroup::create(['contact_id' => $contact->id, 'title' => 'Passport', 'created_by' => $user->id]);
        $token = JWTAuth::fromUser($user);

        $profileNote = $this->makeNote($contact, $user, ['content' => 'Profile level note']);
        $folderNote = $this->makeNote($contact, $user, ['content' => 'Folder note', 'media_group_id' => $group->id]);
        $archived = $this->makeNote($contact, $user, [
            'content' => 'Old note', 'status' => StickyNote::STATUS_ARCHIVED,
            'archive_due_at' => now()->subDay(), 'archived_at' => now()->subDay(),
        ]);

        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/sticky-notes")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);

        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/sticky-notes?media_group_id={$group->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $folderNote->id)
            ->assertJsonPath('data.0.media_group.title', 'Passport');

        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/sticky-notes?media_group_id=none")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $profileNote->id);

        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/sticky-notes?status=archived")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $archived->id);
    }

    public function test_expired_active_notes_are_archived_on_read(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $stale = $this->makeNote($contact, $user, ['content' => 'stale', 'archive_due_at' => now()->subMinute()]);

        $this->withToken(JWTAuth::fromUser($user))->getJson("/api/contacts/{$contact->id}/sticky-notes")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('sticky_notes', [
            'id' => $stale->id, 'status' => StickyNote::STATUS_ARCHIVED, 'archived_by' => null,
        ]);
    }

    public function test_create_with_mentions_queues_one_email_per_recipient(): void
    {
        Queue::fake();
        [$user, $contact] = $this->actor(['contacts.view']);
        $peer = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);

        $response = $this->withToken(JWTAuth::fromUser($user))
            ->withHeader('Idempotency-Key', 'note-'.Str::uuid())
            ->postJson("/api/contacts/{$contact->id}/sticky-notes", [
                'content' => 'Please add bank details @peer',
                'mentioned_user_ids' => [$peer->id, $peer->id],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.mentions.0.user.id', $peer->id)
            ->assertJsonPath('data.mentions.0.email_status', 'queued')
            ->assertJsonCount(1, 'data.mentions');
        $this->assertDatabaseCount('sticky_note_mentions', 1);
        Queue::assertPushed(SendStickyNoteMentionEmail::class, 1);
    }

    public function test_ineligible_mentioned_user_is_rejected(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $outsider = $this->userWith([], $user->microsoft_tenant_id);
        $crossTenant = $this->userWith(['contacts.view'], 'other-tenant');

        foreach ([$outsider->id, $crossTenant->id] as $id) {
            $this->withToken(JWTAuth::fromUser($user))
                ->postJson("/api/contacts/{$contact->id}/sticky-notes", [
                    'content' => 'note', 'mentioned_user_ids' => [$id],
                ])->assertStatus(422)->assertJsonPath('code', 'INVALID_MENTIONED_USERS');
        }
        $this->assertDatabaseCount('sticky_note_mentions', 0);
    }

    public function test_idempotency_key_replays_the_same_note(): void
    {
        Queue::fake();
        [$user, $contact] = $this->actor(['contacts.view']);
        $token = JWTAuth::fromUser($user);
        $payload = ['content' => 'once only'];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'stable')
            ->postJson("/api/contacts/{$contact->id}/sticky-notes", $payload)->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', 'stable')
            ->postJson("/api/contacts/{$contact->id}/sticky-notes", $payload)
            ->assertOk()->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, StickyNote::where('contact_id', $contact->id)->count());
    }

    public function test_update_diffs_mentions_without_resending_unchanged(): void
    {
        Queue::fake();
        [$user, $contact] = $this->actor(['contacts.view']);
        $keep = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);
        $drop = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);
        $add = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);
        $token = JWTAuth::fromUser($user);

        $noteId = $this->withToken($token)->postJson("/api/contacts/{$contact->id}/sticky-notes", [
            'content' => 'v1', 'mentioned_user_ids' => [$keep->id, $drop->id],
        ])->assertCreated()->json('data.id');
        Queue::assertPushed(SendStickyNoteMentionEmail::class, 2);

        $this->withToken($token)->patchJson("/api/sticky-notes/{$noteId}", [
            'content' => 'v2', 'mentioned_user_ids' => [$keep->id, $add->id],
        ])->assertOk()->assertJsonCount(2, 'data.mentions');

        $this->assertDatabaseHas('sticky_note_mentions', ['sticky_note_id' => $noteId, 'mentioned_user_id' => $keep->id]);
        $this->assertDatabaseHas('sticky_note_mentions', ['sticky_note_id' => $noteId, 'mentioned_user_id' => $add->id]);
        $this->assertDatabaseMissing('sticky_note_mentions', ['sticky_note_id' => $noteId, 'mentioned_user_id' => $drop->id]);
        // 2 on create + only the newly added recipient on update.
        Queue::assertPushed(SendStickyNoteMentionEmail::class, 3);
    }

    public function test_list_omits_email_status_but_keeps_user(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $peer = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);
        $note = $this->makeNote($contact, $user, ['content' => 'with mention']);
        $note->mentions()->create(['mentioned_user_id' => $peer->id, 'email_status' => 'sent']);

        $body = $this->withToken(JWTAuth::fromUser($user))
            ->getJson("/api/contacts/{$contact->id}/sticky-notes")->assertOk()->json('data.0.mentions.0');

        $this->assertArrayHasKey('user', $body);
        $this->assertSame($peer->id, $body['user']['id']);
        $this->assertArrayNotHasKey('email_status', $body);
    }

    public function test_manual_archive_and_restore_resets_the_timer(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $note = $this->makeNote($contact, $user, ['content' => 'toggle', 'archive_due_at' => now()->addDay()]);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->patchJson("/api/sticky-notes/{$note->id}/status", ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.archived_by', $user->id);

        $restored = $this->withToken($token)->patchJson("/api/sticky-notes/{$note->id}/status", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.archived_by', null);

        $this->assertTrue(now()->addDays(6)->lessThan($restored->json('data.archive_due_at')));
    }

    public function test_folder_from_another_contact_is_rejected(): void
    {
        [$user, $contact] = $this->actor(['contacts.view']);
        $otherContact = CSVData::create(['firstname' => 'Other', 'lastname' => 'Doc', 'email' => Str::uuid().'@ex.test', 'user_id' => $user->id, 'microsoft_tenant_id' => $user->microsoft_tenant_id]);
        $foreignGroup = ContactMediaGroup::create(['contact_id' => $otherContact->id, 'title' => 'x', 'created_by' => $user->id]);

        $this->withToken(JWTAuth::fromUser($user))->postJson("/api/contacts/{$contact->id}/sticky-notes", [
            'content' => 'note', 'media_group_id' => $foreignGroup->id,
        ])->assertStatus(422)->assertJsonValidationErrors('media_group_id');
    }

    public function test_mention_email_job_sends_and_records_status(): void
    {
        Mail::fake();
        [$user, $contact] = $this->actor(['contacts.view']);
        $peer = $this->userWith(['contacts.view'], $user->microsoft_tenant_id);
        $note = $this->makeNote($contact, $user, ['content' => 'ping']);
        $mention = $note->mentions()->create(['mentioned_user_id' => $peer->id, 'email_status' => 'queued']);

        (new SendStickyNoteMentionEmail($mention->id))->handle();

        $this->assertDatabaseHas('sticky_note_mentions', [
            'id' => $mention->id, 'email_status' => 'sent', 'email_attempts' => 1,
        ]);
    }

    private function actor(array $permissions, string $tenant = 'tenant-a'): array
    {
        $user = $this->userWith($permissions, $tenant);
        $contact = CSVData::create([
            'firstname' => 'Sticky', 'lastname' => 'Doctor', 'email' => Str::uuid().'@example.test',
            'user_id' => $user->id, 'microsoft_tenant_id' => $tenant,
        ]);

        return [$user, $contact];
    }

    private function userWith(array $permissions, ?string $tenant, array $attributes = []): User
    {
        $role = Role::create(['name' => 'sticky_test_'.Str::lower(Str::random(12)), 'label' => 'Sticky Test']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create($attributes + [
            'access_type' => 'R', 'role_id' => $role->id, 'microsoft_tenant_id' => $tenant,
        ]);
    }

    private function makeNote(CSVData $contact, User $user, array $attributes): StickyNote
    {
        return StickyNote::create(array_merge([
            'contact_id' => $contact->id,
            'status' => StickyNote::STATUS_ACTIVE,
            'archive_due_at' => now()->addDays(7),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $attributes));
    }
}
