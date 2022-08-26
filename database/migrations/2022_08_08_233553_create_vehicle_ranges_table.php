<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehicleRangesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vehicle_ranges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('year_min');
            $table->unsignedSmallInteger('year_max');
            $table->unsignedBigInteger('price_min');
            $table->unsignedBigInteger('price_max');
            $table->unsignedInteger('count');
            $table->boolean('is_completed')->default(0);
            $table->unsignedSmallInteger('fetched_pages')->default(0);
            $table->unsignedSmallInteger('empty_entries')->default(0);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('vehicle_ranges');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_ranges');
    }
}
