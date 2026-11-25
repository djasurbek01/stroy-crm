<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Goal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\CrmEngine;
use App\Support\Fmt;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Аналитика воронки: сделки по этапам, конверсия, длительность этапов, цели и план. */
class AnalyticsController extends Controller
{
    /** [начало месяца, начало следующего]. */
    public static function monthBounds(string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month) || (int) substr($month, 5, 2) < 1 || (int) substr($month, 5, 2) > 12) {
            ApiError::abort(422, 'bad_month');
        }
        $start = Carbon::createFromFormat('!Y-m', $month);

        return [$start, $start->copy()->addMonth()];
    }

    /** Сумма и число успешных сделок, закрытых в месяце. */
    public static function wonInMonth(int $org, string $month): array
    {
        [$start, $end] = self::monthBounds($month);
        $wonIds = Stage::org($org)->where('kind', 'won')->pluck('id');
        if ($wonIds->isEmpty()) {
            return [0.0, 0];
        }
        $deals = Deal::org($org)->whereIn('stage_id', $wonIds)->whereNotNull('closed_at')
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)->get();

        return [(float) $deals->sum('amount'), $deals->count()];
    }

    public static function goalOut(int $org, string $month): array
    {
        $goal = Goal::where('organization_id', $org)->where('month', $month)->first();
        $amount = $goal ? (float) $goal->amount : 0.0;
        [$sumWon, $countWon] = self::wonInMonth($org, $month);

        return [
            'month' => $month,
            'amount' => $amount,
            'won_sum' => $sumWon,
            'won_count' => $countWon,
            'progress' => $amount ? (int) round($sumWon / $amount * 100) : null,
        ];
    }

    public function funnel(Request $request)
    {
        $org = $this->org();
        CrmEngine::ensureDefault($org);
        $pipelineId = $this->qInt($request, 'pipeline_id');
        $days = $this->qInt($request, 'days');
        $pipelines = Pipeline::org($org)->orderBy('position')->orderBy('id')->get();
        $pipeline = $pipelineId ? $pipelines->firstWhere('id', $pipelineId) : $pipelines->first();
        if ($pipeline === null) {
            ApiError::abort(404, 'pipeline_not_found');
        }

        $stages = Stage::where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->get();
        $flow = $stages->where('kind', '!=', 'lost')->values();  // путь сделки к успеху
        $rank = [];
        foreach ($flow as $i => $s) {
            $rank[$s->id] = $i;
        }
        $byId = $stages->keyBy('id');

        $query = Deal::org($org)->where('pipeline_id', $pipeline->id)->where('unsorted', false);
        if ($days) {
            $query->where('created_at', '>=', now()->subDays($days));
        }
        $deals = $query->get();
        $events = $deals->isEmpty() ? collect() : DealEvent::whereIn('deal_id', $deals->pluck('id'))->whereNotNull('to_stage_id')->orderBy('id')->get()->groupBy('deal_id');

        $now = now();
        $countNow = [];
        $sumNow = [];
        $maxRank = [];
        $durations = [];
        $cycleDays = [];
        foreach ($deals as $deal) {
            $countNow[$deal->stage_id] = ($countNow[$deal->stage_id] ?? 0) + 1;
            $sumNow[$deal->stage_id] = ($sumNow[$deal->stage_id] ?? 0) + (float) $deal->amount;
            $visits = ($events[$deal->id] ?? collect())->map(fn ($e) => [$e->created_at, $e->to_stage_id])->values()->all();
            if (! $visits || end($visits)[1] !== $deal->stage_id) {
                $visits[] = [$visits ? end($visits)[0] : $deal->created_at, $deal->stage_id];
            }
            $best = -1;
            foreach ($visits as [, $s]) {
                if (isset($rank[$s])) {
                    $best = max($best, $rank[$s]);
                }
            }
            $maxRank[$deal->id] = $best;
            foreach ($visits as $i => [$when, $stageId]) {
                $stage = $byId[$stageId] ?? null;
                if ($stage === null || $stage->kind !== 'open') {
                    continue;
                }
                $leave = $visits[$i + 1][0] ?? $now;
                $durations[$stageId][] = max($when->diffInSeconds($leave, false), 0) / 86400;
            }
            $current = $byId[$deal->stage_id] ?? null;
            if ($current !== null && $current->kind === 'won' && $deal->closed_at) {
                $cycleDays[] = max($deal->created_at->diffInSeconds($deal->closed_at, false), 0) / 86400;
            }
        }

        $rows = [];
        $previousReached = null;
        $firstReached = null;
        foreach ($flow as $i => $stage) {
            $reached = count(array_filter($maxRank, fn ($r) => $r >= $i));
            $firstReached ??= $reached;
            $d = $durations[$stage->id] ?? [];
            $rows[] = [
                'id' => $stage->id,
                'name' => $stage->name,
                'kind' => $stage->kind,
                'count' => $countNow[$stage->id] ?? 0,
                'sum' => (float) ($sumNow[$stage->id] ?? 0),
                'reached' => $reached,
                'conversion_prev' => $previousReached ? (int) round($reached / $previousReached * 100) : null,
                'avg_days' => $d ? round(array_sum($d) / count($d), 1) : null,
            ];
            $previousReached = $reached;
        }

        $lostIds = $stages->where('kind', 'lost')->pluck('id')->all();
        $wonIds = $stages->where('kind', 'won')->pluck('id')->all();
        $won = $deals->whereIn('stage_id', $wonIds);
        $lost = $deals->whereIn('stage_id', $lostIds);
        $open = $deals->whereNotIn('stage_id', array_merge($wonIds, $lostIds));
        $closed = $won->count() + $lost->count();

        return [
            'pipeline_id' => $pipeline->id,
            'stages' => $rows,
            'lost' => ['count' => $lost->count(), 'sum' => (float) $lost->sum('amount')],
            'totals' => [
                'deals' => $deals->count(),
                'open' => $open->count(),
                'open_sum' => (float) $open->sum('amount'),
                'won' => $won->count(),
                'won_sum' => (float) $won->sum('amount'),
                'lost' => $lost->count(),
                'win_rate' => $closed ? (int) round($won->count() / $closed * 100) : null,
                'avg_cycle_days' => $cycleDays ? round(array_sum($cycleDays) / count($cycleDays), 1) : null,
                'overall_conversion' => $rows && $firstReached ? (int) round(end($rows)['reached'] / $firstReached * 100) : null,
            ],
        ];
    }

    public function goal(Request $request)
    {
        return self::goalOut($this->org(), $request->query('month') ?: now()->format('Y-m'));
    }

    public function setGoal(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'amount' => 'required|numeric|min:0']);
        $goal = Goal::firstOrNew(['organization_id' => $this->org(), 'month' => $data['month']]);
        $old = (float) ($goal->amount ?? 0);
        $goal->amount = (float) $data['amount'];
        $goal->save();
        Audit::log($this->user(), 'update', 'goal', null, "План продаж на {$data['month']}: ".Fmt::money0($old).' → '.Fmt::money0((float) $data['amount']).' ₽', null, ['month' => $data['month']]);

        return self::goalOut($this->org(), $data['month']);
    }
}
