<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHistoryEditingIdToUserActivityTable extends Migration
{
    public function up()
    {
        Schema::table('user_activity', function (Blueprint $table) {
            $table->unsignedBigInteger('history_editing_id')->nullable()->after('data_id');
            $table->foreign('history_editing_id')->references('id')->on('history_editing')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('user_activity', function (Blueprint $table) {
            $table->dropForeign(['history_editing_id']);
            $table->dropColumn('history_editing_id');
        });
    }
}