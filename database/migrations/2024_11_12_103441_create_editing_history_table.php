<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEditingHistoryTable extends Migration
{
    public function up()
    {
        Schema::create('history_editing', function (Blueprint $table) {
            $table->id();
            $table->string('firstname');
            $table->string('lastname');
            $table->string('email');
            $table->string('country');
            $table->string('speciality');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('data_id');
            $table->timestamps();

            $table->foreign('data_id')->references('id')->on('csv_data')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('history_editing');
    }
}
