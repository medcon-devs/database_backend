<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserActivityTable extends Migration
{
    public function up()
    {
        Schema::create('user_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('data_id');
            $table->enum('status', ['delete', 'update']);
            $table->timestamps(); // This includes the created_at column

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('data_id')->references('id')->on('csv_data')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_activity');
    }
}
