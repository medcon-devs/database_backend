<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sticky_notes', function (Blueprint $table) {
            $table->foreignId('media_group_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('contact_media_groups')
                ->nullOnDelete();
            $table->string('idempotency_key_hash', 64)->nullable()->after('content');

            $table->index(['contact_id', 'media_group_id', 'status', 'created_at'], 'sticky_notes_contact_group_status_idx');
            $table->unique(['created_by', 'idempotency_key_hash'], 'sticky_notes_creator_idempotency_unique');
        });

        Schema::create('sticky_note_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sticky_note_id')->constrained('sticky_notes')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->restrictOnDelete();
            $table->string('email_status', 30)->default('queued');
            $table->unsignedInteger('email_attempts')->default(0);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('email_failed_at')->nullable();
            $table->text('email_failure_reason')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['sticky_note_id', 'mentioned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticky_note_mentions');

        Schema::table('sticky_notes', function (Blueprint $table) {
            $table->dropUnique('sticky_notes_creator_idempotency_unique');
            $table->dropIndex('sticky_notes_contact_group_status_idx');
            $table->dropConstrainedForeignId('media_group_id');
            $table->dropColumn('idempotency_key_hash');
        });
    }
};
