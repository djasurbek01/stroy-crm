<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Stage;
use App\Models\StageAutomation;
use App\Models\Todo;
use App\Models\User;

/** Воронки: шаблон по умолчанию и автоматические действия на этапах. */
class CrmEngine
{
    public const DEFAULT_STAGES = [
        ['Заявка', 'open'],
        ['Замер или осмотр', 'open'],
        ['Смета и КП', 'open'],
        ['Договор подписан', 'open'],
        ['Объект в работе', 'open'],
        ['Сдача', 'open'],
        ['Оплачено', 'won'],
        ['Отказ', 'lost'],
    ];

    public const SIGNED_STAGE = 'Договор подписан';

    /** Создаёт воронку «Продажи», если у организации ещё нет ни одной. */
    public static function ensureDefault(int $orgId): void
    {
        if (Pipeline::where('organization_id', $orgId)->exists()) {
            return;
        }
        Audit::paused(function () use ($orgId) {
            $pipeline = Pipeline::create(['organization_id' => $orgId, 'name' => 'Продажи', 'position' => 0]);
            foreach (self::DEFAULT_STAGES as $position => [$name, $kind]) {
                $stage = Stage::create(['organization_id' => $orgId, 'pipeline_id' => $pipeline->id, 'name' => $name, 'position' => $position, 'kind' => $kind]);
                if ($name === self::SIGNED_STAGE) {
                    StageAutomation::create(['organization_id' => $orgId, 'stage_id' => $stage->id, 'action' => 'create_project', 'params' => []]);
                    StageAutomation::create(['organization_id' => $orgId, 'stage_id' => $stage->id, 'action' => 'create_tasks', 'params' => ['tasks' => [
                        ['title' => 'Собрать пакет документов по объекту', 'assignee' => 'responsible', 'days' => 2],
                        ['title' => 'Составить список закупок', 'assignee' => 'responsible', 'days' => 3],
                    ]]]);
                    StageAutomation::create(['organization_id' => $orgId, 'stage_id' => $stage->id, 'action' => 'notify', 'params' => ['text' => 'Договор подписан: {deal}, {amount} ₽']]);
                }
            }
        });
    }

    public static function event(Deal $deal, string $kind, string $text, ?int $userId = null, ?int $fromStageId = null, ?int $toStageId = null): void
    {
        DealEvent::create([
            'organization_id' => $deal->organization_id, 'deal_id' => $deal->id, 'user_id' => $userId, 'kind' => $kind,
            'text' => $text, 'from_stage_id' => $fromStageId, 'to_stage_id' => $toStageId,
        ]);
    }

    /** Первая по порядку воронка организации. */
    public static function firstPipeline(int $orgId): ?Pipeline
    {
        return Pipeline::where('organization_id', $orgId)->orderBy('position')->orderBy('id')->first();
    }

    private static function fill(string $template, Deal $deal, string $clientName): string
    {
        return str_replace(['{deal}', '{amount}', '{client}'], [$deal->title, Fmt::money0((float) $deal->amount), $clientName], $template);
    }

    private static function createProject(Deal $deal): void
    {
        if ($deal->project_id) {
            self::event($deal, 'automation', 'Объект уже создан раньше, второй не создаём');

            return;
        }
        $org = Organization::find($deal->organization_id);
        $limit = config('stroy.plan_limits')[$org->plan]['projects'];
        $count = Project::where('organization_id', $org->id)->where('is_demo', false)->count();
        if ($count >= $limit) {
            self::event($deal, 'automation', "Объект не создан: на вашем пакете можно вести до $limit объектов");

            return;
        }
        $project = Project::create([
            'organization_id' => $org->id, 'name' => mb_substr($deal->title, 0, 200), 'address' => $deal->address,
            'status' => 'in_progress', 'start_date' => today(), 'is_demo' => $deal->is_demo,
        ]);
        Contract::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'counterparty_id' => $deal->counterparty_id,
            'title' => mb_substr('Договор: '.$deal->title, 0, 300), 'amount' => $deal->amount ?: 0, 'status' => 'signed',
        ]);
        $deal->project_id = $project->id;
        $deal->save();
        ProjectAccess::addMembers($project, [$deal->responsible_id]);  // ответственный по сделке видит свой объект
        self::event($deal, 'automation', "Создан объект «{$project->name}» и договор на ".Fmt::money0((float) $deal->amount).' ₽');
    }

    private static function createTasks(Deal $deal, User $actor, array $tasks): void
    {
        $owner = User::where('organization_id', $deal->organization_id)->where('role', 'owner')->orderBy('id')->first();
        $created = 0;
        foreach ($tasks as $item) {
            $who = $item['assignee'];
            if ($who === 'responsible') {
                $assigneeId = $deal->responsible_id ?: $actor->id;
            } elseif ($who === 'owner') {
                $assigneeId = $owner ? $owner->id : $actor->id;
            } else {
                $member = User::find($who);
                $assigneeId = $member && $member->organization_id === $deal->organization_id ? $member->id : null;
                if ($assigneeId === null) {
                    continue;
                }
            }
            Todo::create([
                'organization_id' => $deal->organization_id, 'title' => $item['title'], 'assignee_id' => $assigneeId,
                'due_date' => today()->addDays((int) $item['days']), 'deal_id' => $deal->id, 'project_id' => $deal->project_id,
                'created_by' => $actor->id,
            ]);
            $created++;
        }
        self::event($deal, 'automation', "Поставлено задач: $created");
    }

    private static function notify(Deal $deal, string $text, string $clientName): void
    {
        $team = User::where('organization_id', $deal->organization_id)->pluck('id')->all();
        Telegram::notifyUsers($team, self::fill($text, $deal, $clientName));
        self::event($deal, 'automation', Telegram::enabled() ? 'Команде отправлено уведомление в Telegram' : 'Уведомление команде: Telegram не подключён');
    }

    /** Выполняет действия этапа по порядку. Сбой одного действия не мешает остальным. */
    public static function runAutomations(Deal $deal, Stage $stage, User $actor): void
    {
        $automations = StageAutomation::where('stage_id', $stage->id)->orderBy('id')->get();
        if ($automations->isEmpty()) {
            return;
        }
        $clientName = '';
        if ($deal->counterparty_id) {
            $clientName = Counterparty::find($deal->counterparty_id)?->name ?? '';
        }
        // объект создаём раньше задач, чтобы задачи сразу привязались к нему
        $order = ['create_project' => 0, 'create_tasks' => 1, 'notify' => 2];
        $sorted = $automations->sortBy(fn ($a) => [$order[$a->action] ?? 9, $a->id])->values();
        foreach ($sorted as $auto) {
            try {
                match ($auto->action) {
                    'create_project' => self::createProject($deal),
                    'create_tasks' => self::createTasks($deal, $actor, $auto->params['tasks'] ?? []),
                    'notify' => self::notify($deal, $auto->params['text'] ?? '', $clientName),
                    default => null,
                };
            } catch (\Throwable $e) { // одно сломанное действие не должно ронять перемещение сделки
                self::event($deal, 'automation', "Действие «{$auto->action}» не выполнено: ".class_basename($e));
            }
        }
    }
}
