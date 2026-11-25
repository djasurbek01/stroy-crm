<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Purchase;
use App\Support\ApiError;
use App\Support\ExcelImport;
use App\Support\Files;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    private const STATUSES = 'request,ordered,delivered,paid';

    public static function out(Purchase $p): array
    {
        return [
            'item' => $p->item, 'quantity' => (float) $p->quantity, 'unit' => $p->unit, 'amount' => (float) $p->amount,
            'status' => $p->status, 'contract_id' => $p->contract_id, 'counterparty_id' => $p->counterparty_id,
            'id' => $p->id, 'project_id' => $p->project_id,
        ];
    }

    public function store(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $data = $request->validate([
            'item' => 'required|string|min:1|max:300',
            'quantity' => 'sometimes|numeric|gt:0',
            'unit' => 'sometimes|string|max:30',
            'amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:'.self::STATUSES,
            'contract_id' => 'sometimes|nullable|integer',
            'counterparty_id' => 'sometimes|nullable|integer',
        ]);
        $this->checkCounterparty($data['counterparty_id'] ?? null);
        $this->checkContract($data['contract_id'] ?? null);
        $item = Purchase::create(['organization_id' => $this->org(), 'project_id' => (int) $projectId] + $data);

        return response()->json(self::out($item->fresh()), 201);
    }

    public function index(Request $request)
    {
        $query = $this->scopeProjects(Purchase::org($this->org()));
        if (($projectId = $this->qInt($request, 'project_id')) !== null) {
            $query->where('project_id', $projectId);
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paged($request, $query->orderBy('id'), fn ($items) => $items->map(fn ($p) => self::out($p)));
    }

    public function update(Request $request, $id)
    {
        $item = $this->own(Purchase::class, $id, 'purchase_not_found');
        $changes = $request->validate([
            'item' => 'sometimes|nullable|string|min:1|max:300',
            'quantity' => 'sometimes|nullable|numeric|gt:0',
            'unit' => 'sometimes|nullable|string|max:30',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|nullable|in:'.self::STATUSES,
            'contract_id' => 'sometimes|nullable|integer',
            'counterparty_id' => 'sometimes|nullable|integer',
        ]);
        $this->checkCounterparty($changes['counterparty_id'] ?? null);
        $this->checkContract($changes['contract_id'] ?? null);
        foreach ($changes as $field => $value) {
            if ($value === null && in_array($field, ['item', 'quantity', 'unit', 'amount', 'status'], true)) {
                continue;
            }
            $item->{$field} = $value;
        }
        $item->save();

        return self::out($item);
    }

    /** Импорт закупок из Excel с предпросмотром (dry_run). Поставщики создаются по названию. */
    public function import(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $dryRun = $this->qBool($request, 'dry_run', false);
        $request->validate(['file' => 'required|file']);
        $data = Files::read($request->file('file'), config('stroy.max_file_bytes'));
        if ($data === '') {
            ApiError::abort(422, 'bad_excel_file');
        }
        [$columns, $rows, $skipped] = ExcelImport::readRows($data);

        $created = 0;
        if (! $dryRun) {
            $suppliers = [];
            foreach (Counterparty::org($this->org())->get() as $c) {
                $suppliers[mb_strtolower($c->name)] = $c->id;
            }
            foreach ($rows as $row) {
                $supplierId = null;
                $name = $row['supplier'];
                if ($name !== '') {
                    $supplierId = $suppliers[mb_strtolower($name)] ?? null;
                    if ($supplierId === null) {
                        $supplier = Counterparty::create(['organization_id' => $this->org(), 'name' => $name, 'kind' => 'supplier']);
                        $supplierId = $suppliers[mb_strtolower($name)] = $supplier->id;
                    }
                }
                Purchase::create([
                    'organization_id' => $this->org(), 'project_id' => (int) $projectId, 'counterparty_id' => $supplierId,
                    'item' => $row['item'], 'quantity' => $row['quantity'], 'unit' => $row['unit'], 'amount' => $row['amount'],
                ]);
                $created++;
            }
        }

        return [
            'columns' => array_map('strval', $columns),
            'rows' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
            'preview' => array_slice($rows, 0, 5),
            'dry_run' => $dryRun,
        ];
    }
}
