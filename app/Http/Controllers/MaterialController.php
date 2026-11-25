<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Stock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Материалы и склад: справочник, остатки, приход, выдача на объекты, списание, заявки с объектов и закупка недостающего. */
class MaterialController extends Controller
{
    private const QTY = 'numeric|gt:0|max:1000000000|decimal:0,3';

    private const PRICE = 'numeric|min:0|max:100000000|decimal:0,2';

    private static function materialOut(Material $m, array $lv): array
    {
        $stock = $lv['stock'];

        return [
            'id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'min_stock' => (float) $m->min_stock, 'active' => (bool) $m->active,
            'stock' => $stock, 'reserved' => round($lv['reserved'], 3), 'available' => round($stock - $lv['reserved'], 3),
            'avg_price' => round($lv['avg_price'], 2), 'value' => round($stock * $lv['avg_price'], 2),
            'low' => $m->min_stock > 0 && $stock < $m->min_stock,
        ];
    }

    private function material($id): Material
    {
        return $this->own(Material::class, $id, 'material_not_found');
    }

    private static function alertLow(Material $m, float $stock): void
    {
        if ($m->min_stock > 0 && $stock < $m->min_stock) {
            Notify::push($m->organization_id, Notify::people($m->organization_id, ['owner', 'storekeeper', 'buyer']),
                "Низкий остаток: {$m->name}, осталось ".Fmt::g($stock)." {$m->unit} (минимум ".Fmt::g($m->min_stock).')',
                null, '#/warehouse', "low:{$m->id}:".today()->toDateString());
        }
    }

    private static function name($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > 200) {
            throw ValidationException::withMessages(['name' => 'name']);
        }

