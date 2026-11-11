<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('source_domain', 255);
            $table->string('external_reference', 255)->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('country')->nullable();
            $table->string('speciality')->nullable();
            $table->string('submission_type', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('matched_csv_data_id')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();

            $table->unique(['source_domain', 'external_reference']);
            $table->index(['status', 'submission_type']);
            $table->index('email');
            $table->foreign('matched_csv_data_id')->references('id')->on('csv_data')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
