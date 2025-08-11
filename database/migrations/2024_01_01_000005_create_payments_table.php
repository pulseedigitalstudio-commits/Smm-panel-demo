<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_method');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded', 'expired', 'cancelled'])->default('pending');
            $table->string('transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->decimal('gateway_fee', 10, 2)->default(0.00);
            $table->decimal('processing_fee', 10, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('refund_amount', 15, 2)->default(0.00);
            $table->text('refund_reason')->nullable();
            $table->json('webhook_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
            
            $table->index(['user_id', 'status']);
            $table->index(['payment_method', 'status']);
            $table->index('transaction_id');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};