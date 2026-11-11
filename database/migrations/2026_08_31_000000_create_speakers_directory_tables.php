<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $recoveringPartialRun = Schema::hasTable('speaker_profiles')
            && Schema::hasTable('speaker_bank_details')
            && Schema::hasTable('speaker_document_downloads')
            && Schema::hasColumn('contact_media_groups', 'category')
            && Schema::hasColumn('contact_media_groups', 'sensitivity');

        if (! $recoveringPartialRun) {
        Schema::create('speaker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('csv_data')->cascadeOnDelete();
            $table->string('microsoft_tenant_id', 64)->nullable()->index();
            $table->string('professional_title')->nullable();
            $table->text('biography')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('profile_photo_file_id')->nullable()->constrained('contact_media_files')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('speaker_bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaker_profile_id')->unique()->constrained('speaker_profiles')->cascadeOnDelete();
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

        Schema::table('contact_media_groups', function (Blueprint $table) {
            $table->string('category', 40)->default('other')->after('note')->index();
            $table->string('sensitivity', 30)->default('normal')->after('category');
        });

        Schema::create('speaker_document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaker_profile_id')->constrained('speaker_profiles')->cascadeOnDelete();
            $table->foreignId('contact_media_file_id')->constrained('contact_media_files')->restrictOnDelete();
            $table->foreignId('downloaded_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('ip_address_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamp('notification_queued_at')->nullable();
            $table->timestamps();
            $table->index(['speaker_profile_id', 'downloaded_at'], 'speaker_downloads_profile_date_idx');
        });
        } else {
            Schema::table('speaker_document_downloads', function (Blueprint $table) {
                $table->index(['speaker_profile_id', 'downloaded_at'], 'speaker_downloads_profile_date_idx');
            });
        }

        $superRoleId = DB::table('roles')->where('name', 'super_administrator')->value('id');
        $legacyPermissionGroups = [
            'profiles' => ['permissions' => [
                'speakers.view' => 'View speaker profiles', 'speakers.create' => 'Create speaker profiles',
                'speakers.edit' => 'Edit speaker profiles', 'speakers.archive' => 'Archive or reactivate speakers',
                'speakers.export' => 'Export speaker directory data',
            ]],
            'documents' => ['permissions' => [
                'speaker_documents.view' => 'View speaker document metadata', 'speaker_documents.upload' => 'Upload speaker documents',
                'speaker_documents.download' => 'Download general speaker documents', 'speaker_documents.delete' => 'Delete speaker documents',
                'speaker_identity_documents.download' => 'Download passport and identity documents',
            ]],
            'bank_details' => ['permissions' => [
                'speaker_bank_details.view' => 'View speaker bank details', 'speaker_bank_details.edit' => 'Create and edit speaker bank details',
            ]],
            'governance' => ['permissions' => [
                'speaker_download_activity.view' => 'View speaker document download activity',
                'speaker_notifications.receive' => 'Receive sensitive speaker download notifications',
            ]],
        ];
        foreach ($legacyPermissionGroups as $subgroup => $definition) {
            foreach ($definition['permissions'] as $name => $label) {
                DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'group' => 'speakers', 'subgroup' => $subgroup]);
                if ($superRoleId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $superRoleId, 'permission_id' => DB::table('permissions')->where('name', $name)->value('id')]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = collect([
            'speakers.view', 'speakers.create', 'speakers.edit', 'speakers.archive', 'speakers.export',
            'speaker_documents.view', 'speaker_documents.upload', 'speaker_documents.download', 'speaker_documents.delete',
            'speaker_identity_documents.download', 'speaker_bank_details.view', 'speaker_bank_details.edit',
            'speaker_download_activity.view', 'speaker_notifications.receive',
        ]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('speaker_document_downloads');
        Schema::table('contact_media_groups', fn (Blueprint $table) => $table->dropColumn(['category', 'sensitivity']));
        Schema::dropIfExists('speaker_bank_details');
        Schema::dropIfExists('speaker_profiles');
    }
};
