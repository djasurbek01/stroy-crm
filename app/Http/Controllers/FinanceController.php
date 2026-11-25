<?php

namespace App\Http\Controllers;

use App\Models\BudgetLine;
use App\Models\PaymentPlanItem;
use App\Support\ApiError;
use App\Support\Finance;
use App\Support\Perm;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    private const MONEY = 'numeric|min:0|max:100000000000|decimal:0,2';

    private const POSITIVE = 'numeric|gt:0|max:100000000000|decimal:0,2';

    /** Деньги объекта: что получено и ожидается, бюджет, факт, прогноз и предупреждения. */
    public function show($projectId)
    {
        return Finance::compute($this->ownProject($projectId));
    }

    /** Задать договорную стоимость и плановые расходы по статьям. Статьи, которых нет в запросе, удаляются. */
    public function budget(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate([
            'contract_value' => 'sometimes|nullable|'.self::MONEY,
            'lines' => 'sometimes|array|max:6',
            'lines.*.category' => 'required|in:'.implode(',', Finance::CATEGORIES),
            'lines.*.planned' => 'required|'.self::MONEY,
        ]);
        $lines = $data['lines'] ?? [];
        $categories = array_column($lines, 'category');
        if (count(array_unique($categories)) !== count($categories)) {
            ApiError::abort(422, 'duplicate_category');
        }
        if (($data['contract_value'] ?? null) !== null) {
            $project->contract_value = (float) $data['contract_value'];
            $project->save();
        }
        $existing = BudgetLine::where('project_id', $project->id)->get()->keyBy('category');
        foreach ($existing as $category => $row) {
            if (! in_array($category, $categories, true)) {
                $row->delete();
            }
        }
        foreach ($lines as $line) {
            if (isset($existing[$line['category']])) {
                $existing[$line['category']]->planned = (float) $line['planned'];
                $existing[$line['category']]->save();
            } else {
                BudgetLine::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'category' => $line['category'], 'planned' => (float) $line['planned']]);
            }
        }

        return Perm::allows($this->user(), 'finance.read') ? Finance::compute($project->fresh()) : ['ok' => true];
    }

    private function item($id): PaymentPlanItem
    {
        return $this->own(PaymentPlanItem::class, $id, 'plan_item_not_found');
    }

    private static function title($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > 200) {
            throw ValidationException::withMessages(['title' => 'title']);
        }

        return $value;
    }

    /** Платёж заказчика по графику из договора. */
    public function storeItem(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['title' => 'required', 'due_date' => 'required|date_format:Y-m-d', 'amount' => 'required|'.self::POSITIVE, 'note' => 'sometimes|string|max:300']);
        $item = PaymentPlanItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'title' => self::title($data['title']),
            'due_date' => $data['due_date'], 'amount' => (float) $data['amount'], 'note' => trim($data['note'] ?? ''),
        ]);

        return response()->json(Finance::itemOut($item->fresh(), today()), 201);
    }

    public function updateItem(Request $request, $id)
    {
        $item = $this->item($id);
        $changes = $request->validate(['title' => 'sometimes|nullable', 'due_date' => 'sometimes|nullable|date_format:Y-m-d', 'amount' => 'sometimes|nullable|'.self::POSITIVE, 'note' => 'sometimes|nullable|string|max:300']);
        if (($changes['title'] ?? null) !== null) {
            $changes['title'] = self::title($changes['title']);
        }
        if (($changes['amount'] ?? null) !== null && (float) $changes['amount'] < $item->received) {
            ApiError::abort(422, 'amount_below_received');
        }
        foreach ($changes as $key => $value) {
            if ($value !== null) {
                $item->{$key} = is_string($value) && $key !== 'due_date' ? trim($value) : $value;
            }
        }
        $item->save();

        return Finance::itemOut($item->fresh(), today());
    }

    public function destroyItem($id)
    {
        $item = $this->item($id);
        if ($item->received > 0) {
            ApiError::abort(409, 'plan_item_paid');  // поступившие деньги из учёта не пропадают
        }
        $item->delete();

        return $this->noContent();
    }

    /** Записать поступление от заказчика по платежу графика. */
    public function receive(Request $request, $id)
    {
        $data = $request->validate(['amount' => 'required|'.self::POSITIVE, 'received_on' => 'sometimes|nullable|date_format:Y-m-d']);
        $item = $this->item($id);
        if ((float) $data['amount'] > $item->amount - $item->received + 0.0001) {
            ApiError::abort(422, 'over_received');
        }
        $item->received = round($item->received + (float) $data['amount'], 2);
        $item->received_on = $data['received_on'] ?? today();
        $item->save();  // в ленту событий (её видит заказчик) деньги не пишем

        return Finance::itemOut($item->fresh(), today());
    }
}
