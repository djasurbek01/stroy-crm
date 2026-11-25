<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Стройка: этапы, задачи по объекту, фото, проектная документация, чат, лента,
| уведомления, приёмка, финансы объекта, склад и сметы.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('name', 200);
            $table->integer('position')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('percent')->default(0);
            $table->string('note', 500)->default('');
            $table->dateTime('created_at')->nullable();
            $table->unsignedBigInteger('depends_on_id')->nullable();
            $table->string('delay_reason', 300)->default('');
            $table->dateTime('accepted_at')->nullable();
            $table->unsignedBigInteger('responsible_id')->nullable();
        });

        Schema::create('site_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('kind', 10)->default('task');
            $table->string('side', 12)->default('contractor');
            $table->string('source', 10)->default('staff');
            $table->string('title', 300);
            $table->string('note', 1000)->default('');
            $table->string('author', 120)->default('');
            $table->date('due_date')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('status', 18)->default('new');
            $table->string('priority', 8)->default('normal');
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->date('start_date')->nullable();
            $table->string('reason', 300)->default('');
            $table->boolean('requires_review')->default(false);
            $table->unsignedBigInteger('stage_id')->nullable();
        });

        Schema::create('site_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('stage_id')->nullable()->index();
            $table->string('kind', 10);
            $table->string('path', 300);
            $table->string('mime', 60);
            $table->integer('size');
            $table->string('caption', 300)->default('');
            $table->string('author', 120)->default('');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('project_docs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('title', 300);
            $table->string('section', 60)->default('');
            $table->string('filename', 300);
            $table->string('path', 400);
            $table->integer('size');
            $table->integer('version')->default(1);
            $table->string('status', 12)->default('on_review');
            $table->dateTime('released_at')->nullable();
            $table->string('released_by', 120)->default('');
            $table->string('created_by', 120)->default('');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('doc_visas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('doc_id')->index();
            $table->integer('version')->default(1);
            $table->integer('position')->default(0);
            $table->string('label', 120);
            $table->string('side', 10)->default('staff');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('decision', 10)->default('pending');
            $table->string('comment', 1000)->default('');
            $table->string('decided_by', 120)->default('');
            $table->dateTime('decided_at')->nullable();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('side', 10)->default('staff');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author', 120);
            $table->string('body', 2000);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('chat_seen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->integer('last_id')->default(0);
            $table->unique(['user_id', 'project_id'], 'uq_chat_seen_user_project');
        });

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('kind', 20);
            $table->string('text', 300);
            $table->string('actor', 120)->default('');
            $table->dateTime('created_at')->nullable()->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('text', 300);
            $table->string('link', 200)->default('');
            $table->string('dedupe_key', 120)->nullable();
            $table->dateTime('created_at')->nullable()->index();
            $table->dateTime('read_at')->nullable();
            $table->unique(['user_id', 'dedupe_key'], 'uq_notification_user_dedupe');
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('stage_id')->index();
            $table->string('status', 10)->default('requested');
            $table->string('requested_by', 120)->default('');
            $table->string('inspector', 120)->default('');
            $table->date('scheduled_date')->nullable();
            $table->dateTime('inspected_at')->nullable();
            $table->string('notes', 1000)->default('');
            $table->date('fix_by')->nullable();
            $table->unsignedBigInteger('previous_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('inspection_remarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('inspection_id')->index();
            $table->string('text', 500);
            $table->unsignedBigInteger('report_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('payment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('title', 200);
            $table->date('due_date')->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('received', 14, 2)->default(0);
            $table->date('received_on')->nullable();
            $table->string('note', 300)->default('');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('category', 16);
            $table->decimal('planned', 14, 2)->default(0);
            $table->unique(['project_id', 'category'], 'uq_budget_project_category');
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 200);
            $table->string('unit', 30)->default('шт');
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->unique(['organization_id', 'name'], 'uq_material_org_name');
        });

        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->string('item', 200);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 30)->default('');
            $table->decimal('issued', 14, 3)->default(0);
            $table->date('needed_by')->nullable();
            $table->string('reason', 300)->default('');
            $table->string('status', 10)->default('new');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->string('created_by', 120)->default('');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('material_id')->index();
            $table->string('kind', 10);
            $table->decimal('quantity', 14, 3);
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->string('doc_number', 50)->default('');
            $table->string('note', 300)->default('');
            $table->date('moved_on')->index();
            $table->string('created_by', 120)->default('');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('title', 200);
            $table->integer('version')->default(1);
            $table->string('status', 10)->default('draft');
            $table->decimal('markup_pct', 6, 2)->default(0);
            $table->decimal('discount_pct', 6, 2)->default(0);
            $table->decimal('extra', 14, 2)->default(0);
            $table->string('note', 1000)->default('');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('created_by', 120)->default('');
            $table->string('approved_by', 120)->default('');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('estimate_id')->index();
            $table->integer('position')->default(0);
            $table->string('section', 16);
            $table->string('title', 300);
            $table->decimal('quantity', 14, 3)->default(1);
            $table->string('unit', 30)->default('');
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedBigInteger('material_id')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['estimate_items', 'estimates', 'stock_movements', 'material_requests', 'materials', 'budget_lines', 'payment_plan_items',
            'inspection_remarks', 'inspections', 'notifications', 'activity_events', 'chat_seen', 'chat_messages', 'doc_visas',
            'project_docs', 'site_reports', 'site_tasks', 'work_stages'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
