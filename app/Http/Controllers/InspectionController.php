<?php

namespace App\Http\Controllers;

use App\Models\Inspection;
use App\Models\InspectionRemark;
use App\Models\Project;
use App\Models\SiteReport;
use App\Models\SiteTask;
use App\Models\User;
use App\Models\WorkStage;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Notify;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Контроль качества: приёмка этапов. Прораб отправляет завершённый этап на проверку, ответственный принимает его
 * или фиксирует замечания. На каждое замечание создаётся задача на исправление, после исправления этап проверяют снова.
 */
class InspectionController extends Controller
{
    public static function out(Inspection $item, array $stageNames): array
    {
        $remarks = InspectionRemark::where('inspection_id', $item->id)->orderBy('id')->get();
        $taskIds = $remarks->pluck('task_id')->filter()->all();
        $tasks = $taskIds ? SiteTask::whereIn('id', $taskIds)->get()->keyBy('id') : collect();
        $rows = $remarks->map(function ($r) use ($tasks) {
            $task = $r->task_id ? ($tasks[$r->task_id] ?? null) : null;

            return [
                'id' => $r->id, 'text' => $r->text, 'report_id' => $r->report_id, 'task_id' => $r->task_id,
                'task_status' => $task?->status,
                'fixed' => ($task && $task->status === 'done') || $r->task_id === null,
            ];
        })->all();

        return [
            'id' => $item->id,
            'project_id' => $item->project_id,
            'stage_id' => $item->stage_id,
            'stage_name' => $stageNames[$item->stage_id] ?? '',
            'status' => $item->status,
            'requested_by' => $item->requested_by,
            'inspector' => $item->inspector,
            'scheduled_date' => Fmt::d($item->scheduled_date),
            'inspected_at' => Fmt::dt($item->inspected_at),
            'notes' => $item->notes,
            'fix_by' => Fmt::d($item->fix_by),
            'previous_id' => $item->previous_id,
            'created_at' => Fmt::dt($item->created_at),
            'remarks' => $rows,
            'all_fixed' => $rows && ! in_array(false, array_column($rows, 'fixed'), true),
        ];
    }

    private static function stageNames(int $projectId): array
    {
        return WorkStage::where('project_id', $projectId)->pluck('name', 'id')->all();
    }

    private static function link(int $projectId): string
    {
        return "#/project/$projectId/quality";
    }

    private function inspection($id): Inspection
    {
        return $this->own(Inspection::class, $id, 'inspection_not_found');
    }

    public function index(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $query = Inspection::where('project_id', (int) $projectId);
        if (($stageId = $this->qInt($request, 'stage_id')) !== null) {
            $query->where('stage_id', $stageId);
        }
        $names = self::stageNames((int) $projectId);

        return $query->orderByDesc('id')->get()->map(fn ($i) => self::out($i, $names));
    }

