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
        DB::statement("ALTER TABLE users MODIFY access_type ENUM('R', 'RW', 'RWE', 'RWED', 'FULL') DEFAULT 'R'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("ALTER TABLE users MODIFY access_type ENUM('R', 'RW', 'RWED', 'FULL') DEFAULT 'R'");
    }
};
