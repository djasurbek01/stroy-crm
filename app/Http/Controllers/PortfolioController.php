<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Inspection;
use App\Models\InspectionRemark;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\SiteTask;
use App\Models\User;
use App\Support\Finance;
use App\Support\Perm;
use App\Support\Site;
use App\Support\Stock;
use Carbon\Carbon;

/** Панель руководителя: все объекты на одном экране с главными показателями и проблемами. Деньги только у тех, кому можно. */
class PortfolioController extends Controller
{
    private const ACTIVE = ['planning', 'in_progress'];

    /** Заказчик объекта: контрагент сделки, из которой объект создан. */
    private static function customers(int $org): array
    {
        $names = Counterparty::org($org)->pluck('name', 'id');
        $out = [];
        foreach (Deal::org($org)->whereNotNull('project_id')->orderBy('id')->get() as $deal) {
            if (isset($names[$deal->counterparty_id])) {
                $out[$deal->project_id] = $names[$deal->counterparty_id];
            }
        }

        return $out;
    }

    private static function projectRow(Project $project, Carbon $today, array $customers, array $names, bool $showMoney): array
    {
        $ctx = Site::context($project->id);
        $stages = $ctx['stages'];
        $summary = Site::summary($ctx);
        $progress = Site::overallPercent($stages);
        $current = $stages->first(fn ($s) => $s->percent < 100);
        $overdueStages = $stages->filter(fn ($s) => $s->end_date && $s->end_date->lt($today) && $s->percent < 100)->values();
        $tasks = SiteTask::where('project_id', $project->id)->whereNull('done_at')->get();
        $overdueTasks = $tasks->filter(fn ($t) => $t->due_date && $t->due_date->lt($today));
        $inReview = $tasks->where('status', 'in_review');
        $openRemarks = 0;
        $remarks = InspectionRemark::join('inspections', 'inspections.id', '=', 'inspection_remarks.inspection_id')
            ->where('inspections.project_id', $project->id)->where('inspections.status', 'failed')->get(['inspection_remarks.*']);
        foreach ($remarks as $remark) {
            $task = $remark->task_id ? SiteTask::find($remark->task_id) : null;
            if ($task !== null && $task->status !== 'done') {
                $openRemarks++;
            }
        }
        $waitingInspection = Inspection::where('project_id', $project->id)->where('status', 'requested')->exists();

        $problems = [];
        foreach ($overdueStages->take(3) as $stage) {
            $problems[] = "Отстаёт этап «{$stage->name}» на ".(int) $stage->end_date->diffInDays($today).' дн.'.($stage->delay_reason ? " ({$stage->delay_reason})" : ', причина не указана');
        }
        if ($overdueTasks->isNotEmpty()) {
            $problems[] = 'Просрочено задач: '.$overdueTasks->count();
        }
        if ($openRemarks) {
            $problems[] = "Не устранены замечания приёмки: $openRemarks";
        }
        if ($waitingInspection) {
            $problems[] = 'Этап ждёт приёмки';
        }

        $row = [
            'id' => $project->id, 'name' => $project->name, 'address' => $project->address, 'status' => $project->status,
            'customer' => $customers[$project->id] ?? '',
            'manager' => $names[$project->manager_id] ?? '', 'foreman' => $names[$project->foreman_id] ?? '',
            'current_stage' => $current ? $current->name : ($stages->isNotEmpty() ? 'Все этапы завершены' : ''),
            'progress' => $progress, 'planned_end' => $summary['planned_end'], 'forecast_end' => $summary['forecast_end'], 'deviation_days' => $summary['deviation_days'],
            'overdue_stages' => $overdueStages->count(), 'open_tasks' => $tasks->count(), 'overdue_tasks' => $overdueTasks->count(), 'tasks_in_review' => $inReview->count(),
            'open_remarks' => $openRemarks, 'waiting_inspection' => $waitingInspection, 'finance' => null,
        ];
        if ($showMoney) {
            $money = Finance::compute($project, $today, false);
            $row['finance'] = [
                'contract_value' => $money['revenue']['contract_value'], 'received' => $money['revenue']['received'], 'receivable' => $money['revenue']['receivable'],
                'overdue_amount' => $money['revenue']['overdue_amount'], 'budget_total' => $money['budget']['planned_total'], 'fact_total' => $money['budget']['fact_total'],
                'forecast_total' => $money['budget']['forecast_total'], 'used_percent' => $money['budget']['used_percent'], 'forecast_margin' => $money['result']['forecast_margin'],
                'forecast_margin_percent' => $money['result']['forecast_margin_percent'], 'over_budget' => $money['budget']['over_budget'],
            ];
            $problems = array_merge($problems, $money['alerts']);
        }
        $row['problems'] = $problems;
        $row['attention'] = count($problems);
        $row['is_overdue'] = $overdueStages->isNotEmpty() || ($summary['deviation_days'] ?? 0) > 0;

        return $row;
    }

