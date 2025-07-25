<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeCountryNullableInCsvDataTable extends Migration
{
    public function up()
    {
        Schema::table('csv_data', function (Blueprint $table) {
            $table->string('country')->nullable()->change();
            $table->string('speciality')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('csv_data', function (Blueprint $table) {
            $table->string('country')->nullable(false)->change();
            $table->string('speciality')->nullable(false)->change();
        });
    }
}