    private static function createRequest(User $user, Project $project, WorkStage $stage, ?string $scheduled, string $notes, ?Inspection $previous): Inspection
    {
        if ($stage->percent < 100) {
            ApiError::abort(409, 'stage_not_complete');
        }
        $item = Inspection::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'stage_id' => $stage->id, 'status' => 'requested',
            'requested_by' => $user->name, 'scheduled_date' => $scheduled, 'notes' => trim($notes), 'previous_id' => $previous?->id,
        ]);
        Activity::log($project->organization_id, $project->id, 'stage', "Этап «{$stage->name}» отправлен на приёмку", $user->name);
        Notify::push($project->organization_id, Notify::people($project->organization_id, ['owner', 'pm']),
            "Этап «{$stage->name}» ждёт приёмки («{$project->name}»)", $project->id, self::link($project->id), null, $user->id);

        return $item;
    }

    /** Отправить завершённый этап на приёмку. */
    public function store(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['stage_id' => 'required|integer', 'scheduled_date' => 'sometimes|nullable|date_format:Y-m-d', 'notes' => 'sometimes|string|max:1000']);
        $stage = WorkStage::find($data['stage_id']);
        if ($stage === null || $stage->project_id !== $project->id) {
            ApiError::abort(404, 'stage_not_found');
        }
        $last = Inspection::where('stage_id', $stage->id)->orderByDesc('id')->first();
        if ($last !== null) {
            if ($last->status === 'requested') {
                ApiError::abort(409, 'inspection_open');
            }
            if ($last->status === 'failed') {
                ApiError::abort(409, 'use_reinspect');
            }
            if ($last->status === 'passed' && $stage->accepted_at !== null) {
                ApiError::abort(409, 'already_accepted');
            }
        }
        $item = self::createRequest($this->user(), $project, $stage, $data['scheduled_date'] ?? null, $data['notes'] ?? '', null);

        return response()->json(self::out($item->fresh(), self::stageNames($project->id)), 201);
    }

    /** Принять этап или зафиксировать замечания (на каждое создаётся задача на исправление). */
    public function result(Request $request, $id)
    {
        $data = $request->validate([
            'result' => 'required|in:passed,failed',
            'notes' => 'sometimes|string|max:1000',
            'fix_by' => 'sometimes|nullable|date_format:Y-m-d',
            'remarks' => 'sometimes|array|max:30',
            'remarks.*.text' => 'required',
            'remarks.*.report_id' => 'sometimes|nullable|integer',
        ]);
        $remarks = [];
        foreach ($data['remarks'] ?? [] as $i => $remark) {
            $text = is_string($remark['text']) ? trim($remark['text']) : '';
            if ($text === '' || mb_strlen($text) > 500) {
                throw ValidationException::withMessages(["remarks.$i.text" => 'text']);
            }
            $remarks[] = ['text' => $text, 'report_id' => $remark['report_id'] ?? null];
        }
        $item = $this->inspection($id);
        if ($item->status !== 'requested') {
            ApiError::abort(409, 'inspection_closed');
        }
        $project = Project::find($item->project_id);
        $stage = WorkStage::find($item->stage_id);
        $fixBy = $data['fix_by'] ?? null;
        if ($data['result'] === 'failed') {
            if (! $remarks) {
                ApiError::abort(422, 'remarks_required');
            }
            if ($fixBy === null) {
                ApiError::abort(422, 'fix_by_required');
            }
        }
        foreach ($remarks as $remark) {
            if ($remark['report_id'] !== null) {
                $report = SiteReport::find($remark['report_id']);
                if ($report === null || $report->project_id !== $item->project_id) {
                    ApiError::abort(404, 'report_not_found');
                }
            }
        }
        $user = $this->user();
        $item->status = $data['result'];
        $item->inspector = $user->name;
        $item->inspected_at = now();
        $item->notes = trim($data['notes'] ?? '') ?: $item->notes;
        $item->fix_by = $fixBy;
        $item->save();
        $org = $item->organization_id;
        if ($data['result'] === 'passed') {
            $stage->accepted_at = now();
            $stage->save();
            Activity::log($org, $item->project_id, 'stage', "Этап «{$stage->name}» принят", $user->name);
            Notify::push($org, Notify::people($org, ['owner', 'pm', 'foreman']), "Этап «{$stage->name}» принят («{$project->name}»)",
                $project->id, self::link($project->id), null, $user->id);
        } else {
            $stage->accepted_at = null;
            $stage->save();
            foreach ($remarks as $remark) {
                $task = SiteTask::create([
                    'organization_id' => $org, 'project_id' => $item->project_id, 'kind' => 'order', 'side' => 'contractor', 'source' => 'staff',
                    'title' => 'Исправить замечание: '.mb_substr($remark['text'], 0, 250), 'note' => "Приёмка этапа «{$stage->name}»", 'author' => $user->name, 'created_by' => $user->id,
                    'due_date' => $fixBy, 'priority' => 'high', 'assignee_id' => $stage->responsible_id, 'stage_id' => $stage->id,
                ]);
                InspectionRemark::create(['organization_id' => $org, 'inspection_id' => $item->id, 'text' => $remark['text'], 'report_id' => $remark['report_id'], 'task_id' => $task->id]);
            }
            $n = count($remarks);
            Activity::log($org, $item->project_id, 'stage', "Приёмка этапа «{$stage->name}»: замечаний $n", $user->name);
            $who = Notify::people($org, ['owner', 'pm', 'foreman']);
            $who[] = $stage->responsible_id;
            $fix = Carbon::createFromFormat('!Y-m-d', $fixBy)->format('d.m.Y');
            Notify::push($org, $who, "Приёмка этапа «{$stage->name}»: замечаний $n, исправить до $fix («{$project->name}»)",
                $project->id, self::link($project->id), null, $user->id);
        }

        return self::out($item, self::stageNames($item->project_id));
    }

    /** Повторная проверка: после того как все замечания исправлены. */
    public function reinspect($id)
    {
        $previous = $this->inspection($id);
        if ($previous->status !== 'failed') {
            ApiError::abort(409, 'not_failed');
        }
        if (Inspection::where('previous_id', $previous->id)->exists()) {
            ApiError::abort(409, 'inspection_open');
        }
        $names = self::stageNames($previous->project_id);
        if (! self::out($previous, $names)['all_fixed']) {
            ApiError::abort(409, 'remarks_open');
        }
        $project = Project::find($previous->project_id);
        $stage = WorkStage::find($previous->stage_id);
        $item = self::createRequest($this->user(), $project, $stage, null, '', $previous);

        return response()->json(self::out($item->fresh(), $names), 201);
    }
}
