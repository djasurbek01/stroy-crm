<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Участники объекта (кто видит объект) и ход задач: кто поставил, запрос на продление срока, история.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->dateTime('created_at')->nullable();
            $table->unique(['project_id', 'user_id'], 'uq_project_member');
        });

        Schema::table('site_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable();
            $table->date('extension_due')->nullable();
            $table->string('extension_reason', 500)->default('');
            $table->unsignedBigInteger('extension_by')->nullable();
            $table->dateTime('extension_at')->nullable();
        });

        Schema::create('site_task_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author', 120)->default('');
            $table->string('kind', 20);
            $table->string('text', 1000);
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_task_events');
        Schema::table('site_tasks', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'extension_due', 'extension_reason', 'extension_by', 'extension_at']);
        });
        Schema::dropIfExists('project_members');
    }
};
