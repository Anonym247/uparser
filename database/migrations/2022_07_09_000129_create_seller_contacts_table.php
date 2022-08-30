<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSellerContactsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('seller_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedSmallInteger('area_code');
            $table->string('local_number');
            $table->string('phone_type')->nullable();
            $table->timestamps();

            $table->foreign('seller_id')->references('id')->on('sellers');
            $table->unique(['seller_id', 'area_code', 'local_number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('seller_contacts');
    }
}
