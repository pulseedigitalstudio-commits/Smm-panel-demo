<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('provider_id');
            $table->decimal('price', 10, 4);
            $table->decimal('reseller_price', 10, 4)->nullable();
            $table->integer('min_quantity');
            $table->integer('max_quantity');
            $table->boolean('drip_feed')->default(false);
            $table->boolean('refill')->default(false);
            $table->boolean('cancel')->default(false);
            $table->boolean('status')->default(true);
            $table->string('api_service_id')->nullable();
            $table->string('type')->default('default');
            $table->integer('posts')->default(1);
            $table->string('link')->nullable();
            $table->string('sample_link')->nullable();
            $table->json('features')->nullable();
            $table->integer('start_time')->default(0);
            $table->integer('speed')->default(1000);
            $table->integer('quality')->default(80);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('providers')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('services');
    }
};