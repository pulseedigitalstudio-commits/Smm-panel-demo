<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('provider_id');
            $table->string('order_number')->unique();
            $table->string('link');
            $table->integer('quantity');
            $table->decimal('price', 10, 4);
            $table->decimal('total_price', 10, 4);
            $table->enum('status', ['pending', 'processing', 'completed', 'partial', 'cancelled', 'refunded'])->default('pending');
            $table->integer('start_count')->default(0);
            $table->integer('remains')->default(0);
            $table->string('provider_order_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('finish_time')->nullable();
            $table->boolean('drip_feed')->default(false);
            $table->integer('drip_feed_runs')->default(0);
            $table->integer('drip_feed_interval')->default(0);
            $table->integer('drip_feed_total_quantity')->default(0);
            $table->integer('drip_feed_processed')->default(0);
            $table->boolean('refill')->default(false);
            $table->integer('refill_count')->default(0);
            $table->boolean('cancel')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('api_order')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('providers')->onDelete('cascade');
            
            $table->index(['user_id', 'status']);
            $table->index(['service_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index('order_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
};