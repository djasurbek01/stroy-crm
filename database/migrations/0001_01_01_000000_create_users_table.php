<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Организации, пользователи и сессии входа.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('plan', 20)->default('start');
            $table->string('public_key', 60)->nullable()->unique();
            $table->string('phone', 50)->default('');
            $table->string('email', 200)->default('');
            $table->text('details')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('email', 200)->unique();
            $table->string('password_hash', 200);
            $table->string('name', 200);
            $table->string('role', 20)->default('owner');
            $table->integer('token_version')->default(0);
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->string('telegram_link_code', 40)->nullable()->index();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
    }
};
