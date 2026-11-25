<?php

namespace App\Support;

use App\Models\DocVisa;
use App\Models\Inspection;
use App\Models\Project;
use App\Models\ProjectDoc;
use App\Models\SiteReport;
use App\Models\SiteTask;
use App\Models\User;
use App\Models\WorkStage;
use Carbon\Carbon;

/** Общее для кабинета подрядчика и страницы заказчика: график, задачи, фото, проектные документы, камера. */
class Site
{
    public const PRIORITY_RANK = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    // ---------- график работ ----------

    public static function stageStatus(WorkStage $s): string
    {
        return $s->percent >= 100 ? 'done' : ($s->percent > 0 ? 'in_progress' : 'not_started');
    }

    /** Длительность этапа в днях (включая оба конца). Без дат считаем один день. */
    public static function duration(WorkStage $s): int
    {
        if ($s->start_date && $s->end_date) {
            return max((int) $s->start_date->diffInDays($s->end_date, false) + 1, 1);
        }

        return 1;
    }

    /** Готовность объекта с учётом объёма этапов: длинный этап весит больше короткого. */
    public static function overallPercent($stages): int
    {
        if (count($stages) === 0) {
            return 0;
        }
        $sum = 0;
        $weights = 0;
        foreach ($stages as $s) {
            $w = $s->start_date && $s->end_date ? self::duration($s) : 1;
            $sum += $s->percent * $w;
            $weights += $w;
        }

        return self::pyRound($sum / $weights);
    }

