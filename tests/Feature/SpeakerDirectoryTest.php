<?php

namespace Tests\Feature;

use App\Jobs\SendContactDownloadNotification;
use App\Models\ContactBankDetail;
use App\Models\ContactDocumentDownload;
use App\Models\CSVData;
use App\Models\ContactMediaGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeakerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SpeakerDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_speaker_profile_and_encrypted_bank_details_contract(): void
    {
        $user = $this->userWithPermissions(['contacts.create', 'contacts.view', 'contact_bank_details.edit', 'contact_bank_details.view'], 'tenant-a');
        $contact = $this->contact($user);
        $token = JWTAuth::fromUser($user);
        $speakerId = $this->withToken($token)->postJson('/api/speakers', [
            'contact_id' => $contact->id, 'professional_title' => 'Dr.', 'biography' => 'International cardiology speaker.',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/speakers/{$speakerId}/bank-details", [
            'account_holder_name' => 'Test Doctor', 'bank_name' => 'Example Bank', 'iban' => 'AE070331234567890123456',
            'swift_bic' => 'EBILAEAD', 'currency' => 'AED', 'verification_status' => 'verified',
        ])->assertOk()->assertJsonPath('data.iban', 'AE070331234567890123456');

        $raw = ContactBankDetail::where('contact_id', $contact->id)->getQuery()->first();
        $this->assertNotSame('AE070331234567890123456', $raw->iban);
        $this->withToken($token)->getJson("/api/speakers/{$speakerId}/bank-details")
            ->assertOk()->assertJsonPath('data.bank_name', 'Example Bank');
    }

    public function test_speaker_access_is_tenant_isolated(): void
    {
        $owner = $this->userWithPermissions(['contacts.create'], 'tenant-a');
        $speaker = SpeakerProfile::create(['contact_id' => $this->contact($owner)->id, 'microsoft_tenant_id' => 'tenant-a', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        $outsider = $this->userWithPermissions(['contacts.view'], 'tenant-b');
        $this->withToken(JWTAuth::fromUser($outsider))->getJson("/api/speakers/{$speaker->id}")->assertNotFound();
    }

    public function test_sensitive_download_is_authorized_audited_and_does_not_expose_storage_url(): void
    {
        Queue::fake();
        Http::fake(['https://res.cloudinary.com/*' => Http::response('passport-bytes', 200, ['Content-Type' => 'application/pdf'])]);
        $user = $this->userWithPermissions(['contact_documents.view', 'contact_documents.download', 'contact_identity_documents.download'], 'tenant-a');
        $contact = $this->contact($user);
        $speaker = SpeakerProfile::create(['contact_id' => $contact->id, 'microsoft_tenant_id' => 'tenant-a', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $group = ContactMediaGroup::create(['contact_id' => $contact->id, 'title' => 'Passport', 'category' => 'passport_id', 'sensitivity' => 'restricted', 'created_by' => $user->id]);
        $file = $group->files()->create(['file_url' => 'https://res.cloudinary.com/demo/raw/upload/passport.pdf', 'cloudinary_public_id' => 'database/contacts/'.$contact->id.'/passport', 'file_name' => 'passport.pdf', 'resource_type' => 'raw', 'mime_type' => 'application/pdf']);
        $token = JWTAuth::fromUser($user);

        $list = $this->withToken($token)->getJson("/api/speakers/{$speaker->id}/documents")->assertOk();
        $this->assertArrayNotHasKey('file_url', $list->json('data.0.files.0'));
        $this->assertArrayNotHasKey('cloudinary_public_id', $list->json('data.0.files.0'));

        $this->withToken($token)->get("/api/speakers/{$speaker->id}/documents/{$file->id}/download")->assertOk();
        $this->assertDatabaseHas('contact_document_downloads', ['contact_id' => $contact->id, 'contact_media_file_id' => $file->id, 'downloaded_by' => $user->id, 'category' => 'passport_id']);
        Queue::assertPushed(SendContactDownloadNotification::class);
    }

    public function test_passport_download_requires_additional_identity_permission(): void
    {
        $user = $this->userWithPermissions(['contact_documents.download'], 'tenant-a');
        $contact = $this->contact($user);
        $speaker = SpeakerProfile::create(['contact_id' => $contact->id, 'microsoft_tenant_id' => 'tenant-a', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $group = ContactMediaGroup::create(['contact_id' => $contact->id, 'title' => 'Passport', 'category' => 'passport_id', 'sensitivity' => 'restricted', 'created_by' => $user->id]);
        $file = $group->files()->create(['file_url' => 'https://res.cloudinary.com/demo/raw/upload/passport.pdf', 'cloudinary_public_id' => 'database/contacts/'.$contact->id.'/passport', 'file_name' => 'passport.pdf', 'resource_type' => 'raw']);
        $this->withToken(JWTAuth::fromUser($user))->get("/api/speakers/{$speaker->id}/documents/{$file->id}/download")->assertForbidden();
        $this->assertSame(0, ContactDocumentDownload::count());
    }

    private function userWithPermissions(array $permissions, ?string $tenant): User
    {
        $role = Role::create(['name' => 'speaker_test_'.Str::lower(Str::random(12)), 'label' => 'Speaker Test']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        return User::factory()->create(['role_id' => $role->id, 'access_type' => 'R', 'microsoft_tenant_id' => $tenant]);
    }

    private function contact(User $user): CSVData
    {
        return CSVData::create(['firstname' => 'Test', 'lastname' => 'Speaker', 'email' => Str::uuid().'@example.test', 'country' => 'UAE', 'speciality' => 'Cardiology', 'user_id' => $user->id, 'microsoft_tenant_id' => $user->microsoft_tenant_id]);
    }
}
