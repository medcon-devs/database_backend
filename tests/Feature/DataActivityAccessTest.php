<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\User;
use App\Models\UserActivity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DataActivityAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_non_administrator_sees_only_activity_they_performed(): void
    {
        [$contact, $user, $otherUser] = $this->activityScenario();
        $ownActivity = $this->createActivity($user, $contact, 'update', now());
        $this->createActivity($otherUser, $contact, 'delete', now()->addSecond());

        $response = $this->withToken(JWTAuth::fromUser($user))
            ->getJson("/api/data-activity/{$contact->id}?page=1&perPage=10")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownActivity->id)
            ->assertJsonPath('data.0.user.user_id', $user->id);

        $this->assertNotContains($otherUser->id, collect($response->json('data'))->pluck('user.user_id'));
    }

    public function test_non_administrator_with_no_own_activity_receives_an_empty_result(): void
    {
        [$contact, $user, $otherUser] = $this->activityScenario();
        $this->createActivity($otherUser, $contact, 'update', now());

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson("/api/data-activity/{$contact->id}?page=1&perPage=10")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_full_access_administrator_sees_every_users_activity(): void
    {
        [$contact, $user, $otherUser] = $this->activityScenario();
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $firstActivity = $this->createActivity($user, $contact, 'update', now());
        $secondActivity = $this->createActivity($otherUser, $contact, 'delete', now()->addSecond());

        $response = $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson("/api/data-activity/{$contact->id}?page=1&perPage=10")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$firstActivity->id, $secondActivity->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_activity_feed_includes_grouped_media_file_actions(): void
    {
        [$contact, $user] = $this->activityScenario();
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        DB::table('contact_media_group_activities')->insert([
            'group_id' => null,
            'contact_id' => $contact->id,
            'user_id' => $user->id,
            'action' => 'file_deleted',
            'before' => json_encode([
                'title' => 'Medical documents',
                'files' => [['file_name' => 'medical-license.pdf']],
            ]),
            'after' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson("/api/data-activity/{$contact->id}?page=1&perPage=10")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.activity_type', 'media')
            ->assertJsonPath('data.0.status', 'file_deleted')
            ->assertJsonPath('data.0.media_group.before.title', 'Medical documents')
            ->assertJsonPath('data.0.media_group.before.files.0.file_name', 'medical-license.pdf');
    }

    public function test_global_user_activity_feed_includes_media_file_edits(): void
    {
        [$contact, $user] = $this->activityScenario();
        $administrator = User::factory()->create(['access_type' => 'FULL']);

        DB::table('contact_media_group_activities')->insert([
            'group_id' => null,
            'contact_id' => $contact->id,
            'user_id' => $user->id,
            'action' => 'file_replaced',
            'before' => json_encode(['file_name' => 'old-license.pdf']),
            'after' => json_encode(['file_name' => 'new-license.pdf']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson('/api/user-activity?page=1&perPage=15&status=file_replaced')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.activity_type', 'media')
            ->assertJsonPath('data.0.status', 'file_replaced')
            ->assertJsonPath('data.0.data_id', $contact->id)
            ->assertJsonPath('data.0.media_group.before.file_name', 'old-license.pdf')
            ->assertJsonPath('data.0.media_group.after.file_name', 'new-license.pdf');
    }

    private function activityScenario(): array
    {
        $owner = User::factory()->create(['access_type' => 'FULL']);
        $user = User::factory()->create(['access_type' => 'RWE']);
        $otherUser = User::factory()->create(['access_type' => 'RWED']);
        $contact = CSVData::create([
            'firstname' => 'Activity',
            'lastname' => 'Contact',
            'email' => Str::lower(Str::random(12)).'@example.com',
            'phone' => '+9715'.random_int(10000000, 99999999),
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'user_id' => $owner->id,
        ]);

        return [$contact, $user, $otherUser];
    }

    private function createActivity(
        User $user,
        CSVData $contact,
        string $status,
        $createdAt
    ): UserActivity {
        return UserActivity::create([
            'user_id' => $user->id,
            'data_id' => $contact->id,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}