    /** Округление «к чётному», как round() в прежней версии. */
    public static function pyRound(float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Прогноз окончания каждого этапа: начатый этап не может закончиться раньше, чем останется работы
     * от сегодняшнего дня, а зависимый этап не начнётся, пока не закончится тот, от которого он зависит.
     */
    public static function forecastEnds($stages, ?Carbon $today = null): array
    {
        $today ??= today();
        $result = [];
        foreach ($stages as $stage) {
            if ($stage->end_date === null) {
                $result[$stage->id] = null;

                continue;
            }
            $start = $stage->start_date ?? $stage->end_date;
            $end = $stage->end_date->copy();
            if ($stage->percent < 100 && $start->lte($today)) {
                $remaining = max((int) ceil(self::duration($stage) * (100 - $stage->percent) / 100), 1);
                $candidate = $today->copy()->addDays($remaining - 1);
                if ($candidate->gt($end)) {
                    $end = $candidate;
                }
            }
            $depEnd = $stage->depends_on_id ? ($result[$stage->depends_on_id] ?? null) : null;
            if ($stage->percent < 100 && $depEnd && $start->lte($depEnd)) {
                $shift = (int) $start->diffInDays($depEnd->copy()->addDay(), false);
                $candidate = $stage->end_date->copy()->addDays($shift);
                if ($candidate->gt($end)) {
                    $end = $candidate;
                }
            }
            $result[$stage->id] = $end;
        }

        return $result;
    }

    public static function context(int $projectId): array
    {
        $stages = WorkStage::where('project_id', $projectId)->orderBy('position')->orderBy('id')->get();
        $orgId = $stages->first()?->organization_id;
        $names = $orgId ? User::where('organization_id', $orgId)->pluck('name', 'id')->all() : [];
        $inspections = [];
        foreach (Inspection::where('project_id', $projectId)->orderBy('id')->get() as $item) {
            $inspections[$item->stage_id] = $item->status;  // последняя по порядку: актуальное состояние
        }

        return ['stages' => $stages, 'by_id' => $stages->keyBy('id'), 'forecast' => self::forecastEnds($stages), 'names' => $names, 'inspections' => $inspections];
    }

    public static function stageOut(WorkStage $stage, array $ctx, bool $internal = true): array
    {
        $today = today();
        $overdue = $stage->end_date && $stage->end_date->lt($today) && $stage->percent < 100;
        $dep = $stage->depends_on_id ? ($ctx['by_id'][$stage->depends_on_id] ?? null) : null;
        $forecast = $ctx['forecast'][$stage->id] ?? null;
        $out = [
            'id' => $stage->id,
            'project_id' => $stage->project_id,
            'name' => $stage->name,
            'start_date' => Fmt::d($stage->start_date),
            'end_date' => Fmt::d($stage->end_date),
            'percent' => $stage->percent,
            'status' => self::stageStatus($stage),
            'overdue' => (bool) $overdue,
            'note' => $stage->note,
            'delay_reason' => $stage->delay_reason,
            'delay_days' => $overdue ? (int) $stage->end_date->diffInDays($today) : 0,
            'forecast_end' => Fmt::d($forecast),
            'blocked_by' => $dep !== null && $dep->percent < 100 && $stage->percent < 100 ? $dep->name : null,
            'accepted' => $stage->accepted_at !== null,
            'accepted_at' => Fmt::dt($stage->accepted_at),
            'inspection' => $ctx['inspections'][$stage->id] ?? null,
        ];
        if ($internal) {
            $depEnd = $dep ? ($ctx['forecast'][$dep->id] ?? $dep->end_date) : null;
            $out += [
                'depends_on_id' => $stage->depends_on_id,
                'responsible_id' => $stage->responsible_id,
                'responsible_name' => $stage->responsible_id ? ($ctx['names'][$stage->responsible_id] ?? '') : '',
                // начало этапа раньше, чем (по прогнозу) освободится тот, от которого он зависит
                'conflict' => (bool) ($dep !== null && $dep->percent < 100 && $stage->start_date && $depEnd && $depEnd->gte($stage->start_date)),
                'needs_reason' => (bool) ($overdue && ! $stage->delay_reason),
            ];
        }

        return $out;
    }

    public static function summary(array $ctx): array
    {
        $ends = $ctx['stages']->pluck('end_date')->filter();
        $forecasts = array_filter($ctx['forecast']);
        $planned = $ends->isEmpty() ? null : $ends->max();
        $forecast = $forecasts ? max($forecasts) : null;

        return [
            'planned_end' => Fmt::d($planned),
            'forecast_end' => Fmt::d($forecast),
            'deviation_days' => $planned && $forecast ? (int) $planned->diffInDays($forecast, false) : null,
        ];
    }

    public static function schedule(Project $project): array
    {
        $ctx = self::context($project->id);

        return [
            'stages' => $ctx['stages']->map(fn ($s) => self::stageOut($s, $ctx))->all(),
            'overall' => self::overallPercent($ctx['stages']),
            'customer_token' => $project->customer_token,
        ] + self::summary($ctx);
    }

    // ---------- задачи по объекту ----------

    /** internal=false: то, что видит заказчик (без хода работ). internal=true: кабинет подрядчика. */
    public static function taskOut(SiteTask $task, bool $internal = false, array $names = []): array
    {
        $overdue = $task->due_date && $task->due_date->lt(today()) && $task->done_at === null;
        $out = [
            'id' => $task->id,
            'kind' => $task->kind,
            'side' => $task->side,
            'source' => $task->source,
            'title' => $task->title,
            'note' => $task->note,
            'author' => $task->author,
            'due_date' => Fmt::d($task->due_date),
            'done' => $task->done_at !== null,
            'done_at' => Fmt::dt($task->done_at),
            'overdue' => (bool) $overdue,
        ];
        if ($internal) {
            $out += [
                'status' => $task->status,
                'priority' => $task->priority,
                'assignee_id' => $task->assignee_id,
                'assignee_name' => $task->assignee_id ? ($names[$task->assignee_id] ?? '') : '',
                'start_date' => Fmt::d($task->start_date),
                'reason' => $task->reason,
                'requires_review' => (bool) $task->requires_review,
                'stage_id' => $task->stage_id,
            ];
        }

        return $out;
    }

    /** Сначала невыполненные, по сроку и важности; выполненные в конце. */
    public static function projectTasks(int $projectId)
    {
        return SiteTask::where('project_id', $projectId)->get()->sort(function ($a, $b) {
            return [$a->done_at !== null, $a->due_date?->toDateString() ?? '9999-12-31', self::PRIORITY_RANK[$a->priority] ?? 2, $a->id]
                <=> [$b->done_at !== null, $b->due_date?->toDateString() ?? '9999-12-31', self::PRIORITY_RANK[$b->priority] ?? 2, $b->id];
        })->values();
    }

    // ---------- фото и видео ----------

    public static function reportOut(SiteReport $report, ?string $publicPrefix = null): array
    {
        return [
            'id' => $report->id,
            'stage_id' => $report->stage_id,
            'kind' => $report->kind,
            'caption' => $report->caption,
            'author' => $report->author,
            'created_at' => Fmt::dt($report->created_at),
            'url' => $publicPrefix ? "$publicPrefix/{$report->id}/file" : "/reports/{$report->id}/file",
        ];
    }

    public static function projectReports(int $projectId, ?int $stageId = null)
    {
        $query = SiteReport::where('project_id', $projectId);
        if ($stageId !== null) {
            $query->where('stage_id', $stageId);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    // ---------- проектная документация ----------

    public static function visas(ProjectDoc $doc, bool $allVersions = false)
    {
        $query = DocVisa::where('doc_id', $doc->id);
        if (! $allVersions) {
            $query->where('version', $doc->version);
        }

        return $query->orderBy('version')->orderBy('position')->orderBy('id')->get();
    }

    /** Следующая к подписанию виза. Если кто-то отказал, цепочка стоит. */
    public static function activeVisa($visas): ?DocVisa
    {
        foreach ($visas as $v) {
            if ($v->decision === 'rejected') {
                return null;
            }
            if ($v->decision === 'pending') {
                return $v;
            }
        }

        return null;
    }

    public static function docOut(ProjectDoc $doc, ?string $publicPrefix = null): array
    {
        $visas = self::visas($doc);
        $active = self::activeVisa($visas);
        $internal = $publicPrefix === null;

        return [
            'id' => $doc->id,
            'project_id' => $doc->project_id,
            'title' => $doc->title,
            'section' => $doc->section,
            'filename' => $doc->filename,
            'version' => $doc->version,
            'status' => $doc->status,
            'created_by' => $doc->created_by,
            'created_at' => Fmt::dt($doc->created_at),
            'released_at' => Fmt::dt($doc->released_at),
            'released_by' => $doc->released_by,
            'url' => $internal ? "/proj-docs/{$doc->id}/file" : "$publicPrefix/{$doc->id}/file",
            'active_visa_id' => $active?->id,
            'visas' => $visas->map(fn ($v) => [
                'id' => $v->id, 'label' => $v->label, 'side' => $v->side, 'decision' => $v->decision, 'comment' => $v->comment,
                'decided_by' => $v->decided_by, 'decided_at' => Fmt::dt($v->decided_at),
            ] + ($internal ? ['user_id' => $v->user_id] : []))->all(),
        ];
    }

    // ---------- камера ----------

    public static function cameraOut(Project $project): array
    {
        return ['url' => $project->camera_url, 'label' => $project->camera_label, 'public' => (bool) $project->camera_public];
    }

    /** Заказчик видит камеру, только если подрядчик это разрешил. */
    public static function publicCamera(Project $project): ?array
    {
        if ($project->camera_url && $project->camera_public) {
            return ['url' => $project->camera_url, 'label' => $project->camera_label ?: 'Камера на объекте'];
        }

        return null;
    }

    /** Объект по ссылке заказчика или 404 link_not_found. */
    public static function projectByToken(?string $token): Project
    {
        $project = $token && strlen($token) <= 60 ? Project::where('customer_token', $token)->first() : null;
        if ($project === null) {
            ApiError::abort(404, 'link_not_found');
        }

        return $project;
    }
}
