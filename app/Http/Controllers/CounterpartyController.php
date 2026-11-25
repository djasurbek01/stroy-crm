<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Stage;
use App\Support\ApiError;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CounterpartyController extends Controller
{
    private const RULES = [
        'name' => 'required|string|min:1|max:200',
        'kind' => 'sometimes|in:contractor,supplier,customer',
        'inn' => 'sometimes|string|max:20',
        'phone' => 'sometimes|string|max:50',
        'email' => 'sometimes|string|max:200',
    ];

    public static function out(Counterparty $c): array
    {
        return ['name' => $c->name, 'kind' => $c->kind, 'inn' => $c->inn, 'phone' => $c->phone, 'email' => $c->email, 'id' => $c->id];
    }

    private function find($id): Counterparty
    {
        return $this->own(Counterparty::class, $id, 'counterparty_not_found');
    }

    public function index(Request $request)
    {
        $query = Counterparty::org($this->org())->orderBy('id');
        $this->whereContains($query, ['name', 'inn', 'phone', 'email'], $request->query('q'));

        return $this->paged($request, $query, fn ($items) => $items->map(fn ($c) => self::out($c)));
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::RULES);
        $item = Counterparty::create(['organization_id' => $this->org()] + $data);

        return response()->json(self::out($item->fresh()), 201);
    }

    public function show($id)
    {
        return self::out($this->find($id));
    }

    /** Те же правила, что при создании; неизвестные поля не принимаются. */
    public function update(Request $request, $id)
    {
        $item = $this->find($id);
        $payload = $request->json()->all();
        $allowed = ['name', 'kind', 'inn', 'phone', 'email'];
        if (array_diff(array_keys($payload), $allowed)) {
            ApiError::abort(422, 'validation');
        }
        $merged = array_merge(['name' => $item->name, 'kind' => $item->kind, 'inn' => $item->inn, 'phone' => $item->phone, 'email' => $item->email], $payload);
        if (Validator::make($merged, self::RULES)->fails()) {
            ApiError::abort(422, 'validation');
        }
        $item->fill(array_intersect_key($merged, array_flip($allowed)))->save();

        return self::out($item);
    }

    /** Карточка контакта: история сделок, договоров, документов и оплат. */
    public function overview($id)
    {
        $item = $this->find($id);
        $org = $this->org();
        $stageNames = Stage::org($org)->pluck('name', 'id');
        $projectNames = Project::org($org)->pluck('name', 'id');
        $deals = Deal::org($org)->where('counterparty_id', $item->id)->orderByDesc('id')->get();
        $contracts = $this->scopeProjects(Contract::org($org))->where('counterparty_id', $item->id)->orderByDesc('id')->get();
        $documents = $this->scopeProjects(Document::org($org))->where('counterparty_id', $item->id)->orderByDesc('id')->get();
        $payments = $this->scopeProjects(Payment::org($org), 'project_id', true)->where('counterparty_id', $item->id)->orderByDesc('pay_date')->orderByDesc('id')->get();

        return [
            'counterparty' => self::out($item),
            'deals' => $deals->take(50)->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'amount' => (float) $d->amount, 'stage' => $stageNames[$d->stage_id] ?? '', 'project_id' => $d->project_id])->values(),
            'contracts' => $contracts->take(50)->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'amount' => (float) $c->amount, 'status' => $c->status, 'project' => $projectNames[$c->project_id] ?? ''])->values(),
            'documents' => $documents->take(50)->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'doc_type' => $d->doc_type, 'status' => $d->status, 'project_id' => $d->project_id])->values(),
            'payments' => $payments->take(50)->map(fn ($p) => ['id' => $p->id, 'pay_date' => Fmt::d($p->pay_date), 'amount' => (float) $p->amount, 'direction' => $p->direction, 'purpose' => $p->purpose ?? ''])->values(),
            'totals' => [
                'deals_sum' => (float) $deals->sum('amount'),
                'contracts_sum' => (float) $contracts->sum('amount'),
                'paid_out' => (float) $payments->where('direction', 'out')->sum('amount'),
                'paid_in' => (float) $payments->where('direction', 'in')->sum('amount'),
            ],
        ];
    }
}
