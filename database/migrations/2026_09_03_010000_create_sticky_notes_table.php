<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sticky_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('csv_data')->cascadeOnDelete();
            $table->text('content');
            $table->string('status', 20)->default('active');
            $table->timestamp('archive_due_at');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['contact_id', 'status', 'created_at']);
            $table->index(['status', 'archive_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticky_notes');
    }
};
