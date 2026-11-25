<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Сметы: позиции по разделам, наценка, скидка, дополнительные расходы, версии, утверждение и сравнение версий.
 * Правка утверждённой сметы это новая версия, прежняя уходит в архив после утверждения новой.
 */
class EstimateController extends Controller
{
    public const SECTIONS = ['materials', 'work', 'equipment', 'transport', 'subcontract', 'other'];

    private const SECTION_TO_BUDGET = ['materials' => 'materials', 'work' => 'labor', 'equipment' => 'equipment', 'transport' => 'transport', 'subcontract' => 'subcontract', 'other' => 'other'];

    private const QTY = 'numeric|gt:0|max:1000000000|decimal:0,3';

    private const PRICE = 'numeric|min:0|max:100000000000|decimal:0,2';

    private const PCT = 'numeric|min:0|max:500|decimal:0,2';

    private const DISCOUNT = 'numeric|min:0|max:100|decimal:0,2';

    private static function c(float $value): float
    {
        return round($value, 2);
    }

    /** Итоги: по разделам (себестоимость), наценка, скидка, дополнительные расходы и общая сумма. */
    public static function totals(Estimate $est, $items): array
    {
        $bySection = array_fill_keys(self::SECTIONS, 0.0);
        foreach ($items as $item) {
            $bySection[$item->section] += $item->quantity * $item->price;
        }
        $subtotal = array_sum($bySection);
        $withMarkup = $subtotal * (1 + $est->markup_pct / 100);
        $afterDiscount = $withMarkup * (1 - $est->discount_pct / 100);
        $total = round($afterDiscount + $est->extra, 2);

        return [
            'by_section' => array_map(fn ($v) => self::c($v), $bySection),
            'subtotal' => self::c($subtotal), 'markup' => self::c($withMarkup - $subtotal),
            'discount' => self::c($withMarkup - $afterDiscount), 'extra' => (float) $est->extra, 'total' => $total,
        ];
    }

    /** Есть ли смета с таким названием (без учёта регистра). */
    private static function titleTaken(int $projectId, string $title, ?int $exceptId = null): bool
    {
        $wanted = mb_strtolower($title);

        return Estimate::where('project_id', $projectId)->get()->contains(fn ($e) => $e->id !== $exceptId && mb_strtolower($e->title) === $wanted);
    }

    private static function items(Estimate $est)
    {
        return EstimateItem::where('estimate_id', $est->id)->orderBy('position')->orderBy('id')->get();
    }

    public static function out(Estimate $est, bool $withItems = true): array
    {
        $items = self::items($est);
        $out = [
            'id' => $est->id, 'project_id' => $est->project_id, 'title' => $est->title, 'version' => $est->version, 'status' => $est->status,
            'markup_pct' => (float) $est->markup_pct, 'discount_pct' => (float) $est->discount_pct, 'note' => $est->note, 'parent_id' => $est->parent_id,
            'created_by' => $est->created_by, 'approved_by' => $est->approved_by, 'approved_at' => Fmt::dt($est->approved_at),
            'created_at' => Fmt::dt($est->created_at), 'items_count' => $items->count(),
        ] + self::totals($est, $items);
        if ($withItems) {
            $out['items'] = $items->map(fn ($i) => [
                'id' => $i->id, 'section' => $i->section, 'title' => $i->title, 'quantity' => (float) $i->quantity, 'unit' => $i->unit,
                'price' => (float) $i->price, 'sum' => self::c($i->quantity * $i->price), 'material_id' => $i->material_id,
            ])->all();
        }

        return $out;
    }

    private function estimate($id): Estimate
    {
        return $this->own(Estimate::class, $id, 'estimate_not_found');
    }

    private static function itemRules(): array
    {
        return [
            'items.*.section' => 'required|in:'.implode(',', self::SECTIONS),
            'items.*.title' => 'required',
            'items.*.quantity' => 'sometimes|'.self::QTY,
            'items.*.unit' => 'sometimes|string|max:30',
            'items.*.price' => 'sometimes|'.self::PRICE,
            'items.*.material_id' => 'sometimes|nullable|integer',
        ];
    }

