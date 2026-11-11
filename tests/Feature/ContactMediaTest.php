<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ContactMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'scout.driver' => 'null', 'mail.default' => 'array',
            'services.cloudinary.cloud_name' => 'medcon-cloud', 'services.cloudinary.api_key' => 'key', 'services.cloudinary.api_secret' => 'secret', 'services.cloudinary.base_folder' => 'database']);
        DB::purge();
        app('mail.manager')->purge('array');
        $this->schema();
    }

    public function test_grouped_media_workflow_and_secure_cloudinary_deletion(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['result' => 'ok'])]);
        $admin = User::factory()->create(['access_type' => 'FULL', 'receive_notification' => true, 'email_verified_at' => now()]);
        $contact = CSVData::create(['firstname' => 'Majd', 'lastname' => 'Doctor', 'email' => 'media@example.test', 'country' => 'UAE', 'speciality' => 'Cardiology', 'user_id' => $admin->id]);
        $token = JWTAuth::fromUser($admin);
        $file = fn ($name) => ['file_url' => "https://res.cloudinary.com/medcon-cloud/raw/upload/{$name}.pdf", 'cloudinary_public_id' => "database/contacts/{$contact->id}/{$name}", 'file_name' => "{$name}.pdf", 'resource_type' => 'raw', 'format' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 1234];

        $created = $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Medical documents', 'note' => 'Licenses', 'files' => [$file('license'), $file('certificate')]])
            ->assertCreated()->assertJsonPath('data.files_count', 2);
        $groupId = $created->json('data.id');
        $fileId = $created->json('data.files.0.id');

        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/media-groups?search=license")
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.files_count', 2);
        $this->withToken($token)->patchJson("/api/contacts/{$contact->id}/media-groups/{$groupId}", ['title' => 'Updated documents'])->assertOk();
        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files", ['files' => [$file('new-file')]])->assertOk()->assertJsonPath('data.files_count', 3);
        $this->withToken($token)->patchJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files/{$fileId}", $file('replacement'))->assertOk()->assertJsonPath('data.file_name', 'replacement.pdf');
        $this->withToken($token)->deleteJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files/{$fileId}")->assertOk();
        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/media-groups/deleted")
            ->assertOk()->assertJsonPath('data.0.type', 'file')->assertJsonPath('data.0.restorable', true);
        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files/{$fileId}/restore")
            ->assertOk()->assertJsonPath('data.id', $fileId);
        $this->withToken($token)->deleteJson("/api/contacts/{$contact->id}/media-groups/{$groupId}")->assertOk();
        $this->assertSoftDeleted('contact_media_groups', ['id' => $groupId]);
        $this->withToken($token)->getJson("/api/contacts/{$contact->id}/media-groups/deleted")
            ->assertOk()->assertJsonPath('data.0.type', 'group')->assertJsonPath('data.0.restorable', true);
        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/restore")
            ->assertOk()->assertJsonPath('data.files_count', 3);

        $this->assertNotSoftDeleted('contact_media_groups', ['id' => $groupId]);
        $this->assertDatabaseCount('contact_media_group_activities', 8);
        $this->assertDatabaseHas('contact_media_group_activities', ['action' => 'file_restored']);
        $this->assertDatabaseHas('contact_media_group_activities', ['action' => 'restored']);
        Http::assertSentCount(1);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('/destroy', $request->url());
            $this->assertArrayHasKey('signature', $request->data());
            $this->assertArrayNotHasKey('api_secret', $request->data());
        }
    }

    public function test_folders_can_be_created_empty_and_files_can_be_added_later(): void
    {
        $admin = User::factory()->create(['access_type' => 'FULL']);
        $contact = CSVData::create(['firstname' => 'Folder', 'lastname' => 'Doctor', 'email' => 'folder@example.test', 'speciality' => 'Cardiology', 'user_id' => $admin->id]);
        $token = JWTAuth::fromUser($admin);
        $file = ['file_url' => 'https://res.cloudinary.com/medcon-cloud/raw/upload/license.pdf', 'cloudinary_public_id' => "database/contacts/{$contact->id}/license", 'file_name' => 'license.pdf', 'resource_type' => 'raw', 'format' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 125000];

        $withoutFiles = $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Empty folder', 'note' => null])
            ->assertCreated()->assertJsonPath('message', 'Folder created successfully.')
            ->assertJsonPath('data.files_count', 0)->assertJsonCount(0, 'data.files');

        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Empty array', 'note' => 'Add files later.', 'files' => []])
            ->assertCreated()->assertJsonPath('data.files_count', 0)->assertJsonCount(0, 'data.files');

        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'With files', 'files' => [$file]])
            ->assertCreated()->assertJsonPath('data.files_count', 1)->assertJsonCount(1, 'data.files');

        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$withoutFiles->json('data.id')}/files", ['files' => [$file]])
            ->assertOk()->assertJsonPath('data.files_count', 1)->assertJsonCount(1, 'data.files');

        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$withoutFiles->json('data.id')}/files", ['files' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_empty_folder_creation_still_enforces_permission_and_contact_existence(): void
    {
        $admin = User::factory()->create(['access_type' => 'FULL']);
        $readOnly = User::factory()->create(['access_type' => 'R']);
        $contact = CSVData::create(['firstname' => 'Protected', 'lastname' => 'Doctor', 'email' => 'protected-folder@example.test', 'speciality' => 'Cardiology', 'user_id' => $admin->id]);

        $this->withToken(JWTAuth::fromUser($readOnly))->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Not allowed'])
            ->assertForbidden();
        $this->withToken(JWTAuth::fromUser($admin))->postJson('/api/contacts/999999/media-groups', ['title' => 'Missing contact'])
            ->assertNotFound();
    }

    public function test_group_file_validation_is_indexed(): void
    {
        $admin = User::factory()->create(['access_type' => 'FULL']);
        $contact = CSVData::create(['firstname' => 'A', 'lastname' => 'B', 'email' => 'a@example.test', 'speciality' => 'Cardiology', 'user_id' => $admin->id]);
        $this->withToken(JWTAuth::fromUser($admin))->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Docs', 'files' => [['file_url' => 'not-cloudinary', 'cloudinary_public_id' => 'wrong', 'resource_type' => 'raw']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['files.0.file_url', 'files.0.cloudinary_public_id']);
    }

    public function test_expired_deleted_asset_is_purged_and_cannot_be_restored(): void
    {
        config(['contact_media.recovery_days' => 1]);
        Http::fake(['api.cloudinary.com/*' => Http::response(['result' => 'ok'])]);
        $admin = User::factory()->create(['access_type' => 'FULL']);
        $contact = CSVData::create(['firstname' => 'A', 'lastname' => 'B', 'email' => 'expired@example.test', 'speciality' => 'Cardiology', 'user_id' => $admin->id]);
        $token = JWTAuth::fromUser($admin);
        $file = [
            'file_url' => 'https://res.cloudinary.com/medcon-cloud/raw/upload/expired.pdf',
            'cloudinary_public_id' => "database/contacts/{$contact->id}/expired",
            'file_name' => 'expired.pdf',
            'resource_type' => 'raw',
            'file_size' => 1234,
        ];

        $created = $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups", ['title' => 'Expired', 'files' => [$file]])->assertCreated();
        $groupId = $created->json('data.id');
        $fileId = $created->json('data.files.0.id');
        $this->withToken($token)->deleteJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files/{$fileId}")->assertOk();
        DB::table('contact_media_files')->where('id', $fileId)->update(['deleted_at' => now()->subDays(2)]);

        $this->artisan('contact-media:purge-expired')->assertSuccessful();
        $this->assertDatabaseMissing('contact_media_files', ['id' => $fileId, 'asset_purged_at' => null]);
        $this->withToken($token)->postJson("/api/contacts/{$contact->id}/media-groups/{$groupId}/files/{$fileId}/restore")
            ->assertStatus(409)->assertJsonPath('code', 'MEDIA_ASSET_NOT_RECOVERABLE');
    }

    private function schema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('remember_token')->nullable();
            $t->boolean('is_banned')->default(false);
            $t->string('access_type')->default('R');
            $t->boolean('receive_notification')->default(false);
            $t->timestamps();
        });
        Schema::create('csv_data', function (Blueprint $t) {
            $t->id();
            $t->string('firstname');
            $t->string('lastname');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('country')->nullable();
            $t->string('speciality');
            $t->foreignId('user_id');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('contact_media_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id');
            $t->string('title');
            $t->text('note')->nullable();
            $t->foreignId('created_by');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('contact_media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id');
            $t->text('file_url');
            $t->string('cloudinary_public_id');
            $t->string('file_name')->nullable();
            $t->string('resource_type')->default('raw');
            $t->string('format')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->boolean('deleted_via_group')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->timestamp('asset_purged_at')->nullable();
        });
        Schema::create('contact_media_group_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->nullable();
            $t->foreignId('contact_id');
            $t->foreignId('user_id');
            $t->string('action');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
        });
    }
}
