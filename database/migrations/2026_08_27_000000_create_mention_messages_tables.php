<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mention_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('context_type', 80);
            $table->unsignedBigInteger('context_id');
            $table->text('message');
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['context_type', 'context_id', 'created_at'], 'mention_messages_context_index');
            $table->unique(['sender_id', 'idempotency_key_hash'], 'mention_messages_sender_idempotency_unique');
        });

        Schema::create('message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mention_messages')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'mentioned_user_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_mention_id')->constrained('message_mentions')->cascadeOnDelete();
            $table->string('channel', 30)->default('email');
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
            $table->unique(['message_mention_id', 'channel']);
        });

        $permissionIds = [];
        foreach (['mentions.read' => 'Read messages and mentions', 'mentions.create' => 'Create messages and mention users'] as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], [
                'label' => $label, 'group' => 'mentions', 'subgroup' => null,
            ]);
            $permissionIds[$name] = DB::table('permissions')->where('name', $name)->value('id');
        }

        $roleNames = ['super_administrator', 'contact_editor', 'contact_manager'];
        foreach (DB::table('roles')->whereIn('name', $roleNames)->pluck('id', 'name') as $name => $roleId) {
            foreach ($permissionIds as $permissionName => $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
        $viewerRoleIds = DB::table('roles')->whereIn('name', ['viewer', 'contact_importer'])->pluck('id');
        foreach ($viewerRoleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds['mentions.read']]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', ['mentions.read', 'mentions.create'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('message_mentions');
        Schema::dropIfExists('mention_messages');
    }
};
