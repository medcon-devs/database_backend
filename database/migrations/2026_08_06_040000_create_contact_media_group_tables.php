<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_media_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->string('title');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['contact_id', 'created_at']);
        });
        Schema::create('contact_media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contact_media_groups')->cascadeOnDelete();
            $table->text('file_url');
            $table->string('cloudinary_public_id', 500);
            $table->string('file_name')->nullable();
            $table->string('resource_type', 30)->default('raw');
            $table->string('format', 30)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['group_id', 'created_at']);
        });
        Schema::create('contact_media_group_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained('contact_media_groups')->nullOnDelete();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });

        DB::table('contact_media')->whereNull('deleted_at')->orderBy('id')->each(function ($media) {
            $groupId = DB::table('contact_media_groups')->insertGetId([
                'contact_id' => $media->contact_id, 'title' => $media->title, 'note' => $media->note,
                'created_by' => $media->uploaded_by, 'created_at' => $media->created_at, 'updated_at' => $media->updated_at,
            ]);
            DB::table('contact_media_files')->insert([
                'group_id' => $groupId, 'file_url' => $media->file_url,
                'cloudinary_public_id' => $media->cloudinary_public_id, 'file_name' => $media->file_name,
                'resource_type' => $media->resource_type, 'format' => $media->format,
                'mime_type' => $media->mime_type, 'file_size' => $media->file_size,
                'created_at' => $media->created_at, 'updated_at' => $media->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_media_group_activities');
        Schema::dropIfExists('contact_media_files');
        Schema::dropIfExists('contact_media_groups');
    }
};
