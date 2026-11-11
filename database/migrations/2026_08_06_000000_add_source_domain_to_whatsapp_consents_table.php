<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_consents', function (Blueprint $table) {
            $table->string('source_domain', 255)->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_consents', function (Blueprint $table) {
            $table->dropIndex(['source_domain']);
            $table->dropColumn('source_domain');
        });
    }
};
