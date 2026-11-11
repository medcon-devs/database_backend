<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CsvUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge();
        $this->createTestSchema();
        Mail::fake();
        config(['scout.driver' => 'null']);
    }

    public function test_upload_accepts_utf8_bom_and_header_aliases(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $email = Str::lower(Str::random(12)).'@example.test';
        $csv = "\xEF\xBB\xBFFIRST_NAME,LAST_NAME,EMAIL,PHONE,COUNTRY,SPECIALTY\r\n"
            ."Ali,Ahmad,{$email},+971501234567,United Arab Emirates,Cardiology\r\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_added', 1)
            ->assertJsonPath('data.rows_encoding_converted', 0);

        $this->assertDatabaseHas('csv_data', [
            'email' => $email,
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
    }

    public function test_upload_converts_windows_1252_values_to_utf8(): void
    {
        [, $token] = $this->authenticatedUser();
        $email = Str::lower(Str::random(12)).'@example.test';
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n"
            ."Amine,Test,{$email},123456789,Alg\xE9rie,Cardiology\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('windows.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_added', 1)
            ->assertJsonPath('data.rows_encoding_converted', 1);

        $this->assertDatabaseHas('csv_data', [
            'email' => $email,
            'country' => "Alg\u{00E9}rie",
        ]);
    }

    public function test_upload_ignores_scientific_phone_and_imports_the_other_values(): void
    {
        [, $token] = $this->authenticatedUser();
        $validEmail = Str::lower(Str::random(12)).'@example.test';
        $invalidEmail = Str::lower(Str::random(12)).'@example.test';
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n"
            ."Valid,Contact,{$validEmail},971501234567,UAE,Cardiology\n"
            ."Broken,Phone,{$invalidEmail},9.72E+11,UAE,Cardiology\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('scientific.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_added', 2)
            ->assertJsonPath('data.phone_values_ignored', 1)
            ->assertJsonPath('data.phone_ignored_rows.0', 3)
            ->assertJsonPath(
                'message',
                'CSV data imported successfully. 1 phone value(s) in scientific notation were ignored.'
            );

        $this->assertDatabaseHas('csv_data', [
            'email' => $validEmail,
            'phone' => '971501234567',
        ]);
        $this->assertDatabaseHas('csv_data', [
            'email' => $invalidEmail,
            'phone' => null,
        ]);
    }

    public function test_upload_reports_swapped_country_and_speciality_columns(): void
    {
        [, $token] = $this->authenticatedUser();
        $email = Str::lower(Str::random(12)).'@example.test';
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n"
            ."Imad,Toor,{$email},557637178,Aviation medicine,United Arab Emirates\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('swapped.csv', $csv),
        ])->assertUnprocessable()
            ->assertJsonPath('data.errors.0.row', 2)
            ->assertJsonPath('data.errors.0.column', 'COUNTRY')
            ->assertJsonPath('data.errors.0.message', 'COUNTRY and SPECIALITY appear to be swapped.');
    }

    public function test_upload_reports_missing_headers_and_malformed_row_width(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent(
                'missing.csv',
                "FIRST NAME,LAST NAME,EMAIL,COUNTRY\nAli,A,ali@example.test,UAE\n"
            ),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'CSV_VALIDATION_ERROR')
            ->assertJsonPath(
                'message',
                'Missing columns: SPECIALITY. Row 1: Required columns are FIRST NAME, LAST NAME, EMAIL, COUNTRY, SPECIALITY. PHONE is optional.'
            );

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent(
                'width.csv',
                "FIRST NAME,LAST NAME,EMAIL,COUNTRY,SPECIALITY\nAli,A,ali@example.test,UAE\n"
            ),
        ])->assertUnprocessable()
            ->assertJsonPath('data.errors.0.row', 2)
            ->assertJsonPath('data.errors.0.message', 'Expected 5 columns but found 4.');
    }

    public function test_upload_still_updates_only_non_empty_fields(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $email = Str::lower(Str::random(12)).'@example.test';
        $contact = CSVData::create([
            'firstname' => 'Old',
            'lastname' => 'Preserved',
            'email' => $email,
            'phone' => '123',
            'country' => 'UAE',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n"
            ."New,,{$email},9.72E+11,,Dermatology\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('update.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_updated', 1)
            ->assertJsonPath('data.phone_values_ignored', 1);

        $contact->refresh();
        $this->assertSame('New', $contact->firstname);
        $this->assertSame('Preserved', $contact->lastname);
        $this->assertSame('123', $contact->phone);
        $this->assertSame('UAE', $contact->country);
        $this->assertSame('Dermatology', $contact->speciality);
        $this->assertDatabaseHas('history_editing', [
            'data_id' => $contact->id,
            'firstname' => 'Old',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('user_activity', [
            'data_id' => $contact->id,
            'user_id' => $user->id,
            'status' => 'update',
        ]);
    }

    public function test_upload_merges_duplicate_emails_before_bulk_writing(): void
    {
        [, $token] = $this->authenticatedUser();
        $email = Str::lower(Str::random(12)).'@example.test';
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n"
            ."First,Original,{$email},123,UAE,Cardiology\n"
            ."Updated,,{$email},9.72E+11,,Dermatology\n";

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('duplicates.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_added', 1)
            ->assertJsonPath('data.duplicate_rows_merged', 1)
            ->assertJsonPath('data.phone_values_ignored', 1);

        $this->assertDatabaseCount('csv_data', 1);
        $this->assertDatabaseHas('csv_data', [
            'email' => $email,
            'firstname' => 'Updated',
            'lastname' => 'Original',
            'phone' => '123',
            'country' => 'UAE',
            'speciality' => 'Dermatology',
        ]);
    }

    public function test_upload_writes_a_large_csv_in_database_batches(): void
    {
        [, $token] = $this->authenticatedUser();
        $csv = "FIRST NAME,LAST NAME,EMAIL,PHONE,COUNTRY,SPECIALITY\n";
        for ($index = 1; $index <= 1200; $index++) {
            $csv .= "Doctor,{$index},bulk{$index}@example.test,971500{$index},UAE,Cardiology\n";
        }

        $this->withToken($token)->post('/api/upload-csv', [
            'file' => UploadedFile::fake()->createWithContent('large.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.rows_added', 1200)
            ->assertJsonPath('data.rows_updated', 0);

        $this->assertDatabaseCount('csv_data', 1200);
    }

    public function test_ai_search_can_return_only_contacts_with_phone_numbers(): void
    {
        [$user, $token] = $this->authenticatedUser();
        Http::preventStrayRequests();
        config([
            'services.openai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
        ]);
        $withPhone = CSVData::create([
            'firstname' => 'Has',
            'lastname' => 'Phone',
            'email' => 'has-phone@example.test',
            'phone' => '+971501234567',
            'country' => 'UAE',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
        CSVData::create([
            'firstname' => 'No',
            'lastname' => 'Phone',
            'email' => 'no-phone@example.test',
            'phone' => null,
            'country' => 'UAE',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
        $deletedWithPhone = CSVData::create([
            'firstname' => 'Deleted',
            'lastname' => 'With Phone',
            'email' => 'deleted-phone@example.test',
            'phone' => '+971509999999',
            'country' => 'UAE',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
        $deletedWithPhone->delete();

        $response = $this->withToken($token)->postJson('/api/ai-search', [
            'query' => 'find all where phone not null',
            'perPage' => 100,
        ]);

        $response->assertOk()
            ->assertJsonPath('interpretation.search_text', '')
            ->assertJsonPath('interpretation.phone_filter', 'has_phone')
            ->assertJsonPath('interpretation.parser', 'local')
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $withPhone->id])
            ->assertJsonFragment(['id' => $deletedWithPhone->id]);

        Http::assertNothingSent();
    }

    public function test_large_tag_membership_is_inserted_in_database_batches(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $tag = Tag::create([
            'name' => 'Bulk tag',
            'slug' => 'bulk-tag',
            'created_by' => $user->id,
        ]);
        $timestamp = now();
        $rows = [];

        for ($index = 1; $index <= 12000; $index++) {
            $rows[] = [
                'firstname' => 'Bulk',
                'lastname' => (string) $index,
                'email' => "tag-bulk-{$index}@example.test",
                'phone' => null,
                'country' => 'UAE',
                'speciality' => 'Cardiology',
                'user_id' => $user->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('csv_data')->insert($chunk);
        }
        $contactIds = DB::table('csv_data')->orderBy('id')->pluck('id')->all();

        $this->withToken($token)->postJson("/api/tags/{$tag->id}/contacts", [
            'contact_ids' => $contactIds,
        ])->assertOk()
            ->assertJsonPath('data.attached_contacts', 12000)
            ->assertJsonPath('data.tag.contacts_count', 12000);

        $this->assertSame(12000, DB::table('csv_data_tag')->where('tag_id', $tag->id)->count());
    }

    private function authenticatedUser(): array
    {
        $user = User::factory()->create(['access_type' => 'FULL']);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createTestSchema(): void
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
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
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

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color')->default('#2563EB');
            $table->text('description')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('csv_data_tag', function (Blueprint $table) {
            $table->foreignId('csv_data_id');
            $table->foreignId('tag_id');
            $table->timestamps();
            $table->unique(['csv_data_id', 'tag_id']);
        });
    }
}
