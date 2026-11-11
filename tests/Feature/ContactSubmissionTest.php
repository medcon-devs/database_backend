<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ContactSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'scout.driver' => 'null',
        ]);
        DB::purge();
        $this->createSchema();
    }

    public function test_public_submission_is_staged_and_never_writes_directly_to_contacts(): void
    {
        $this->postJson('/api/v1/contact-submissions', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.submission_type', 'new');

        $this->assertDatabaseCount('contact_submissions', 1);
        $this->assertDatabaseCount('csv_data', 0);
    }

    public function test_existing_email_is_classified_as_update_and_returns_field_differences(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        CSVData::create([
            'firstname' => 'Old', 'lastname' => 'Name', 'email' => 'doctor@example.test',
            'phone' => '971500000001', 'country' => 'UAE', 'speciality' => 'Cardiology',
            'user_id' => $administrator->id,
        ]);
        $submissionId = $this->postJson('/api/v1/contact-submissions', $this->payload([
            'firstname' => 'New', 'email' => 'DOCTOR@example.test',
        ]))->assertStatus(202)->assertJsonPath('data.submission_type', 'update')->json('data.submission_id');

        $this->withToken(JWTAuth::fromUser($administrator))
            ->getJson("/api/contact-submissions/{$submissionId}")
            ->assertOk()
            ->assertJsonPath('data.differences.firstname.current', 'Old')
            ->assertJsonPath('data.differences.firstname.incoming', 'New')
            ->assertJsonPath('data.differences.firstname.changed', true)
            ->assertJsonPath('data.differences.email.changed', false);
    }

    public function test_administrator_can_migrate_selected_new_and_updated_submissions(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        CSVData::create([
            'firstname' => 'Old', 'lastname' => 'Doctor', 'email' => 'existing@example.test',
            'phone' => '971500000001', 'country' => 'UAE', 'speciality' => 'Cardiology',
            'user_id' => $administrator->id,
        ]);
        $newId = $this->postJson('/api/v1/contact-submissions', $this->payload([
            'email' => 'new@example.test', 'external_reference' => 'registration-new',
        ]))->json('data.submission_id');
        $updateId = $this->postJson('/api/v1/contact-submissions', $this->payload([
            'email' => 'existing@example.test', 'firstname' => 'Updated',
            'external_reference' => 'registration-update',
        ]))->json('data.submission_id');

        $this->withToken(JWTAuth::fromUser($administrator))
            ->postJson('/api/contact-submissions/migrate', ['ids' => [$newId, $updateId]])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.processed', 2);

        $this->assertDatabaseHas('csv_data', ['email' => 'new@example.test']);
        $this->assertDatabaseHas('csv_data', ['email' => 'existing@example.test', 'firstname' => 'Updated']);
        $this->assertDatabaseCount('history_editing', 1);
        $this->assertDatabaseCount('user_activity', 1);
        $this->assertDatabaseHas('contact_submissions', ['id' => $newId, 'status' => 'migrated']);
    }

    public function test_select_all_respects_filters_and_reject_keeps_the_main_database_unchanged(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $this->postJson('/api/v1/contact-submissions', $this->payload([
            'email' => 'one@example.test', 'source_domain' => 'webinar-one.test', 'external_reference' => '1',
        ]));
        $this->postJson('/api/v1/contact-submissions', $this->payload([
            'email' => 'two@example.test', 'source_domain' => 'webinar-two.test', 'external_reference' => '2',
        ]));

        $this->withToken(JWTAuth::fromUser($administrator))
            ->deleteJson('/api/contact-submissions', ['all' => true, 'source_domain' => 'webinar-one.test'])
            ->assertOk()
            ->assertJsonPath('data.rejected', 1);

        $this->assertDatabaseHas('contact_submissions', ['source_domain' => 'webinar-one.test', 'status' => 'rejected']);
        $this->assertDatabaseHas('contact_submissions', ['source_domain' => 'webinar-two.test', 'status' => 'pending']);
        $this->assertDatabaseCount('csv_data', 0);
    }

    public function test_non_administrator_cannot_review_or_migrate_submissions(): void
    {
        $user = User::factory()->create(['access_type' => 'R']);

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/contact-submissions')
            ->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'source_domain' => 'webinar.example.test',
            'external_reference' => 'registration-123',
            'firstname' => 'New',
            'lastname' => 'Doctor',
            'email' => 'doctor@example.test',
            'phone' => '+971501234567',
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'metadata' => ['webinar_id' => 45],
        ], ...$overrides];
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->string('access_type')->default('R');
            $table->boolean('receive_notification')->default(false);
            $table->timestamps();
        });
        Schema::create('csv_data', function (Blueprint $table) {
            $table->id();
            $table->string('firstname');
            $table->string('lastname');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality');
            $table->foreignId('user_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('history_editing', function (Blueprint $table) {
            $table->id();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->foreignId('user_id');
            $table->foreignId('data_id');
            $table->timestamps();
        });
        Schema::create('user_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('data_id');
            $table->string('status');
            $table->foreignId('history_editing_id')->nullable();
            $table->timestamps();
        });
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('source_domain');
            $table->string('external_reference')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->string('submission_type');
            $table->string('status')->default('pending');
            $table->foreignId('matched_csv_data_id')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
            $table->unique(['source_domain', 'external_reference']);
        });
    }
}
