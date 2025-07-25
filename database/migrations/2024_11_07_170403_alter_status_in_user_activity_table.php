<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterStatusInUserActivityTable extends Migration
{
    public function up()
    {
        Schema::table('user_activity', function (Blueprint $table) {
            // Drop the existing 'status' column
            $table->dropColumn('status');
        });

        Schema::table('user_activity', function (Blueprint $table) {
            // Re-add the 'status' column with the new enum options
            $table->enum('status', ['delete', 'update', 'restore'])->after('data_id');
        });
    }

    public function down()
    {
        Schema::table('user_activity', function (Blueprint $table) {
            // Drop the modified 'status' column
            $table->dropColumn('status');
        });

        Schema::table('user_activity', function (Blueprint $table) {
            // Re-add the original 'status' column with only 'delete' and 'update' options
            $table->enum('status', ['delete', 'update'])->after('data_id');
        });
    }
}