    private static function cleanTitle($value, int $max, string $field): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => $field]);
        }

        return $value;
    }

    private function writeItems(Estimate $est, array $items): void
    {
        $catalog = Material::org($this->org())->pluck('id')->all();
        foreach ($items as $i => $item) {
            $items[$i]['title'] = self::cleanTitle($item['title'], 300, "items.$i.title");
            if (($item['material_id'] ?? null) !== null && ! in_array((int) $item['material_id'], $catalog, true)) {
                ApiError::abort(404, 'material_not_found');
            }
        }
        EstimateItem::where('estimate_id', $est->id)->delete();
        foreach (array_values($items) as $position => $item) {
            EstimateItem::create([
                'organization_id' => $est->organization_id, 'estimate_id' => $est->id, 'position' => $position, 'section' => $item['section'],
                'title' => $item['title'], 'quantity' => (float) ($item['quantity'] ?? 1), 'unit' => trim($item['unit'] ?? ''),
                'price' => (float) ($item['price'] ?? 0), 'material_id' => $item['material_id'] ?? null,
            ]);
        }
    }

    public function index($projectId)
    {
        $this->ownProject($projectId);

        return Estimate::where('project_id', (int) $projectId)->orderBy('title')->orderByDesc('version')->get()->map(fn ($e) => self::out($e, false));
    }

    /** Новая смета (версия 1). Если смета с таким названием уже есть, используйте «новая версия». */
    public function store(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate([
            'title' => 'required', 'markup_pct' => 'sometimes|'.self::PCT, 'discount_pct' => 'sometimes|'.self::DISCOUNT,
            'extra' => 'sometimes|'.self::PRICE, 'note' => 'sometimes|string|max:1000', 'items' => 'sometimes|array|max:500',
        ] + self::itemRules());
        $title = self::cleanTitle($data['title'], 200, 'title');
        if (self::titleTaken($project->id, $title)) {
            ApiError::abort(409, 'estimate_exists');
        }
        $est = Estimate::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'title' => $title, 'version' => 1,
            'markup_pct' => (float) ($data['markup_pct'] ?? 0), 'discount_pct' => (float) ($data['discount_pct'] ?? 0), 'extra' => (float) ($data['extra'] ?? 0),
            'note' => trim($data['note'] ?? ''), 'created_by' => $this->user()->name,
        ]);
        $this->writeItems($est, $data['items'] ?? []);

        return response()->json(self::out($est->fresh()), 201);
    }

    /** Сравнение двух версий: что добавлено, убрано и изменилось, и насколько изменилась общая стоимость. */
    public function compare(Request $request)
    {
        $a = $this->qInt($request, 'a');
        $b = $this->qInt($request, 'b');
        if ($a === null || $b === null) {
            throw ValidationException::withMessages(['a' => 'required']);
        }
        $left = $this->estimate($a);
        $right = $this->estimate($b);
        if ($left->project_id !== $right->project_id) {
            ApiError::abort(422, 'different_projects');
        }
        $keyed = function (Estimate $est) {
            $out = [];
            foreach (self::items($est) as $i) {
                $out[$i->section.'|'.mb_strtolower($i->title)] = $i;
            }

            return $out;
        };
        $ka = $keyed($left);
        $kb = $keyed($right);
        $row = fn ($i) => ['section' => $i->section, 'title' => $i->title, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'price' => (float) $i->price, 'sum' => round($i->quantity * $i->price, 2)];
        $added = array_values(array_map($row, array_diff_key($kb, $ka)));
        $removed = array_values(array_map($row, array_diff_key($ka, $kb)));
        $changed = [];
        $common = array_intersect_key($ka, $kb);
        foreach (array_keys($common) as $k) {
            [$x, $y] = [$ka[$k], $kb[$k]];
            if ([$x->quantity, $x->price, $x->unit] != [$y->quantity, $y->price, $y->unit]) {
                $changed[] = ['section' => $x->section, 'title' => $y->title, 'before' => $row($x), 'after' => $row($y), 'diff' => round($y->quantity * $y->price - $x->quantity * $x->price, 2)];
            }
        }
        usort($changed, fn ($p, $q) => strcmp($p['title'], $q['title']));
        $ta = self::totals($left, self::items($left))['total'];
        $tb = self::totals($right, self::items($right))['total'];

        return [
            'a' => ['id' => $left->id, 'version' => $left->version, 'status' => $left->status, 'total' => $ta],
            'b' => ['id' => $right->id, 'version' => $right->version, 'status' => $right->status, 'total' => $tb],
            'diff_total' => round($tb - $ta, 2), 'diff_percent' => $ta ? round(($tb - $ta) / $ta * 100, 2) : null,
            'added' => $added, 'removed' => $removed, 'changed' => $changed, 'unchanged' => count($common) - count($changed),
        ];
    }

    public function show($id)
    {
        return self::out($this->estimate($id));
    }

    /** Править можно только черновик. Утверждённую смету меняют новой версией. */
    public function update(Request $request, $id)
    {
        $est = $this->estimate($id);
        if ($est->status !== 'draft') {
            ApiError::abort(409, 'not_draft');
        }
        $data = $request->validate([
            'title' => 'sometimes|nullable', 'markup_pct' => 'sometimes|nullable|'.self::PCT, 'discount_pct' => 'sometimes|nullable|'.self::DISCOUNT,
            'extra' => 'sometimes|nullable|'.self::PRICE, 'note' => 'sometimes|nullable|string|max:1000', 'items' => 'sometimes|nullable|array|max:500',
        ] + self::itemRules());
        if (($data['title'] ?? null) !== null) {
            $data['title'] = self::cleanTitle($data['title'], 200, 'title');
            if (self::titleTaken($est->project_id, $data['title'], $est->id)) {
                ApiError::abort(409, 'estimate_exists');
            }
        }
        foreach (['title', 'markup_pct', 'discount_pct', 'extra', 'note'] as $key) {
            if (($data[$key] ?? null) !== null) {
                $est->{$key} = is_string($data[$key]) && in_array($key, ['title', 'note'], true) ? trim($data[$key]) : (float) $data[$key];
            }
        }
        $est->save();
        if (($data['items'] ?? null) !== null) {
            $this->writeItems($est, $data['items']);
        }

        return self::out($est->fresh());
    }

    /** Копия сметы как новый черновик: версия растёт, прежняя остаётся как есть. */
    public function newVersion($id)
    {
        $src = $this->estimate($id);
        $latest = Estimate::where('project_id', $src->project_id)->where('title', $src->title)->max('version');
        if (Estimate::where('project_id', $src->project_id)->where('title', $src->title)->where('status', 'draft')->exists()) {
            ApiError::abort(409, 'draft_exists');
        }
        $est = Estimate::create([
            'organization_id' => $src->organization_id, 'project_id' => $src->project_id, 'title' => $src->title, 'version' => ($latest ?: 1) + 1,
            'markup_pct' => $src->markup_pct, 'discount_pct' => $src->discount_pct, 'extra' => $src->extra, 'note' => $src->note,
            'parent_id' => $src->id, 'created_by' => $this->user()->name,
        ]);
        foreach (self::items($src) as $item) {
            EstimateItem::create([
                'organization_id' => $est->organization_id, 'estimate_id' => $est->id, 'position' => $item->position, 'section' => $item->section,
                'title' => $item->title, 'quantity' => $item->quantity, 'unit' => $item->unit, 'price' => $item->price, 'material_id' => $item->material_id,
            ]);
        }

        return response()->json(self::out($est->fresh()), 201);
    }

    /** Утвердить смету. Прежняя утверждённая версия этой сметы уходит в архив. */
    public function approve($id)
    {
        $est = $this->estimate($id);
        if ($est->status !== 'draft') {
            ApiError::abort(409, 'not_draft');
        }
        if (self::items($est)->isEmpty()) {
            ApiError::abort(422, 'empty_estimate');
        }
        Estimate::where('project_id', $est->project_id)->where('title', $est->title)->where('status', 'approved')->update(['status' => 'archived']);
        $user = $this->user();
        $est->fill(['status' => 'approved', 'approved_by' => $user->name, 'approved_at' => now()])->save();
        Notify::push($est->organization_id, Notify::people($est->organization_id, ['pm', 'owner', 'buyer']), "Смета «{$est->title}» (версия {$est->version}) утверждена",
            $est->project_id, "#/project/{$est->project_id}/estimates", null, $user->id);

        return self::out($est);
    }

    /** Перенести утверждённую смету в бюджет объекта: плановые расходы по статьям берутся из разделов сметы. */
    public function applyBudget(Request $request, $id)
    {
        $data = $request->validate(['set_contract_value' => 'sometimes|boolean']);
        $est = $this->estimate($id);
        if ($est->status !== 'approved') {
            ApiError::abort(409, 'not_approved');
        }
        $parts = self::totals($est, self::items($est));
        $planned = array_fill_keys(['materials', 'labor', 'subcontract', 'equipment', 'transport', 'other'], 0.0);
        foreach ($parts['by_section'] as $section => $amount) {
            $planned[self::SECTION_TO_BUDGET[$section]] += $amount;
        }
        $planned['other'] += $est->extra;
        $existing = BudgetLine::where('project_id', $est->project_id)->get()->keyBy('category');
        foreach ($planned as $category => $amount) {
            if (isset($existing[$category])) {
                $existing[$category]->planned = round($amount, 2);
                $existing[$category]->save();
            } else {
                BudgetLine::create(['organization_id' => $est->organization_id, 'project_id' => $est->project_id, 'category' => $category, 'planned' => round($amount, 2)]);
            }
        }
        $project = $this->ownProject($est->project_id);
        if (! empty($data['set_contract_value'])) {
            $project->contract_value = $parts['total'];
            $project->save();
        }

        return ['budget' => array_map(fn ($v) => round($v, 2), $planned), 'contract_value' => (float) $project->contract_value];
    }

    public function destroy($id)
    {
        $est = $this->estimate($id);
        if ($est->status !== 'draft') {
            ApiError::abort(409, 'not_draft');
        }
        EstimateItem::where('estimate_id', $est->id)->delete();
        Estimate::where('parent_id', $est->id)->update(['parent_id' => null]);
        $est->delete();

        return $this->noContent();
    }
}
