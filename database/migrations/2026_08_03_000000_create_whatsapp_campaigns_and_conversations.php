<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('template_name', 150);
            $table->string('template_language', 20);
            $table->string('template_category', 30)->nullable();
            $table->json('components')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('require_opt_in')->default(true);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('excluded_no_phone_count')->default(0);
            $table->unsignedInteger('excluded_duplicate_phone_count')->default(0);
            $table->unsignedInteger('excluded_no_consent_count')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->foreignId('csv_data_id')->nullable()->constrained('csv_data')->nullOnDelete();
            $table->string('phone', 32);
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->json('components')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('whatsapp_message_record_id')->nullable()->unique()
                ->constrained('whatsapp_messages')->nullOnDelete();
            $table->unsignedInteger('response_count')->default(0);
            $table->text('last_response_content')->nullable();
            $table->timestamp('last_responded_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['whatsapp_campaign_id', 'phone'], 'wa_campaign_recipient_phone_unique');
            $table->index(['whatsapp_campaign_id', 'status'], 'wa_campaign_recipient_status_index');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreignId('whatsapp_campaign_id')->nullable()->after('csv_data_id')
                ->constrained('whatsapp_campaigns')->nullOnDelete();
            $table->foreignId('whatsapp_campaign_recipient_id')->nullable()->after('whatsapp_campaign_id')
                ->constrained('whatsapp_campaign_recipients')->nullOnDelete();
            $table->string('reply_to_whatsapp_message_id', 255)->nullable()->after('whatsapp_message_id')->index();
            $table->timestamp('viewed_at')->nullable()->after('received_at')->index();
            $table->index(['phone', 'created_at'], 'wa_messages_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('wa_messages_conversation_index');
            $table->dropConstrainedForeignId('whatsapp_campaign_id');
            $table->dropConstrainedForeignId('whatsapp_campaign_recipient_id');
            $table->dropColumn(['reply_to_whatsapp_message_id', 'viewed_at']);
        });
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
