<?php

namespace App\Support;

use App\Models\BudgetLine;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentPlanItem;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\TimeEntry;
use Carbon\Carbon;

/**
 * Финансы объекта: договорная стоимость, график платежей заказчика, бюджет по статьям, факт и прогноз.
 *
 * Как считается факт расходов (чтобы не считать одно и то же дважды):
 * - чеки (документы типа «чек», кроме отклонённых) по их статье;
 * - закупки со статусом «привезли» или «оплачено» (статья «материалы»);
 * - зарплата по табелю (часы на ставку, статья «работы»).
 * Закупки со статусом «заказано» это обязательства: они не факт, но входят в прогноз.
 * Банковские платежи, привязанные к объекту, показаны отдельно и в сумму не входят.
 */
class Finance
{
    public const CATEGORIES = ['materials', 'labor', 'subcontract', 'equipment', 'transport', 'other'];

    // статьи чеков -> статьи бюджета
    public const EXPENSE_MAP = [
        'materials' => 'materials', 'tools' => 'equipment', 'equipment' => 'equipment', 'fuel' => 'transport', 'transport' => 'transport',
        'labor' => 'labor', 'subcontract' => 'subcontract', 'other' => 'other', '' => 'other',
    ];

    public static function planStatus(PaymentPlanItem $item, Carbon $today): string
    {
        if ($item->received >= $item->amount) {
            return 'paid';
        }
        if ($item->due_date->lt($today)) {
            return 'overdue';
        }

        return $item->received > 0 ? 'partial' : 'upcoming';
    }

    public static function itemOut(PaymentPlanItem $item, Carbon $today): array
    {
        return [
            'id' => $item->id, 'title' => $item->title, 'due_date' => Fmt::d($item->due_date), 'amount' => (float) $item->amount,
            'received' => (float) $item->received, 'remaining' => round($item->amount - $item->received, 2), 'status' => self::planStatus($item, $today),
            'received_on' => Fmt::d($item->received_on), 'note' => $item->note,
        ];
    }

