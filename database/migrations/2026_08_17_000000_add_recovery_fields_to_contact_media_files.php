<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_media_files', function (Blueprint $table) {
            $table->boolean('deleted_via_group')->default(false)->after('file_size');
            $table->timestamp('asset_purged_at')->nullable()->after('deleted_at');
            $table->index(['deleted_at', 'asset_purged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_media_files', function (Blueprint $table) {
            $table->dropIndex(['deleted_at', 'asset_purged_at']);
            $table->dropColumn(['deleted_via_group', 'asset_purged_at']);
        });
    }
};
