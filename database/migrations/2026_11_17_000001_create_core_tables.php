<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Объекты, контрагенты, договоры, закупки, документы, платежи, безопасность и журнал.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 200);
            $table->string('address', 300)->default('');
            $table->string('status', 20)->default('planning');
            $table->boolean('is_demo')->default(false);
            $table->string('customer_token', 60)->nullable()->unique();
            $table->string('camera_url', 500)->nullable();
            $table->string('camera_label', 120)->default('');
            $table->boolean('camera_public')->default(false);
            $table->decimal('contract_value', 14, 2)->default(0);
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('foreman_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name', 200);
            $table->string('kind', 20)->default('supplier');
            $table->string('inn', 20)->default('');
            $table->string('phone', 50)->default('');
            $table->string('email', 200)->default('');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->string('title', 300);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->date('due_date')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->string('item', 300);
            $table->decimal('quantity', 14, 3)->default(1);
            $table->string('unit', 30)->default('');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status', 20)->default('request');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('title', 300);
            $table->string('number', 50)->default('');
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('doc_type', 20)->default('other')->index();
            $table->string('category', 20)->default('');
            $table->string('status', 20)->default('draft')->index();
            $table->date('due_date')->nullable()->index();
            $table->integer('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->integer('version');
            $table->string('filename', 300);
            $table->string('stored_path', 400);
            $table->bigInteger('size');
            $table->unsignedBigInteger('uploaded_by');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('match', 10)->default('');
            $table->date('pay_date')->index();
            $table->decimal('amount', 14, 2);
            $table->string('direction', 10)->default('out');
            $table->text('purpose')->nullable();
            $table->string('party_name', 300)->default('');
            $table->string('party_inn', 20)->default('');
            $table->string('doc_number', 50)->default('');
            $table->string('dedup_key', 300);
            $table->dateTime('created_at')->nullable();
            $table->unique(['organization_id', 'dedup_key'], 'uq_payment_dedup');
        });

        Schema::create('rate_events', function (Blueprint $table) {
            $table->id();
            $table->string('bucket', 40);
            $table->string('key', 200);
            $table->dateTime('created_at')->nullable();
            $table->index(['bucket', 'key', 'created_at'], 'ix_rate_events_bucket_key');
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 30);
            $table->string('entity', 30);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->dateTime('created_at')->nullable()->index();
            $table->index(['organization_id', 'entity', 'entity_id'], 'ix_audit_entity');
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('ok')->default(true);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->dateTime('created_at')->nullable()->index();
        });

        Schema::create('telegram_leads', function (Blueprint $table) {
            $table->bigInteger('chat_id')->primary();
            $table->unsignedBigInteger('organization_id')->index();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('telegram_outbox', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->text('text');
            $table->integer('attempts')->default(0);
            $table->dateTime('next_try_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->boolean('failed')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->index(['sent_at', 'next_try_at'], 'ix_outbox_pending');
        });
    }

    public function down(): void
    {
        foreach (['telegram_outbox', 'telegram_leads', 'ai_usage', 'audit_events', 'password_resets', 'rate_events', 'payments',
            'document_approvals', 'document_versions', 'documents', 'purchases', 'contracts', 'counterparties', 'projects'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