    /** Все цифры по деньгам объекта. Используется и страницей финансов, и панелью руководителя. */
    public static function compute(Project $project, ?Carbon $today = null, bool $withItems = true): array
    {
        $today ??= today();
        $org = $project->organization_id;
        $zero = array_fill_keys(self::CATEGORIES, 0.0);
        $planned = $zero;
        foreach (BudgetLine::where('project_id', $project->id)->get() as $line) {
            $planned[$line->category] = (float) $line->planned;
        }
        $fact = $zero;
        $committed = $zero;
        $sources = ['receipts' => 0.0, 'purchases' => 0.0, 'labor' => 0.0];
        foreach (Document::org($org)->where('project_id', $project->id)->where('doc_type', 'receipt')->where('status', '!=', 'rejected')->get() as $doc) {
            if ($doc->amount !== null) {
                $fact[self::EXPENSE_MAP[$doc->category ?? ''] ?? 'other'] += $doc->amount;
                $sources['receipts'] += $doc->amount;
            }
        }
        foreach (Purchase::org($org)->where('project_id', $project->id)->get() as $purchase) {
            if (in_array($purchase->status, ['delivered', 'paid'], true)) {
                $fact['materials'] += $purchase->amount;
                $sources['purchases'] += $purchase->amount;
            } elseif ($purchase->status === 'ordered') {
                $committed['materials'] += $purchase->amount;
            }
        }
        foreach (TimeEntry::where('organization_id', $org)->where('project_id', $project->id)->get() as $entry) {
            $pay = $entry->hours * $entry->rate;
            $fact['labor'] += $pay;
            $sources['labor'] += $pay;
        }
        $bankIn = 0.0;
        $bankOut = 0.0;
        foreach (Payment::org($org)->where('project_id', $project->id)->get() as $pay) {
            if ($pay->direction === 'in') {
                $bankIn += $pay->amount;
            } else {
                $bankOut += $pay->amount;
            }
        }

        $lines = [];
        foreach (self::CATEGORIES as $category) {
            $forecast = max($planned[$category], $fact[$category] + $committed[$category]);
            $lines[] = [
                'category' => $category, 'planned' => round($planned[$category], 2), 'fact' => round($fact[$category], 2),
                'committed' => round($committed[$category], 2), 'forecast' => round($forecast, 2),
                'over' => $forecast > $planned[$category] + 0.001 && ($planned[$category] > 0 || $fact[$category] > 0),
            ];
        }
        $plannedTotal = array_sum($planned);
        $factTotal = array_sum($fact);
        $forecastTotal = array_sum(array_column($lines, 'forecast'));

        $items = PaymentPlanItem::where('project_id', $project->id)->orderBy('due_date')->orderBy('id')->get();
        $contract = (float) ($project->contract_value ?? 0);
        $received = (float) $items->sum('received');
        $overdueItems = $items->filter(fn ($i) => self::planStatus($i, $today) === 'overdue');
        $overdueAmount = (float) $overdueItems->sum(fn ($i) => $i->amount - $i->received);
        $next = $items->first(fn ($i) => $i->received < $i->amount);

        $marginPlanned = $contract ? $contract - $plannedTotal : null;
        $marginForecast = $contract ? $contract - $forecastTotal : null;
        $alerts = [];
        if ($plannedTotal > 0 && $forecastTotal > $plannedTotal + 0.001) {
            $alerts[] = 'Прогноз расходов превышает бюджет на '.Fmt::money0($forecastTotal - $plannedTotal).' ₽';
        }
        foreach ($lines as $line) {
            if ($line['over'] && $line['planned'] > 0) {
                $alerts[] = "Статья «{$line['category']}» вышла за бюджет";
            }
        }
        if ($overdueItems->isNotEmpty()) {
            $alerts[] = 'Просрочены платежи заказчика: '.Fmt::money0($overdueAmount).' ₽';
        }
        if ($marginForecast !== null && $marginForecast < 0) {
            $alerts[] = 'Прогнозируемый результат объекта отрицательный';
        }

        $out = [
            'project' => ['id' => $project->id, 'name' => $project->name, 'contract_value' => $contract],
            'revenue' => [
                'contract_value' => $contract, 'received' => round($received, 2), 'receivable' => round($contract - $received, 2),
                'overdue_amount' => round($overdueAmount, 2), 'overdue_count' => $overdueItems->count(),
                'scheduled_total' => (float) $items->sum('amount'),
                'next_payment' => $next ? self::itemOut($next, $today) : null,
                'bank_in' => round($bankIn, 2),
            ],
            'budget' => [
                'planned_total' => round($plannedTotal, 2), 'fact_total' => round($factTotal, 2), 'forecast_total' => round($forecastTotal, 2),
                'remaining' => round(max($plannedTotal - $factTotal, 0), 2), 'deviation' => round($forecastTotal - $plannedTotal, 2),
                'used_percent' => $plannedTotal > 0 ? Site::pyRound($factTotal / $plannedTotal * 100) : null,
                'over_budget' => $plannedTotal > 0 && $forecastTotal > $plannedTotal + 0.001, 'lines' => $lines,
            ],
            'result' => [
                'planned_margin' => $marginPlanned !== null ? round($marginPlanned, 2) : null,
                'forecast_margin' => $marginForecast !== null ? round($marginForecast, 2) : null,
                'forecast_margin_percent' => $marginForecast !== null && $contract ? round($marginForecast / $contract * 100, 1) : null,
            ],
            'sources' => ['receipts' => round($sources['receipts'], 2), 'purchases' => round($sources['purchases'], 2), 'labor' => round($sources['labor'], 2), 'bank_out' => round($bankOut, 2)],
            'alerts' => $alerts,
        ];
        if ($withItems) {
            $out['plan'] = $items->map(fn ($i) => self::itemOut($i, $today))->all();
        }

        return $out;
    }
}
