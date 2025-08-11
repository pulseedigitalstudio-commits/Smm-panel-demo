<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('ticket_number')->unique();
            $table->string('subject');
            $table->text('message');
            $table->enum('status', ['open', 'answered', 'customer_reply', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('department', ['general', 'technical', 'billing', 'sales', 'support'])->default('general');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->unsignedBigInteger('last_reply_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('attachments')->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('last_reply_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');
            
            $table->index(['user_id', 'status']);
            $table->index(['status', 'priority']);
            $table->index(['department', 'status']);
            $table->index('assigned_to');
            $table->index('ticket_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tickets');
    }
};