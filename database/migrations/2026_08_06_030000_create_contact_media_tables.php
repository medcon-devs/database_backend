<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->string('title');
            $table->text('note')->nullable();
            $table->text('file_url');
            $table->string('cloudinary_public_id', 500);
            $table->string('file_name')->nullable();
            $table->string('resource_type', 30)->default('raw');
            $table->string('format', 30)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'created_at']);
        });

        Schema::create('contact_media_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_media_id')->nullable()->constrained('contact_media')->nullOnDelete();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_media_activities');
        Schema::dropIfExists('contact_media');
    }
};
