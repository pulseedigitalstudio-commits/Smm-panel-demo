<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->unique()->nullable();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->boolean('status')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('api_token', 80)->unique()->nullable();
            $table->boolean('is_reseller')->default(false);
            $table->decimal('reseller_percentage', 5, 2)->default(0.00);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('avatar')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('language')->default('en');
            $table->string('currency')->default('USD');
            $table->string('referral_code')->unique()->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
};