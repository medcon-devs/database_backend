<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_super_admin')->default(false);
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('group');
            $table->string('subgroup')->nullable();
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('access_type')->constrained()->nullOnDelete();
        });

        $permissionIds = [];
        foreach (config('permissions.groups') as $group => $definition) {
            $sets = isset($definition['subgroups']) ? $definition['subgroups'] : [null => $definition];
            foreach ($sets as $subgroup => $set) {
                foreach ($set['permissions'] as $name => $label) {
                    $permissionIds[$name] = DB::table('permissions')->insertGetId([
                        'name' => $name,
                        'label' => $label,
                        'group' => $group,
                        'subgroup' => $subgroup ?: null,
                    ]);
                }
            }
        }

        $all = array_keys($permissionIds);
        $roleDefinitions = [
            'viewer' => ['Viewer', ['contacts.view', 'tags.view', 'contact_media.view', 'activity.view_own']],
            'contact_importer' => ['Contact Importer', ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'tags.view', 'contact_media.view', 'activity.view_own']],
            'contact_editor' => ['Contact Editor', ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'contacts.edit', 'tags.view', 'tags.manage_contacts', 'contact_media.view', 'contact_media.upload', 'contact_media.edit', 'activity.view_own', 'activity.view_contact']],
            'contact_manager' => ['Contact Manager', ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'contacts.edit', 'contacts.archive', 'tags.view', 'tags.manage', 'tags.manage_contacts', 'tags.export', 'contact_media.view', 'contact_media.upload', 'contact_media.edit', 'contact_media.delete', 'contact_media.restore', 'activity.view_own', 'activity.view_contact']],
            'super_administrator' => ['Super Administrator', $all],
        ];
        $legacy = ['R' => 'viewer', 'RW' => 'contact_importer', 'RWE' => 'contact_editor', 'RWED' => 'contact_manager', 'FULL' => 'super_administrator'];
        $roleIds = [];
        foreach ($roleDefinitions as $name => [$label, $permissionNames]) {
            $roleIds[$name] = DB::table('roles')->insertGetId([
                'name' => $name, 'label' => $label, 'is_system' => true,
                'is_super_admin' => $name === 'super_administrator', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($permissionNames as $permissionName) {
                DB::table('permission_role')->insert(['role_id' => $roleIds[$name], 'permission_id' => $permissionIds[$permissionName]]);
            }
        }
        foreach ($legacy as $accessType => $roleName) {
            DB::table('users')->where('access_type', $accessType)->update(['role_id' => $roleIds[$roleName]]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('role_id'));
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
