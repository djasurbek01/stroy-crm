<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Inspection;
use App\Models\MaterialRequest;
use App\Models\PaymentPlanItem;
use App\Models\Project;
use App\Models\SiteTask;
use App\Models\User;
use App\Models\WorkStage;
use App\Support\Fmt;
use App\Support\Notify;
use App\Support\Telegram;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Ежедневно: правила автоматизации (напоминания и эскалация в «колокольчик») и сводка по срокам документов в Telegram.
 * У каждого уведомления ключ дедупликации, поэтому повторный запуск в тот же день ничего не дублирует.
 */
#[Signature('stroy:reminders')]
#[Description('Напоминания по срокам: задачи, этапы, приёмка, платежи заказчика, заявки на материалы, сводка документов')]
class Reminders extends Command
{
    private array $projects = [];

    public function handle(): int
    {
        $today = today();
        $this->projects = Project::all()->mapWithKeys(fn ($p) => [$p->id => [$p->name, $p->organization_id]])->all();
        $created = $this->tasks($today) + $this->stages($today) + $this->inspections($today) + $this->payments($today) + $this->materials($today);
        $this->info("Создано уведомлений по правилам: $created");
        $digest = $this->digest($today);
        foreach ($digest as $userId => $text) {
            Telegram::enqueue(User::find($userId)->telegram_chat_id, $text);
        }
        $this->info('В очередь поставлено сводок: '.count($digest));

        return self::SUCCESS;
    }

    private function project(int $projectId, int $orgId): array
    {
        return $this->projects[$projectId] ?? ['', $orgId];
    }

    /** Задачи: напоминание перед сроком; при просрочке исполнителю каждый день, руководителям один раз, критичные ещё и владельцу. */
    private function tasks(Carbon $today): int
    {
        $created = 0;
        $tasks = SiteTask::whereNull('done_at')->whereNotNull('due_date')->where('due_date', '<=', $today->copy()->addDay()->toDateString())->get();
        foreach ($tasks as $task) {
            [$project, $org] = $this->project($task->project_id, $task->organization_id);
            $link = "#/project/{$task->project_id}/tasks";
            if ($task->due_date->gte($today)) {
                $created += Notify::push($org, [$task->assignee_id], "Срок задачи скоро: «{$task->title}» ($project) до ".$task->due_date->format('d.m'),
                    $task->project_id, $link, "task-soon:{$task->id}:{$task->due_date->toDateString()}");

                continue;
            }
            $late = (int) $task->due_date->diffInDays($today);
            $text = "Задача просрочена на $late дн.: «{$task->title}» ($project)";
            $created += Notify::push($org, [$task->assignee_id], $text, $task->project_id, $link, "task-late:{$task->id}:{$today->toDateString()}");
            $created += Notify::push($org, Notify::people($org, ['owner', 'pm']), $text, $task->project_id, $link, "task-late-mgr:{$task->id}");
            if ($task->priority === 'critical') {
                $created += Notify::push($org, Notify::people($org, ['owner']), "Критичная задача просрочена: «{$task->title}» ($project)", $task->project_id, $link, "task-critical:{$task->id}");
            }
        }

        return $created;
    }

    /** Этапы графика: просрочен, руководитель и ответственный получают напоминание. */
    private function stages(Carbon $today): int
    {
        $created = 0;
        foreach (WorkStage::where('percent', '<', 100)->whereNotNull('end_date')->where('end_date', '<', $today->toDateString())->get() as $stage) {
            [$project, $org] = $this->project($stage->project_id, $stage->organization_id);
            $late = (int) $stage->end_date->diffInDays($today);
            $who = Notify::people($org, ['owner', 'pm']);
            $who[] = $stage->responsible_id;
            $tail = $stage->delay_reason ? " Причина: {$stage->delay_reason}" : ' Укажите причину задержки.';
            $created += Notify::push($org, $who, "Этап «{$stage->name}» отстаёт на $late дн. ($project).$tail",
                $stage->project_id, "#/project/{$stage->project_id}/schedule", "stage-late:{$stage->id}:{$stage->end_date->toDateString()}");
        }

        return $created;
    }

