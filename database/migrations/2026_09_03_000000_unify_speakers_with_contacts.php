<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $permissions = [
        'contacts.create' => ['Create contacts', 'records'],
        'contacts.archive' => ['Archive or reactivate contacts', 'records'],
        'contact_documents.view' => ['View contact document metadata', 'documents'],
        'contact_documents.upload' => ['Upload contact documents', 'documents'],
        'contact_documents.download' => ['Download general contact documents', 'documents'],
        'contact_documents.delete' => ['Delete contact documents', 'documents'],
        'contact_identity_documents.download' => ['Download passport and identity documents', 'documents'],
        'contact_bank_details.view' => ['View contact bank details', 'bank_details'],
        'contact_bank_details.edit' => ['Create and edit contact bank details', 'bank_details'],
        'contact_download_activity.view' => ['View contact document download activity', 'governance'],
        'contact_notifications.receive' => ['Receive sensitive contact document download notifications', 'governance'],
    ];

    public function up(): void
    {
        Schema::table('csv_data', function (Blueprint $table) {
            $table->string('microsoft_tenant_id', 64)->nullable()->index()->after('user_id');
            $table->string('professional_title')->nullable()->after('speciality');
            $table->text('biography')->nullable()->after('professional_title');
            $table->string('status', 30)->default('active')->index()->after('biography');
            $table->foreignId('profile_photo_file_id')->nullable()->after('status')->constrained('contact_media_files')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('profile_photo_file_id');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->foreignId('profile_created_by')->nullable()->after('archived_by')->constrained('users')->nullOnDelete();
            $table->foreignId('profile_updated_by')->nullable()->after('profile_created_by')->constrained('users')->nullOnDelete();
        });

        Schema::create('contact_bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('csv_data')->cascadeOnDelete();
            $table->text('account_holder_name')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('iban')->nullable();
            $table->text('swift_bic')->nullable();
            $table->text('bank_country')->nullable();
            $table->text('currency')->nullable();
            $table->text('bank_address')->nullable();
            $table->text('beneficiary_address')->nullable();
            $table->string('verification_status', 30)->default('unverified');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->foreignId('contact_media_file_id')->constrained('contact_media_files')->restrictOnDelete();
            $table->foreignId('downloaded_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('ip_address_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamp('notification_queued_at')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'downloaded_at'], 'contact_downloads_contact_date_idx');
        });

        $this->backfillContactData();
        $this->backfillBankDetails();
        $this->backfillDownloadActivity();
        $this->migratePermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::dropIfExists('contact_document_downloads');
        Schema::dropIfExists('contact_bank_details');
        Schema::table('csv_data', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_photo_file_id');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('profile_created_by');
            $table->dropConstrainedForeignId('profile_updated_by');
            $table->dropColumn(['microsoft_tenant_id', 'professional_title', 'biography', 'status', 'archived_at']);
        });
    }

    private function backfillContactData(): void
    {
        DB::table('csv_data')->orderBy('id')->chunkById(500, function ($contacts) {
            $tenants = DB::table('users')->whereIn('id', $contacts->pluck('user_id')->unique())->pluck('microsoft_tenant_id', 'id');
            foreach ($contacts as $contact) {
                DB::table('csv_data')->where('id', $contact->id)->update([
                    'microsoft_tenant_id' => $tenants[$contact->user_id] ?? null,
                ]);
            }
        });

        if (Schema::hasTable('speaker_profiles')) {
            DB::table('speaker_profiles')->orderBy('id')->chunkById(500, function ($profiles) {
                foreach ($profiles as $profile) {
                    DB::table('csv_data')->where('id', $profile->contact_id)->update([
                        'microsoft_tenant_id' => $profile->microsoft_tenant_id,
                        'professional_title' => $profile->professional_title,
                        'biography' => $profile->biography,
                        'status' => $profile->status,
                        'profile_photo_file_id' => $profile->profile_photo_file_id,
                        'archived_at' => $profile->status === 'archived' ? $profile->updated_at : null,
                        'archived_by' => $profile->status === 'archived' ? $profile->updated_by : null,
                        'profile_created_by' => $profile->created_by,
                        'profile_updated_by' => $profile->updated_by,
                    ]);
                }
            });
        }

        DB::table('csv_data')->whereNotNull('deleted_at')->update([
            'status' => 'archived',
            'archived_at' => DB::raw('deleted_at'),
        ]);
    }

    private function backfillBankDetails(): void
    {
        if (! Schema::hasTable('speaker_bank_details')) {
            return;
        }

        DB::table('speaker_bank_details')->orderBy('id')->chunkById(500, function ($rows) {
            $contactIds = DB::table('speaker_profiles')->whereIn('id', $rows->pluck('speaker_profile_id')->unique())->pluck('contact_id', 'id');
            foreach ($rows as $row) {
                $contactId = $contactIds[$row->speaker_profile_id] ?? null;
                if (! $contactId) {
                    continue;
                }
                DB::table('contact_bank_details')->insert([
                    'contact_id' => $contactId,
                    'account_holder_name' => $row->account_holder_name,
                    'bank_name' => $row->bank_name,
                    'account_number' => $row->account_number,
                    'iban' => $row->iban,
                    'swift_bic' => $row->swift_bic,
                    'bank_country' => $row->bank_country,
                    'currency' => $row->currency,
                    'bank_address' => $row->bank_address,
                    'beneficiary_address' => $row->beneficiary_address,
                    'verification_status' => $row->verification_status,
                    'updated_by' => $row->updated_by,
                    'verified_by' => $row->verified_by,
                    'verified_at' => $row->verified_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });
    }

    private function backfillDownloadActivity(): void
    {
        if (! Schema::hasTable('speaker_document_downloads')) {
            return;
        }

        DB::table('speaker_document_downloads')->orderBy('id')->chunkById(500, function ($rows) {
            $contactIds = DB::table('speaker_profiles')->whereIn('id', $rows->pluck('speaker_profile_id')->unique())->pluck('contact_id', 'id');
            foreach ($rows as $row) {
                $contactId = $contactIds[$row->speaker_profile_id] ?? null;
                if (! $contactId) {
                    continue;
                }
                DB::table('contact_document_downloads')->insert([
                    'contact_id' => $contactId,
                    'contact_media_file_id' => $row->contact_media_file_id,
                    'downloaded_by' => $row->downloaded_by,
                    'category' => $row->category,
                    'ip_address_hash' => $row->ip_address_hash,
                    'user_agent_hash' => $row->user_agent_hash,
                    'downloaded_at' => $row->downloaded_at,
                    'notification_queued_at' => $row->notification_queued_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });
    }

    private function migratePermissions(): void
    {
        foreach ($this->permissions as $name => [$label, $subgroup]) {
            DB::table('permissions')->updateOrInsert(['name' => $name], [
                'label' => $label,
                'group' => 'contacts',
                'subgroup' => $subgroup,
            ]);
        }

        foreach (['contacts.view', 'contacts.edit', 'contacts.import', 'contacts.export'] as $name) {
            DB::table('permissions')->where('name', $name)->update(['group' => 'contacts', 'subgroup' => 'records']);
        }

        $mappings = [
            'speakers.view' => 'contacts.view',
            'speakers.create' => 'contacts.create',
            'contacts.import' => 'contacts.create',
            'speakers.edit' => 'contacts.edit',
            'speakers.archive' => 'contacts.archive',
            'contacts.delete' => 'contacts.archive',
            'contacts.restore' => 'contacts.archive',
            'speakers.export' => 'contacts.export',
            'speaker_documents.view' => 'contact_documents.view',
            'speaker_documents.upload' => 'contact_documents.upload',
            'speaker_documents.download' => 'contact_documents.download',
            'speaker_documents.delete' => 'contact_documents.delete',
            'speaker_identity_documents.download' => 'contact_identity_documents.download',
            'speaker_bank_details.view' => 'contact_bank_details.view',
            'speaker_bank_details.edit' => 'contact_bank_details.edit',
            'speaker_download_activity.view' => 'contact_download_activity.view',
            'speaker_notifications.receive' => 'contact_notifications.receive',
        ];

        foreach ($mappings as $old => $new) {
            $oldId = DB::table('permissions')->where('name', $old)->value('id');
            $newId = DB::table('permissions')->where('name', $new)->value('id');
            if (! $oldId || ! $newId) {
                continue;
            }
            foreach (DB::table('permission_role')->where('permission_id', $oldId)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $newId]);
            }
        }

        $retiredNames = array_values(array_filter(array_keys($mappings), fn ($name) => $name !== 'contacts.import'));
        $retiredIds = DB::table('permissions')->whereIn('name', $retiredNames)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $retiredIds)->delete();
        DB::table('permissions')->whereIn('id', $retiredIds)->delete();

        $newIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        foreach (DB::table('roles')->where('is_super_admin', true)->pluck('id') as $roleId) {
            foreach ($newIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }
};
