<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Stage;
use App\Models\Todo;
use App\Support\CrmEngine;
use App\Support\Fmt;
use App\Support\Perm;
use Illuminate\Http\Request;

/** Рабочий стол, общий поиск и сводка по объектам. */
class HomeController extends Controller
{
    public function home()
    {
        $user = $this->user();
        $org = $user->organization_id;
        CrmEngine::ensureDefault($org);
        $today = today();
        $month = now()->format('Y-m');
        $monthStart = now()->startOfMonth();

        $pipeline = CrmEngine::firstPipeline($org);
        $stages = Stage::where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->get();
        $deals = Deal::org($org)->where('pipeline_id', $pipeline->id)->where('unsorted', false)->get();
        $unsortedCount = Deal::org($org)->where('unsorted', true)->count();
        $kinds = $stages->pluck('kind', 'id');
        $openDeals = $deals->filter(fn ($d) => ($kinds[$d->stage_id] ?? null) === 'open');
        $funnel = $stages->where('kind', '!=', 'lost')->values()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'kind' => $s->kind,
            'count' => $deals->where('stage_id', $s->id)->count(),
            'sum' => (float) $deals->where('stage_id', $s->id)->sum('amount'),
        ]);

        $todos = Todo::org($org)->where('assignee_id', $user->id)->where('done', false)
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')->get();

        $docs = $this->scopeProjects(Document::org($org))->whereIn('status', Document::OPEN_STATUSES)->get();
        $myReview = $docs->filter(fn ($d) => $d->status === 'in_review' && $d->assignee_id === $user->id);

        $money = null;
        if (Perm::allows($user, 'money.read')) {
            $payments = Payment::org($org)->where('pay_date', '>=', $monthStart->toDateString())->get();
            $invoices = Document::org($org)->where('doc_type', 'invoice')->whereNotNull('amount')->get();
            $paidByDoc = Payment::org($org)->whereNotNull('document_id')->groupBy('document_id')
                ->selectRaw('document_id, SUM(amount) as total')->pluck('total', 'document_id');
            $unpaid = [];
            foreach ($invoices as $d) {
                if ($d->status === 'rejected') {
                    continue;
                }
                $left = $d->amount - (float) ($paidByDoc[$d->id] ?? 0);
                if ($left > 0.01) {
                    $unpaid[] = $left;
                }
            }
            $money = [
                'out_month' => (float) $payments->where('direction', 'out')->sum('amount'),
                'in_month' => (float) $payments->where('direction', 'in')->sum('amount'),
                'unpaid_count' => count($unpaid),
                'unpaid_sum' => (float) array_sum($unpaid),
            ];
        }

        $dealTitles = $deals->pluck('title', 'id');
        $recent = DealEvent::where('organization_id', $org)->orderByDesc('id')->limit(8)->get();

        return [
            'deals' => [
                'unsorted' => $unsortedCount,
                'open' => $openDeals->count(),
                'open_sum' => (float) $openDeals->sum('amount'),
                'created_month' => $deals->filter(fn ($d) => $d->created_at && $d->created_at->gte($monthStart))->count(),
            ],
            'funnel' => $funnel,
            'pipeline_id' => $pipeline->id,
            'goal' => AnalyticsController::goalOut($org, $month),
            'tasks' => [
                'overdue' => $todos->filter(fn ($t) => $t->due_date && $t->due_date->lt($today))->count(),
                'today' => $todos->filter(fn ($t) => $t->due_date && $t->due_date->isSameDay($today))->count(),
                'total' => $todos->count(),
                'items' => $todos->take(6)->map(fn ($t) => [
                    'id' => $t->id, 'title' => $t->title, 'due_date' => Fmt::d($t->due_date),
                    'overdue' => ! $t->done && $t->due_date !== null && $t->due_date->lt($today),
                ])->values(),
            ],
            'documents' => [
                'to_approve' => $myReview->count(),
                'in_review' => $docs->where('status', 'in_review')->count(),
                'overdue' => $docs->filter(fn ($d) => $d->isOverdue())->count(),
            ],
            'money' => $money,
            'recent' => $recent->filter(fn ($e) => isset($dealTitles[$e->deal_id]))->values()->map(fn ($e) => [
                'deal_id' => $e->deal_id,
                'deal' => $dealTitles[$e->deal_id] ?? '',
                'kind' => $e->kind,
                'text' => $e->text,
                'created_at' => Fmt::dt($e->created_at),
            ]),
        ];
    }

    public function search(Request $request)
    {
        $needle = trim((string) $request->query('q', ''));
        $empty = ['deals' => [], 'contacts' => [], 'documents' => [], 'projects' => []];
        if (mb_strlen($needle) < 2) {
            return $empty;
        }
        $org = $this->org();
        $top = function ($class, array $columns) use ($org, $needle) {
            $query = $class::org($org);
            if ($class === Document::class || $class === Project::class) {
                $this->scopeProjects($query, $class === Project::class ? 'id' : 'project_id');
            }

            return $this->whereContains($query, $columns, $needle)->orderByDesc('id')->limit(5)->get();
        };

        return [
            'deals' => $top(Deal::class, ['title', 'address'])->map(fn ($d) => ['id' => $d->id, 'title' => $d->title]),
            'contacts' => $top(Counterparty::class, ['name', 'inn', 'phone', 'email'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind]),
            'documents' => $top(Document::class, ['title', 'number'])->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'project_id' => $d->project_id]),
            'projects' => $top(Project::class, ['name', 'address'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
        ];
    }

    /** Сводка по объектам: документы, согласование, просрочка, закупки, договоры. */
    public function dashboard()
    {
        $org = $this->org();
        $docs = $this->scopeProjects(Document::org($org))->get()->groupBy('project_id');
        $openPurchases = $this->scopeProjects(Purchase::org($org))->where('status', '!=', 'paid')->get()->countBy('project_id');
        $contracts = $this->scopeProjects(Contract::org($org))->get()->countBy('project_id');

        return $this->scopeProjects(Project::org($org), 'id')->orderBy('id')->get()->map(function ($p) use ($docs, $openPurchases, $contracts) {
            $list = $docs[$p->id] ?? collect();

            return [
                'project_id' => $p->id,
                'name' => $p->name,
                'address' => $p->address,
                'status' => $p->status,
                'documents' => $list->count(),
                'in_review' => $list->where('status', 'in_review')->count(),
                'overdue' => $list->filter(fn ($d) => $d->isOverdue())->count(),
                'purchases_open' => $openPurchases[$p->id] ?? 0,
                'contracts' => $contracts[$p->id] ?? 0,
            ];
        });
    }
}