    /** Приёмка ждёт больше двух дней: напомнить тем, кто её проводит. */
    private function inspections(Carbon $today): int
    {
        $created = 0;
        foreach (Inspection::where('status', 'requested')->get() as $item) {
            if ($item->created_at === null || $item->created_at->copy()->startOfDay()->diffInDays($today) < 2) {
                continue;
            }
            [$project, $org] = $this->project($item->project_id, $item->organization_id);
            $stage = WorkStage::find($item->stage_id);
            $created += Notify::push($org, Notify::people($org, ['owner', 'pm']), 'Приёмка этапа «'.($stage->name ?? '')."» ждёт уже больше двух дней ($project)",
                $item->project_id, "#/project/{$item->project_id}/quality", "insp-wait:{$item->id}");
        }

        return $created;
    }

    /** Платежи заказчика: за три дня до срока напомнить, при просрочке — бухгалтеру, владельцу и менеджеру. */
    private function payments(Carbon $today): int
    {
        $created = 0;
        foreach (PaymentPlanItem::where('due_date', '<=', $today->copy()->addDays(3)->toDateString())->get() as $item) {
            if ($item->received >= $item->amount) {
                continue;
            }
            [$project, $org] = $this->project($item->project_id, $item->organization_id);
            $left = Fmt::money0($item->amount - $item->received);
            $link = "#/project/{$item->project_id}/finance";
            if ($item->due_date->gte($today)) {
                $created += Notify::push($org, Notify::people($org, ['owner', 'accountant']), "Скоро платёж заказчика «{$item->title}» ($project): $left ₽ до ".$item->due_date->format('d.m'),
                    $item->project_id, $link, "pay-soon:{$item->id}:{$item->due_date->toDateString()}");
            } else {
                $late = (int) $item->due_date->diffInDays($today);
                $created += Notify::push($org, Notify::people($org, ['owner', 'accountant', 'manager']),
                    "Платёж заказчика просрочен на $late дн.: «{$item->title}» ($project), не получено $left ₽. Свяжитесь с заказчиком",
                    $item->project_id, $link, "pay-late:{$item->id}:{$item->due_date->toDateString()}");
            }
        }

        return $created;
    }

    /** Заявки на материалы: срок прошёл, а материал не выдан. */
    private function materials(Carbon $today): int
    {
        $created = 0;
        $requests = MaterialRequest::whereIn('status', ['new', 'partial', 'purchase'])->whereNotNull('needed_by')->where('needed_by', '<', $today->toDateString())->get();
        foreach ($requests as $req) {
            [$project, $org] = $this->project($req->project_id, $req->organization_id);
            $created += Notify::push($org, Notify::people($org, ['owner', 'storekeeper', 'buyer', 'pm']),
                'Материал нужен был к '.$req->needed_by->format('d.m').", но не выдан: {$req->item} ($project)", $req->project_id, '#/warehouse', "req-late:{$req->id}");
        }

        return $created;
    }

    /** Сводка по документам: что просрочено и что горит сегодня или завтра (только тем, кто подключил Telegram). */
    private function digest(Carbon $today): array
    {
        $docs = Document::whereNotNull('assignee_id')->whereIn('status', Document::OPEN_STATUSES)->whereNotNull('due_date')
            ->where('due_date', '<=', $today->copy()->addDay()->toDateString())->get();
        $overdue = [];
        $upcoming = [];
        foreach ($docs as $doc) {
            if ($doc->due_date->lt($today)) {
                $overdue[$doc->assignee_id][] = $doc->title;
            } else {
                $upcoming[$doc->assignee_id][] = $doc->title;
            }
        }
        $result = [];
        foreach (array_unique(array_merge(array_keys($overdue), array_keys($upcoming))) as $userId) {
            $user = User::find($userId);
            if ($user === null || ! $user->telegram_chat_id) {
                continue;
            }
            $lines = ['Сводка по документам:'];
            if (! empty($overdue[$userId])) {
                $lines[] = 'Просрочено:';
                foreach ($overdue[$userId] as $t) {
                    $lines[] = "• $t";
                }
            }
            if (! empty($upcoming[$userId])) {
                $lines[] = 'Срок сегодня или завтра:';
                foreach ($upcoming[$userId] as $t) {
                    $lines[] = "• $t";
                }
            }
            $result[$userId] = implode("\n", $lines);
        }

        return $result;
    }
}
