<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('access_type')->default('R')->change();
            });
            return;
        }
        DB::statement("ALTER TABLE users MODIFY access_type ENUM('R', 'RW', 'RWE', 'RWED', 'FULL') DEFAULT 'R'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('access_type')->default('R')->change();
            });
            return;
        }
        DB::statement("ALTER TABLE users MODIFY access_type ENUM('R', 'RW', 'RWED', 'FULL') DEFAULT 'R'");
    }
};