    /** Все объекты: этап, готовность, сроки с прогнозом, проблемы, деньги. Объекты с проблемами сверху. */
    public function index()
    {
        $org = $this->org();
        $today = today();
        $showMoney = Perm::allows($this->user(), 'finance.read');
        $customers = self::customers($org);
        $names = User::org($org)->pluck('name', 'id')->all();
        $rows = $this->scopeProjects(Project::org($org), 'id')->orderBy('id')->get()->map(fn ($p) => self::projectRow($p, $today, $customers, $names, $showMoney))->all();
        usort($rows, fn ($a, $b) => [-$a['attention'], $a['name']] <=> [-$b['attention'], $b['name']]);

        $openTasks = $this->scopeProjects(SiteTask::where('organization_id', $org))->whereNull('done_at')->get();
        $purchases = $this->scopeProjects(Purchase::org($org))->where('status', '!=', 'paid')->get();
        $requestsOpen = MaterialRequest::org($org)->whereIn('status', Stock::OPEN_REQUEST)->count();
        $levels = Stock::levels($org);
        $lowStock = Material::org($org)->where('active', true)->get()->filter(fn ($m) => $m->min_stock > 0 && $levels[$m->id]['stock'] < $m->min_stock)->count();
        $active = array_values(array_filter($rows, fn ($r) => in_array($r['status'], self::ACTIVE, true)));
        $kpi = [
            'projects_total' => count($rows),
            'projects_active' => count($active),
            'projects_finishing' => count(array_filter($active, fn ($r) => $r['progress'] >= 90)),
            'projects_overdue' => count(array_filter($active, fn ($r) => $r['is_overdue'])),
            'tasks_today' => $openTasks->filter(fn ($t) => $t->due_date && $t->due_date->isSameDay($today))->count(),
            'tasks_overdue' => $openTasks->filter(fn ($t) => $t->due_date && $t->due_date->lt($today))->count(),
            'tasks_in_review' => $openTasks->where('status', 'in_review')->count(),
            'purchases_open' => $purchases->count(),
            'requests_open' => $requestsOpen,
            'low_stock' => $lowStock,
            'money' => null,
        ];
        if ($showMoney) {
            $money = array_filter(array_column($rows, 'finance'));
            $sum = fn ($key) => round(array_sum(array_column($money, $key)), 2);
            $kpi['money'] = [
                'contracts_total' => $sum('contract_value'), 'received' => $sum('received'), 'expected' => $sum('receivable'),
                'overdue' => $sum('overdue_amount'), 'expenses' => $sum('fact_total'), 'forecast_expenses' => $sum('forecast_total'),
                'purchases_open_amount' => round((float) $purchases->sum('amount'), 2),
            ];
        }

        return ['kpi' => $kpi, 'projects' => $rows];
    }

    /** Нагрузка сотрудников: открытые и просроченные задачи и на скольких объектах занят. */
    public function workload()
    {
        $org = $this->org();
        $today = today();
        $rows = [];
        foreach (User::org($org)->orderBy('id')->get() as $u) {
            $rows[$u->id] = ['user_id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'open_tasks' => 0, 'overdue_tasks' => 0, 'in_review' => 0, 'projects' => []];
        }
        foreach ($this->scopeProjects(SiteTask::where('organization_id', $org))->whereNull('done_at')->whereNotNull('assignee_id')->get() as $task) {
            if (! isset($rows[$task->assignee_id])) {
                continue;
            }
            $rows[$task->assignee_id]['open_tasks']++;
            $rows[$task->assignee_id]['overdue_tasks'] += $task->due_date && $task->due_date->lt($today) ? 1 : 0;
            $rows[$task->assignee_id]['in_review'] += $task->status === 'in_review' ? 1 : 0;
            $rows[$task->assignee_id]['projects'][$task->project_id] = true;
        }
        foreach ($this->scopeProjects(Project::org($org), 'id')->whereIn('status', self::ACTIVE)->get() as $project) {
            foreach ([$project->manager_id, $project->foreman_id] as $uid) {
                if ($uid && isset($rows[$uid])) {
                    $rows[$uid]['projects'][$project->id] = true;
                }
            }
        }
        $out = array_map(function ($r) {
            $r['projects'] = count($r['projects']);

            return $r;
        }, array_values($rows));
        usort($out, fn ($a, $b) => [-$a['overdue_tasks'], -$a['open_tasks'], $a['name']] <=> [-$b['overdue_tasks'], -$b['open_tasks'], $b['name']]);

        return $out;
    }
}
