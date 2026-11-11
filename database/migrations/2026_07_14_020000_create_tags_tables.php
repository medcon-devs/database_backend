<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color', 7)->default('#2563EB');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('csv_data_tag', function (Blueprint $table) {
            $table->foreignId('csv_data_id')->constrained('csv_data')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['csv_data_id', 'tag_id']);
            $table->index(['tag_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_data_tag');
        Schema::dropIfExists('tags');
    }
};
