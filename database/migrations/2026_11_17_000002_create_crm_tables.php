<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Воронки, сделки, задачи, планы продаж, табель.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 100);
            $table->integer('position')->default(0);
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->string('name', 100);
            $table->integer('position')->default(0);
            $table->string('kind', 10)->default('open');
        });

        Schema::create('stage_automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('stage_id')->index();
            $table->string('action', 20);
            $table->json('params')->nullable();
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->unsignedBigInteger('stage_id')->index();
            $table->string('title', 300);
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedBigInteger('responsible_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('address', 300)->default('');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->boolean('unsorted')->default(false)->index();
            $table->string('source', 20)->default('manual');
            $table->string('offer_token', 60)->nullable()->unique();
            $table->text('offer_note')->nullable();
            $table->date('offer_valid_until')->nullable();
            $table->dateTime('offer_accepted_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('deal_id')->index();
            $table->integer('position')->default(0);
            $table->string('title', 300);
            $table->decimal('quantity', 14, 3)->default(1);
            $table->string('unit', 30)->default('');
            $table->decimal('price', 14, 2)->default(0);
        });

        Schema::create('deal_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 20);
            $table->text('text');
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('month', 7);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unique(['organization_id', 'month'], 'uq_goal_month');
        });

        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('title', 300);
            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->boolean('done')->default(false)->index();
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 200);
            $table->string('position', 100)->default('');
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('worker_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->date('work_date')->index();
            $table->decimal('hours', 5, 2);
            $table->decimal('rate', 10, 2)->default(0);
            $table->string('note', 300)->default('');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['worker_id', 'project_id', 'work_date'], 'uq_time_worker_project_day');
        });
    }

    public function down(): void
    {
        foreach (['time_entries', 'workers', 'todos', 'goals', 'deal_events', 'deal_items', 'deals', 'stage_automations', 'stages', 'pipelines'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
