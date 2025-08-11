<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('api_url');
            $table->string('api_key');
            $table->string('api_secret');
            $table->boolean('status')->default(true);
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->integer('min_order')->default(1);
            $table->integer('max_order')->default(1000000);
            $table->boolean('drip_feed')->default(false);
            $table->boolean('refill')->default(false);
            $table->boolean('cancel')->default(false);
            $table->boolean('test_mode')->default(false);
            $table->integer('timeout')->default(30);
            $table->integer('retry_attempts')->default(3);
            $table->timestamp('last_check')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('error_count')->default(0);
            $table->decimal('success_rate', 5, 2)->default(0.00);
            $table->integer('total_orders')->default(0);
            $table->integer('successful_orders')->default(0);
            $table->integer('failed_orders')->default(0);
            $table->integer('average_response_time')->default(0);
            $table->text('notes')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('support_hours')->nullable();
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('providers');
    }
};