        return $value;
    }

    private function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $wanted = mb_strtolower($name);

        return Material::org($this->org())->get()->contains(fn ($o) => $o->id !== $exceptId && mb_strtolower($o->name) === $wanted);
    }

    public function index(Request $request)
    {
        $levels = Stock::levels($this->org());
        $needle = mb_strtolower(trim((string) $request->query('q', '')));
        $low = $this->qBool($request, 'low', false);
        $rows = [];
        foreach (Material::org($this->org())->orderBy('name')->get() as $m) {
            if ($needle !== '' && ! str_contains(mb_strtolower($m->name), $needle)) {
                continue;
            }
            $out = self::materialOut($m, $levels[$m->id]);
            if ($low && ! $out['low']) {
                continue;
            }
            $rows[] = $out;
        }

        return $rows;
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required', 'unit' => 'sometimes|string|min:1|max:30', 'min_stock' => 'sometimes|numeric|min:0|max:1000000000|decimal:0,3']);
        $name = self::name($data['name']);
        if ($this->nameTaken($name)) {
            ApiError::abort(409, 'material_exists');
        }
        $m = Material::create(['organization_id' => $this->org(), 'name' => $name, 'unit' => trim($data['unit'] ?? 'шт'), 'min_stock' => (float) ($data['min_stock'] ?? 0)]);

        return response()->json(self::materialOut($m->fresh(), Stock::levels($this->org())[$m->id]), 201);
    }

    public function update(Request $request, $id)
    {
        $m = $this->material($id);
        $changes = $request->validate([
            'name' => 'sometimes|nullable', 'unit' => 'sometimes|nullable|string|min:1|max:30',
            'min_stock' => 'sometimes|nullable|numeric|min:0|max:1000000000|decimal:0,3', 'active' => 'sometimes|nullable|boolean',
        ]);
        if (($changes['name'] ?? null) !== null) {
            $changes['name'] = self::name($changes['name']);
            if ($this->nameTaken($changes['name'], $m->id)) {
                ApiError::abort(409, 'material_exists');
            }
        }
        foreach ($changes as $key => $value) {
            if ($value !== null) {
                $m->{$key} = is_string($value) ? trim($value) : $value;
            }
        }
        $m->save();

        return self::materialOut($m, Stock::levels($this->org())[$m->id]);
    }

    private static function movementOut(StockMovement $mv, array $projects): array
    {
        return [
            'id' => $mv->id, 'kind' => $mv->kind, 'quantity' => (float) $mv->quantity, 'price' => (float) $mv->price, 'amount' => round($mv->quantity * $mv->price, 2),
            'project_id' => $mv->project_id, 'project_name' => $mv->project_id ? ($projects[$mv->project_id] ?? '') : '', 'counterparty_id' => $mv->counterparty_id,
            'doc_number' => $mv->doc_number, 'note' => $mv->note, 'moved_on' => Fmt::d($mv->moved_on), 'created_by' => $mv->created_by,
            'request_id' => $mv->request_id, 'purchase_id' => $mv->purchase_id,
        ];
    }

    private function projectNames(): array
    {
        return Project::org($this->org())->pluck('name', 'id')->all();
    }

    public function movements($id)
    {
        $m = $this->material($id);
        $names = $this->projectNames();

        return StockMovement::where('material_id', $m->id)->orderByDesc('moved_on')->orderByDesc('id')->limit(300)->get()->map(fn ($mv) => self::movementOut($mv, $names));
    }

    /** Приход, выдача или списание. Выдача и списание по средней цене прихода. */
    private function addMovement(User $user, Material $m, array $p, ?int $requestId = null, ?int $purchaseId = null): StockMovement
    {
        $levels = Stock::levels($m->organization_id)[$m->id];
        $price = $p['price'] ?? null;
        $projectId = $p['project_id'] ?? null;
        $note = trim($p['note'] ?? '');
        if ($p['kind'] === 'in') {
            $price ??= 0;
            $this->checkCounterparty($p['counterparty_id'] ?? null);
        } else {
            if ($p['kind'] === 'out' && $projectId === null) {
                ApiError::abort(422, 'project_required');
            }
            if ($p['kind'] === 'writeoff' && $note === '') {
                ApiError::abort(422, 'note_required');
            }
            if ($projectId !== null) {
                $this->ownProject($projectId);
            }
            if ((float) $p['quantity'] > $levels['stock'] + 0.0000001) {
                ApiError::abort(409, 'not_enough_stock');
            }
            $price = round($levels['avg_price'], 2);
        }
        $mv = StockMovement::create([
            'organization_id' => $m->organization_id, 'material_id' => $m->id, 'kind' => $p['kind'], 'quantity' => (float) $p['quantity'], 'price' => (float) $price,
            'project_id' => $projectId, 'counterparty_id' => $p['kind'] === 'in' ? ($p['counterparty_id'] ?? null) : null, 'request_id' => $requestId,
            'purchase_id' => $purchaseId, 'doc_number' => trim($p['doc_number'] ?? ''), 'note' => $note, 'moved_on' => $p['moved_on'] ?? today(),
            'created_by' => $user->name,
        ]);
        if ($p['kind'] !== 'in') {
            self::alertLow($m, $levels['stock'] - (float) $p['quantity']);
        }

        return $mv;
    }

    public function storeMovement(Request $request, $id)
    {
        $data = $request->validate([
            'kind' => 'required|in:in,out,writeoff', 'quantity' => 'required|'.self::QTY, 'price' => 'sometimes|nullable|'.self::PRICE,
            'project_id' => 'sometimes|nullable|integer', 'counterparty_id' => 'sometimes|nullable|integer', 'doc_number' => 'sometimes|string|max:50',
            'moved_on' => 'sometimes|nullable|date_format:Y-m-d', 'note' => 'sometimes|string|max:300',
        ]);
        $m = $this->material($id);
        if (in_array($data['kind'], ['out', 'writeoff'], true) && ! Perm::allows($this->user(), 'materials.issue')) {
            ApiError::abort(403, 'forbidden');
        }
        $mv = $this->addMovement($this->user(), $m, $data);

        return response()->json(self::movementOut($mv->fresh(), $this->projectNames()), 201);
    }

    // ---------- заявки с объектов ----------

    private static function requestOut(MaterialRequest $r, array $projects, array $levels): array
    {
        $lv = $r->material_id ? ($levels[$r->material_id] ?? null) : null;
        $remaining = max($r->quantity - $r->issued, 0);
        $open = in_array($r->status, Stock::OPEN_REQUEST, true);

        return [
            'id' => $r->id, 'project_id' => $r->project_id, 'project_name' => $projects[$r->project_id] ?? '', 'material_id' => $r->material_id, 'item' => $r->item,
            'quantity' => (float) $r->quantity, 'unit' => $r->unit, 'issued' => (float) $r->issued, 'remaining' => round($remaining, 3), 'needed_by' => Fmt::d($r->needed_by),
            'reason' => $r->reason, 'status' => $r->status, 'purchase_id' => $r->purchase_id, 'created_by' => $r->created_by,
            'created_at' => Fmt::dt($r->created_at),
            'in_stock' => $lv ? $lv['stock'] : null, 'shortage' => $lv && $open ? round(max($remaining - $lv['stock'], 0), 3) : null,
            'overdue' => (bool) ($r->needed_by && $r->needed_by->lt(today()) && $open),
        ];
    }

    private function request_($id): MaterialRequest
    {
        return $this->own(MaterialRequest::class, $id, 'request_not_found');
    }

    /** Все заявки организации: склад видит здесь, что нужно выдать. */
    public function requests(Request $request)
    {
        $query = $this->scopeProjects(MaterialRequest::org($this->org()));
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        $names = $this->projectNames();
        $levels = Stock::levels($this->org());

        return $query->orderByDesc('id')->limit(500)->get()->map(fn ($r) => self::requestOut($r, $names, $levels));
    }

    public function projectRequests($projectId)
    {
        $this->ownProject($projectId);
        $names = $this->projectNames();
        $levels = Stock::levels($this->org());

        return MaterialRequest::where('project_id', (int) $projectId)->orderByDesc('id')->get()->map(fn ($r) => self::requestOut($r, $names, $levels));
    }

    /** Заявка прораба на материалы для объекта. */
    public function storeRequest(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate([
            'material_id' => 'sometimes|nullable|integer', 'item' => 'sometimes|string|max:200', 'quantity' => 'required|'.self::QTY,
            'unit' => 'sometimes|string|max:30', 'needed_by' => 'sometimes|nullable|date_format:Y-m-d', 'reason' => 'sometimes|string|max:300',
        ]);
        $material = ($data['material_id'] ?? null) !== null ? $this->material($data['material_id']) : null;
        $item = $material ? $material->name : trim($data['item'] ?? '');
        if ($item === '') {
            ApiError::abort(422, 'item_required');
        }
        $user = $this->user();
        $req = MaterialRequest::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'material_id' => $material?->id, 'item' => $item,
            'quantity' => (float) $data['quantity'], 'unit' => $material ? $material->unit : trim($data['unit'] ?? ''), 'needed_by' => $data['needed_by'] ?? null,
            'reason' => trim($data['reason'] ?? ''), 'created_by' => $user->name,
        ]);
        Notify::push($project->organization_id, Notify::people($project->organization_id, ['owner', 'storekeeper']),
            "Заявка на материалы («{$project->name}»): $item, ".Fmt::g($req->quantity)." {$req->unit}", $project->id, '#/warehouse', null, $user->id);

        return response()->json(self::requestOut($req->fresh(), [$project->id => $project->name], Stock::levels($project->organization_id)), 201);
    }

    /** Выдать материал по заявке. Если на складе меньше, выдаётся сколько есть, остаток можно отправить в закупку. */
    public function issue(Request $request, $id)
    {
        $data = $request->validate(['quantity' => 'sometimes|nullable|'.self::QTY, 'material_id' => 'sometimes|nullable|integer']);
        $req = $this->request_($id);
        if (! in_array($req->status, Stock::OPEN_REQUEST, true)) {
            ApiError::abort(409, 'request_closed');
        }
        if ($req->material_id === null) {
            if (($data['material_id'] ?? null) === null) {
                ApiError::abort(422, 'material_not_linked');
            }
            $req->material_id = $this->material($data['material_id'])->id;
        }
        $m = Material::find($req->material_id);
        $stock = Stock::levels($req->organization_id)[$m->id]['stock'];
        $remaining = round($req->quantity - $req->issued, 3);
        $now = min(($data['quantity'] ?? null) ? (float) $data['quantity'] : $remaining, $remaining, $stock);
        if ($now <= 0) {
            ApiError::abort(409, 'not_enough_stock');
        }
        $user = $this->user();
        $this->addMovement($user, $m, ['kind' => 'out', 'quantity' => $now, 'project_id' => $req->project_id, 'note' => "Заявка №{$req->id}"], $req->id);
        $req->issued = round($req->issued + $now, 3);
        $req->status = $req->issued >= $req->quantity ? 'issued' : 'partial';
        $req->save();
        $project = Project::find($req->project_id);
        Notify::push($req->organization_id, Notify::people($req->organization_id, ['owner', 'pm', 'foreman']),
            "Материал выдан на «{$project->name}»: {$m->name}, ".Fmt::g($now)." {$m->unit}".($req->status === 'partial' ? ' (не всё)' : ''),
            $req->project_id, '#/warehouse', null, $user->id);

        return self::requestOut($req, $this->projectNames(), Stock::levels($req->organization_id));
    }

    /** Недостающее по заявке отправить в закупку: создаётся закупка со статусом «нужно купить». */
    public function toPurchase($id)
    {
        $req = $this->request_($id);
        $shortage = round($req->quantity - $req->issued, 3);
        if (! in_array($req->status, Stock::OPEN_REQUEST, true) || $shortage <= 0) {
            ApiError::abort(409, 'nothing_to_buy');
        }
        if ($req->purchase_id !== null) {
            ApiError::abort(409, 'purchase_exists');
        }
        $purchase = Purchase::create(['organization_id' => $req->organization_id, 'project_id' => $req->project_id, 'item' => $req->item, 'quantity' => $shortage, 'unit' => $req->unit, 'amount' => 0, 'status' => 'request']);
        $req->purchase_id = $purchase->id;
        $req->status = 'purchase';
        $req->save();
        $project = Project::find($req->project_id);
        Notify::push($req->organization_id, Notify::people($req->organization_id, ['owner', 'buyer']),
            "Нужно купить для «{$project->name}»: {$req->item}, ".Fmt::g($shortage)." {$req->unit}", $req->project_id, "#/project/{$req->project_id}/purchases", null, $this->user()->id);

        return ['purchase_id' => $purchase->id, 'quantity' => $shortage, 'request' => self::requestOut($req, $this->projectNames(), Stock::levels($req->organization_id))];
    }

    public function cancel($id)
    {
        $req = $this->request_($id);
        if (! in_array($req->status, Stock::OPEN_REQUEST, true) || $req->issued > 0) {
            ApiError::abort(409, 'request_closed');
        }
        $req->status = 'cancelled';
        $req->save();

        return self::requestOut($req, $this->projectNames(), Stock::levels($req->organization_id));
    }

    /** Закупка приехала: материал ставится на склад (создаётся в справочнике, если его нет), закупка получает статус «привезли». */
    public function receivePurchase(Request $request, $purchaseId)
    {
        $data = $request->validate(['price' => 'sometimes|nullable|'.self::PRICE, 'doc_number' => 'sometimes|string|max:50', 'moved_on' => 'sometimes|nullable|date_format:Y-m-d']);
        $purchase = $this->own(Purchase::class, $purchaseId, 'purchase_not_found');
        if (in_array($purchase->status, ['delivered', 'paid'], true)) {
            ApiError::abort(409, 'already_received');
        }
        $name = trim($purchase->item);
        $material = Material::org($this->org())->get()->first(fn ($x) => mb_strtolower($x->name) === mb_strtolower($name));
        if ($material === null) {
            $material = Material::create(['organization_id' => $this->org(), 'name' => mb_substr($name, 0, 200), 'unit' => mb_substr($purchase->unit ?: 'шт', 0, 30)]);
        }
        $price = $data['price'] ?? null;
        if ($price === null) {
            $price = $purchase->amount && $purchase->quantity ? round($purchase->amount / $purchase->quantity, 2) : 0;
        }
        $mv = $this->addMovement($this->user(), $material, [
            'kind' => 'in', 'quantity' => $purchase->quantity, 'price' => $price, 'counterparty_id' => $purchase->counterparty_id,
            'doc_number' => $data['doc_number'] ?? '', 'moved_on' => $data['moved_on'] ?? null, 'note' => "Закупка №{$purchase->id}",
        ], null, $purchase->id);
        $purchase->status = 'delivered';
        $purchase->save();

        return response()->json([
            'material_id' => $material->id,
            'movement' => self::movementOut($mv->fresh(), []),
            'stock' => self::materialOut($material->fresh(), Stock::levels($this->org())[$material->id]),
        ], 201);
    }

    /** Что выдано на объект и на какую сумму; рядом плановое количество из утверждённой сметы. */
    public function projectMaterials($projectId)
    {
        $project = $this->ownProject($projectId);
        $materials = Material::where('organization_id', $project->organization_id)->get()->keyBy('id');
        $used = [];
        foreach (StockMovement::where('project_id', $project->id)->where('kind', 'out')->get() as $mv) {
            $used[$mv->material_id] ??= ['quantity' => 0.0, 'cost' => 0.0];
            $used[$mv->material_id]['quantity'] += $mv->quantity;
            $used[$mv->material_id]['cost'] += $mv->quantity * $mv->price;
        }
        $plan = [];
        $approved = Estimate::where('project_id', $project->id)->where('status', 'approved')->orderByDesc('id')->first();
        if ($approved !== null) {
            foreach (EstimateItem::where('estimate_id', $approved->id)->whereNotNull('material_id')->get() as $item) {
                $plan[$item->material_id] = ($plan[$item->material_id] ?? 0) + $item->quantity;
            }
        }
        $ids = array_unique(array_merge(array_keys($used), array_keys($plan)));
        usort($ids, fn ($a, $b) => strcmp($materials[$a]->name ?? '', $materials[$b]->name ?? ''));
        $rows = [];
        foreach ($ids as $materialId) {
            $m = $materials[$materialId] ?? null;
            if ($m === null) {
                continue;
            }
            $fact = $used[$materialId] ?? ['quantity' => 0.0, 'cost' => 0.0];
            $planned = $plan[$materialId] ?? null;
            $rows[] = [
                'material_id' => $materialId, 'name' => $m->name, 'unit' => $m->unit, 'issued' => round($fact['quantity'], 3), 'cost' => round($fact['cost'], 2),
                'planned' => $planned !== null ? round($planned, 3) : null, 'over_plan' => $planned !== null && $fact['quantity'] > $planned,
            ];
        }
        $openRequests = MaterialRequest::where('project_id', $project->id)->whereIn('status', Stock::OPEN_REQUEST)->count();

        return ['items' => $rows, 'total_cost' => round(array_sum(array_column($used, 'cost')), 2), 'open_requests' => $openRequests];
    }
}
