<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Worker;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Fmt;
use App\Support\Perm;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Табель (часы и зарплата по часовой ставке) и траты по объектам (чеки по статьям). */
class TimesheetController extends Controller
{
    /** Траты: чеки без суммы или статьи видны отдельно, чтобы ничего не потерялось. */
    public function expenses()
    {
        $org = $this->org();
        $projects = Project::org($org)->pluck('name', 'id');
        $receipts = $this->scopeProjects(Document::org($org))->where('doc_type', 'receipt')->where('status', '!=', 'rejected')->orderBy('id')->get();
        $rows = [];
        foreach ($receipts as $doc) {
            $rows[$doc->project_id] ??= [
                'project_id' => $doc->project_id, 'name' => $projects[$doc->project_id] ?? '', 'total' => 0.0, 'count' => 0,
                'no_amount' => 0, 'no_category' => 0, 'by_category' => array_fill_keys(Document::EXPENSE_CATEGORIES, 0.0),
            ];
            $row = &$rows[$doc->project_id];
            $row['count']++;
            if ($doc->amount === null) {
                $row['no_amount']++;
                unset($row);

                continue;
            }
            $row['total'] += $doc->amount;
            if (in_array($doc->category, Document::EXPENSE_CATEGORIES, true)) {
                $row['by_category'][$doc->category] += $doc->amount;
            } else {
                $row['no_category']++;
                $row['by_category']['other'] += $doc->amount;
            }
            unset($row);
        }
        $items = array_values($rows);
        usort($items, fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach ($items as &$row) {
            $row['by_category'] = (object) array_filter($row['by_category'], fn ($v) => $v != 0);
        }
        unset($row);

        return [
            'projects' => $items,
            'total' => (float) array_sum(array_column($items, 'total')),
            'unresolved' => array_sum(array_map(fn ($r) => $r['no_amount'] + $r['no_category'], $items)),
        ];
    }

    private function worker($id): Worker
    {
        return $this->own(Worker::class, $id, 'worker_not_found');
    }

    private static function workerOut(Worker $w, bool $showRate): array
    {
        return ['id' => $w->id, 'name' => $w->name, 'position' => $w->position, 'active' => (bool) $w->active, 'hourly_rate' => $showRate ? (float) $w->hourly_rate : null];
    }

    public function workers()
    {
        $showRate = Perm::allows($this->user(), 'payroll.read');

        return Worker::org($this->org())->orderBy('name')->get()->map(fn ($w) => self::workerOut($w, $showRate));
    }

    public function storeWorker(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:1|max:200',
            'position' => 'sometimes|string|max:100',
            'hourly_rate' => 'sometimes|numeric|min:0|max:100000',
        ]);
        $item = Worker::create([
            'organization_id' => $this->org(), 'name' => trim($data['name']), 'position' => trim($data['position'] ?? ''),
            'hourly_rate' => (float) ($data['hourly_rate'] ?? 0),
        ]);

