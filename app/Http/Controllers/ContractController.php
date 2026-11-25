<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Support\Fmt;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    private const STATUSES = 'draft,review,signed,closed';

    public static function out(Contract $c): array
    {
        return [
            'title' => $c->title, 'counterparty_id' => $c->counterparty_id, 'amount' => (float) $c->amount, 'status' => $c->status,
            'due_date' => Fmt::d($c->due_date), 'id' => $c->id, 'project_id' => $c->project_id,
        ];
    }

    public function store(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $data = $request->validate([
            'title' => 'required|string|min:1|max:300',
            'counterparty_id' => 'sometimes|nullable|integer',
            'amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:'.self::STATUSES,
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        $this->checkCounterparty($data['counterparty_id'] ?? null);
        $item = Contract::create(['organization_id' => $this->org(), 'project_id' => (int) $projectId] + $data);

        return response()->json(self::out($item->fresh()), 201);
    }

    public function index(Request $request)
    {
        $query = $this->scopeProjects(Contract::org($this->org()));
        if (($projectId = $this->qInt($request, 'project_id')) !== null) {
            $query->where('project_id', $projectId);
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paged($request, $query->orderBy('id'), fn ($items) => $items->map(fn ($c) => self::out($c)));
    }

    public function show($id)
    {
        return self::out($this->own(Contract::class, $id, 'contract_not_found'));
    }

    public function update(Request $request, $id)
    {
        $item = $this->own(Contract::class, $id, 'contract_not_found');
        $changes = $request->validate([
            'title' => 'sometimes|nullable|string|min:1|max:300',
            'counterparty_id' => 'sometimes|nullable|integer',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|nullable|in:'.self::STATUSES,
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        $this->checkCounterparty($changes['counterparty_id'] ?? null);
        foreach ($changes as $field => $value) {
            if ($value === null && in_array($field, ['title', 'amount', 'status'], true)) {
                continue;
            }
            $item->{$field} = $value;
        }
        $item->save();

        return self::out($item);
    }
}
