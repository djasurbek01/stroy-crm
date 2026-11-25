<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Перенос данных из базы прежней версии (SQLite stroy.db) и её папки с файлами.
 * Номера записей сохраняются, поэтому ссылки между таблицами и пути к файлам остаются верными.
 * Переносится только в пустую базу, чтобы не смешать данные.
 */
#[Signature('stroy:import-sqlite {path : путь к stroy.db} {--storage= : папка storage прежней версии (файлы документов)}')]
#[Description('Перенести данные из stroy.db прежней версии на Python')]
class ImportSqlite extends Command
{
    /** Таблицы в порядке переноса. rate_events не переносим: это временные счётчики попыток входа. */
    private const TABLES = [
        'organizations', 'users', 'projects', 'counterparties', 'contracts', 'purchases', 'documents', 'document_versions',
        'document_approvals', 'payments', 'password_resets', 'audit_events', 'ai_usage', 'telegram_leads', 'telegram_outbox',
        'pipelines', 'stages', 'stage_automations', 'deals', 'deal_items', 'deal_events', 'goals', 'todos', 'workers', 'time_entries',
        'work_stages', 'site_tasks', 'site_reports', 'project_docs', 'doc_visas', 'chat_messages', 'chat_seen', 'activity_events',
        'notifications', 'inspections', 'inspection_remarks', 'payment_plan_items', 'budget_lines', 'materials', 'material_requests',
        'stock_movements', 'estimates', 'estimate_items',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: $path");

            return self::FAILURE;
        }
        if (DB::table('organizations')->exists()) {
            $this->error('В базе уже есть данные. Перенос делается только в пустую базу (php artisan migrate:fresh).');

            return self::FAILURE;
        }
        $old = new PDO('sqlite:'.$path);
        $old->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $oldTables = $old->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        DB::transaction(function () use ($old, $oldTables) {
            foreach (self::TABLES as $table) {
                if (! in_array($table, $oldTables, true)) {
                    continue;
                }
                $columns = array_intersect(
                    array_column($old->query("PRAGMA table_info(\"$table\")")->fetchAll(), 'name'),
                    Schema::getColumnListing($table),
                );
                $types = collect(Schema::getColumns($table))->pluck('type_name', 'name');
                $rows = $old->query('SELECT "'.implode('","', $columns).'" FROM "'.$table.'"')->fetchAll();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert(array_map(fn ($row) => $this->convert($table, $row, $types), $chunk));
                }
                $this->line(sprintf('  %-20s %d', $table, count($rows)));
            }
        });

        if ($storage = $this->option('storage')) {
            $target = storage_path('app/private/'.trim(config('stroy.storage_dir'), '/'));
            File::copyDirectory($storage, $target);
            $this->line('  файлы скопированы в '.$target);
        }
        $this->info('Перенос завершён.');

        return self::SUCCESS;
    }

    private function convert(string $table, array $row, $types): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && in_array($types[$column] ?? '', ['datetime', 'timestamp'], true)) {
                $row[$column] = substr($value, 0, 19);  // без долей секунды и часового пояса
            }
        }
        // bcrypt из Python ($2b$) и PHP ($2y$) — один и тот же алгоритм, отличается только метка
        if ($table === 'users' && str_starts_with($row['password_hash'] ?? '', '$2b$')) {
            $row['password_hash'] = '$2y$'.substr($row['password_hash'], 4);
        }

        return $row;
    }
}
