<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('receive_notification')->default(false)->after('access_type');
        });

        // Users without a pending password link predate the invitation flow
        // and are already active accounts. Preserve that state when adding
        // explicit verification tracking.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNotIn('email', DB::table('password_resets')->select('email'))
            ->update(['email_verified_at' => now()]);

        DB::table('users')
            ->where('access_type', 'FULL')
            ->whereNotNull('email_verified_at')
            ->update(['receive_notification' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('receive_notification');
        });
    }
};
