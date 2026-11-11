<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider', 20)->default('local')->after('password');
            $table->string('microsoft_tenant_id', 64)->nullable()->after('auth_provider');
            $table->string('microsoft_oid', 64)->nullable()->after('microsoft_tenant_id');
            $table->timestamp('last_microsoft_login_at')->nullable()->after('microsoft_oid');

            $table->unique(
                ['microsoft_tenant_id', 'microsoft_oid'],
                'users_microsoft_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_microsoft_identity_unique');
            $table->dropColumn([
                'auth_provider',
                'microsoft_tenant_id',
                'microsoft_oid',
                'last_microsoft_login_at',
            ]);
        });
    }
};