        return response()->json(self::workerOut($item->fresh(), true), 201);
    }

    public function updateWorker(Request $request, $id)
    {
        $item = $this->worker($id);
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|min:1|max:200',
            'position' => 'sometimes|nullable|string|max:100',
            'hourly_rate' => 'sometimes|nullable|numeric|min:0|max:100000',
            'active' => 'sometimes|nullable|boolean',
        ]);
        if (isset($data['name'])) {
            $item->name = trim($data['name']);
        }
        if (isset($data['position'])) {
            $item->position = trim($data['position']);
        }
        if (isset($data['hourly_rate'])) {
            $item->hourly_rate = (float) $data['hourly_rate'];
        }
        if (isset($data['active'])) {
            $item->active = (bool) $data['active'];
        }
        $item->save();

        return self::workerOut($item, true);
    }

    private static function date(Request $request, string $name): Carbon
    {
        $value = (string) $request->query($name, '');
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$name => 'date']);
        }

        return Carbon::instance($parsed);
    }

    public function entries(Request $request)
    {
        $from = self::date($request, 'date_from');
        $to = self::date($request, 'date_to');
        if ($to->lt($from) || $from->diffInDays($to) > 366) {
            ApiError::abort(422, 'bad_period');
        }
        $query = $this->scopeProjects(TimeEntry::where('organization_id', $this->org()))
            ->where('work_date', '>=', $from->toDateString())->where('work_date', '<=', $to->toDateString());
        if (($projectId = $this->qInt($request, 'project_id')) !== null) {
            $query->where('project_id', $projectId);
        }
        if (($workerId = $this->qInt($request, 'worker_id')) !== null) {
            $query->where('worker_id', $workerId);
        }

        return $query->orderBy('work_date')->orderBy('id')->get()->map(fn ($e) => [
            'id' => $e->id, 'worker_id' => $e->worker_id, 'project_id' => $e->project_id, 'work_date' => Fmt::d($e->work_date),
            'hours' => (float) $e->hours, 'note' => $e->note,
        ]);
    }

    /** Записывает часы человека на объекте за день. Ноль часов убирает запись. */
    public function setHours(Request $request)
    {
        $data = $request->validate([
            'worker_id' => 'required|integer',
            'project_id' => 'required|integer',
            'work_date' => 'required|date_format:Y-m-d',
            'hours' => 'required|numeric|min:0|max:24',
            'note' => 'sometimes|string|max:300',
        ]);
        $user = $this->user();
        $worker = $this->worker($data['worker_id']);
        $project = $this->ownProject($data['project_id']);
        $day = Carbon::createFromFormat('!Y-m-d', $data['work_date']);
        if ($day->gt(today())) {
            ApiError::abort(422, 'future_date');
        }
        $hours = round((float) $data['hours'], 2);
        $entry = TimeEntry::where('worker_id', $worker->id)->where('project_id', $project->id)->where('work_date', $day->toDateString())->first();
        $other = (float) TimeEntry::where('worker_id', $worker->id)->where('work_date', $day->toDateString())
            ->where('id', '!=', $entry?->id ?? 0)->sum('hours');
        if ($other + $hours > 24) {
            ApiError::abort(422, 'day_over_24');
        }
        $dayText = Fmt::ru($day);
        if ($hours == 0) {
            if ($entry !== null) {
                $entry->delete();
                Audit::log($user, 'delete', 'timesheet', $entry->id, "Табель: {$worker->name}, «{$project->name}», $dayText: запись убрана");
            }

            return ['worker_id' => $worker->id, 'project_id' => $project->id, 'work_date' => $day->toDateString(), 'hours' => 0];
        }
        $was = null;
        if ($entry === null) {
            $entry = new TimeEntry([
                'organization_id' => $user->organization_id, 'worker_id' => $worker->id, 'project_id' => $project->id,
                'work_date' => $day->toDateString(), 'rate' => $worker->hourly_rate, 'created_by' => $user->id,
            ]);
        } else {
            $was = (float) $entry->hours;
        }
        $entry->hours = $hours;
        $entry->note = trim($data['note'] ?? '');
        $entry->save();
        $change = $was === null ? Fmt::g($hours).' ч' : 'было '.Fmt::g($was).' ч, стало '.Fmt::g($hours).' ч';
        Audit::log($user, $was !== null ? 'update' : 'create', 'timesheet', $entry->id, "Табель: {$worker->name}, «{$project->name}», $dayText: $change");

        return ['worker_id' => $worker->id, 'project_id' => $project->id, 'work_date' => $day->toDateString(), 'hours' => $hours];
    }

    /** Часы и зарплата за месяц: по людям и по объектам. */
    public function summary(Request $request)
    {
        $month = (string) $request->query('month', '');
        if ($month === '') {
            throw ValidationException::withMessages(['month' => 'required']);
        }
        [$start, $end] = AnalyticsController::monthBounds($month);
        $org = $this->org();
        $names = Worker::org($org)->pluck('name', 'id');
        $projects = Project::org($org)->pluck('name', 'id');
        $entries = $this->scopeProjects(TimeEntry::where('organization_id', $org))->where('work_date', '>=', $start->toDateString())->where('work_date', '<', $end->toDateString())->get();

        $people = [];
        $objects = [];
        foreach ($entries as $e) {
            $pay = $e->hours * $e->rate;
            $people[$e->worker_id] ??= ['worker_id' => $e->worker_id, 'name' => $names[$e->worker_id] ?? '', 'hours' => 0.0, 'pay' => 0.0, 'days' => [], 'by_project' => []];
            $people[$e->worker_id]['hours'] += $e->hours;
            $people[$e->worker_id]['pay'] += $pay;
            $people[$e->worker_id]['days'][$e->work_date->toDateString()] = true;
            $people[$e->worker_id]['by_project'][$e->project_id] = ($people[$e->worker_id]['by_project'][$e->project_id] ?? 0) + $e->hours;
            $objects[$e->project_id] ??= ['project_id' => $e->project_id, 'name' => $projects[$e->project_id] ?? '', 'hours' => 0.0, 'pay' => 0.0];
            $objects[$e->project_id]['hours'] += $e->hours;
            $objects[$e->project_id]['pay'] += $pay;
        }
        usort($people, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $workersOut = array_map(function ($p) use ($projects) {
            $byProject = [];
            foreach ($p['by_project'] as $pid => $h) {
                $byProject[$projects[$pid] ?? ''] = round($h, 2);
            }

            return ['worker_id' => $p['worker_id'], 'name' => $p['name'], 'hours' => round($p['hours'], 2), 'pay' => round($p['pay'], 2), 'days' => count($p['days']), 'by_project' => (object) $byProject];
        }, $people);
        $objectsOut = array_values($objects);
        usort($objectsOut, fn ($a, $b) => $b['pay'] <=> $a['pay']);
        $objectsOut = array_map(fn ($o) => ['project_id' => $o['project_id'], 'name' => $o['name'], 'hours' => round($o['hours'], 2), 'pay' => round($o['pay'], 2)], $objectsOut);

        return [
            'month' => $month,
            'workers' => $workersOut,
            'projects' => $objectsOut,
            'total_hours' => round(array_sum(array_column($people, 'hours')), 2),
            'total_pay' => round(array_sum(array_column($people, 'pay')), 2),
        ];
    }
}
