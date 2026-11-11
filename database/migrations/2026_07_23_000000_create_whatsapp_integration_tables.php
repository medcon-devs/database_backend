<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_data_id')
                ->nullable()
                ->constrained('csv_data')
                ->nullOnDelete();
            $table->string('phone', 32)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->string('scope', 50)->default('marketing');
            $table->string('source', 50);
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 30)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_data_id')
                ->nullable()
                ->constrained('csv_data')
                ->nullOnDelete();
            $table->string('whatsapp_message_id', 255)->nullable()->unique();
            $table->string('phone', 32)->index();
            $table->string('direction', 10)->index();
            $table->string('type', 30)->default('text');
            $table->string('template_name', 150)->nullable();
            $table->string('template_language', 20)->nullable();
            $table->string('status', 30)->index();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_consent_id')
                ->constrained('whatsapp_consents')
                ->cascadeOnDelete();
            $table->foreignId('whatsapp_message_record_id')
                ->nullable()
                ->constrained('whatsapp_messages')
                ->nullOnDelete();
            $table->string('event', 30);
            $table->string('source', 50);
            $table->string('evidence_reference', 255)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_consent_events');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_consents');
    }
};
