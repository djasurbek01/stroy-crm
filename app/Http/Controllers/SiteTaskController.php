<?php

namespace App\Http\Controllers;

use App\Models\InspectionRemark;
use App\Models\Project;
use App\Models\SiteTask;
use App\Models\SiteTaskEvent;
use App\Models\User;
use App\Models\WorkStage;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\ProjectAccess;
use App\Support\RateLimit;
use App\Support\Site;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Общие задачи и предписания по объекту: их видят и подрядчик, и заказчик (по своей ссылке).
 *
 * Порядок работы:
 * - задачу ставит любой участник объекта; исполнитель — тоже участник этого объекта;
 * - ЛПР по задаче — тот, кто её поставил, а также владелец, руководитель проекта и ген. директор объекта;
 * - исполнитель не закрывает чужую задачу сам: «Выполнено» отправляет её ЛПР на проверку;
 * - ЛПР может вернуть задачу на доработку даже после выполнения, обязательно с комментарием;
 * - срок исполнитель не меняет: он просит продление с комментарием, ЛПР согласует или отказывает;
 *   по просроченной задаче исполнитель продолжает работу только после согласования продления.
 */
class SiteTaskController extends Controller
{
    /** Откуда куда можно перевести задачу. */
    private const TRANSITIONS = [
        'new' => ['in_progress', 'waiting_materials', 'in_review', 'done'],
        'in_progress' => ['new', 'waiting_materials', 'in_review', 'done'],
        'waiting_materials' => ['in_progress'],
        'in_review' => ['done', 'rejected'],
        'rejected' => ['in_progress', 'in_review'],
        'done' => ['in_progress', 'rejected'],
    ];

    private const NEEDS_REASON = ['waiting_materials', 'rejected'];

    private const STAFF_ROLES = ['owner', 'foreman', 'pm'];

    private const SHOWN = ['in_progress' => 'в работе', 'waiting_materials' => 'ждёт материалов', 'in_review' => 'на проверке', 'done' => 'выполнена', 'rejected' => 'возвращена на доработку', 'new' => 'новая'];

    /** @return array<int, string> */
    private static function names(int $org): array
    {
        return User::where('organization_id', $org)->pluck('name', 'id')->all();
    }

    private static function link(int $projectId): string
    {
        return "#/project/$projectId/tasks";
    }

    private static function text(mixed $value, int $max, string $field): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => $field]);
        }

        return $value;
    }

    private function task(mixed $id): SiteTask
    {
        return $this->own(SiteTask::class, $id, 'task_not_found');
    }

    /** ЛПР по задаче: поставивший её или руководство объекта (право tasks.review). */
    private static function isDecider(SiteTask $task, User $user): bool
    {
        return ($task->created_by !== null && $task->created_by === $user->id) || Perm::allows($user, 'tasks.review');
    }

    /** Менять содержание задачи может поставивший её и тот, кто управляет задачами объекта. */
    private static function canEdit(SiteTask $task, User $user): bool
    {
        return ($task->created_by !== null && $task->created_by === $user->id) || Perm::allows($user, 'sitetasks.write');
    }

    /** Кому решать по задаче: поставивший и руководство (лишних отсеет доступ к объекту). */
    private static function deciders(SiteTask $task): array
    {
        $ids = Notify::people($task->organization_id, ['owner', 'pm', 'director']);
        $ids[] = $task->created_by;

        return $ids;
    }

    private static function isOverdue(SiteTask $task): bool
    {
        return $task->due_date !== null && $task->due_date->lt(today()) && $task->done_at === null;
    }

    private static function log(SiteTask $task, ?User $user, string $kind, string $text, string $author = ''): void
    {
        SiteTaskEvent::create([
            'organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $user?->id,
            'author' => mb_substr($user?->name ?? $author, 0, 120), 'kind' => $kind, 'text' => mb_substr($text, 0, 1000),
        ]);
    }

    /** Задача для кабинета: с автором, запросом продления и тем, что может сделать текущий человек. */
    private function out(SiteTask $task, ?array $names = null): array
    {
        $names ??= self::names($task->organization_id);
        $user = $this->user();
        $pending = $task->extension_due !== null;

        return Site::taskOut($task, true, $names) + [
            'created_by' => $task->created_by,
            'created_by_name' => $task->created_by ? ($names[$task->created_by] ?? '') : '',
            'extension' => $pending ? [
                'due_date' => Fmt::d($task->extension_due),
                'reason' => $task->extension_reason,
                'requested_by' => $task->extension_by ? ($names[$task->extension_by] ?? '') : '',
                'requested_at' => Fmt::dt($task->extension_at),
            ] : null,
            'can_decide' => self::isDecider($task, $user),
            'can_edit' => self::canEdit($task, $user),
            'needs_extension' => self::isOverdue($task) && $task->assignee_id === $user->id && ! self::isDecider($task, $user),
        ];
    }

    private static function checkStage(Project $project, mixed $stageId): void
    {
        if ($stageId === null) {
            return;
        }
        $stage = WorkStage::find($stageId);
        if ($stage === null || $stage->project_id !== $project->id) {
            ApiError::abort(404, 'stage_not_found');
        }
    }

    /** Исполнитель — только участник этого объекта, иначе он задачу не увидит. */
    private function checkAssignee(Project $project, mixed $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }
        $this->checkUser($assigneeId);
        if (! in_array((int) $assigneeId, ProjectAccess::people($project), true)) {
            ApiError::abort(422, 'assignee_not_member');
        }
    }

    public function index(mixed $projectId)
    {
        $this->ownProject($projectId);
        $names = self::names($this->org());

        return Site::projectTasks((int) $projectId)->map(fn ($t) => $this->out($t, $names));
    }

    /** Карточка задачи с историей. */
    public function show(mixed $id)
    {
        $task = $this->task($id);
        $events = SiteTaskEvent::where('task_id', $task->id)->orderBy('id')->get();

        return $this->out($task) + [
            'events' => $events->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'text' => $e->text, 'author' => $e->author, 'created_at' => Fmt::dt($e->created_at)])->all(),
        ];
    }

    public function store(Request $request, mixed $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate([
            'kind' => 'sometimes|in:task,order',
            'side' => 'sometimes|in:contractor,customer',
            'title' => 'required',
            'note' => 'sometimes|string|max:1000',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'priority' => 'sometimes|in:low,normal,high,critical',
            'assignee_id' => 'sometimes|nullable|integer',
            'requires_review' => 'sometimes|boolean',
            'stage_id' => 'sometimes|nullable|integer',
        ]);
        $title = self::text($data['title'], 300, 'title');
        $user = $this->user();
        $this->checkAssignee($project, $data['assignee_id'] ?? null);
        self::checkStage($project, $data['stage_id'] ?? null);
        $task = SiteTask::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'kind' => $data['kind'] ?? 'task',
            'side' => $data['side'] ?? 'contractor', 'source' => 'staff', 'title' => $title, 'note' => trim($data['note'] ?? ''),
            'author' => $user->name, 'created_by' => $user->id, 'due_date' => $data['due_date'] ?? null, 'start_date' => $data['start_date'] ?? null,
            'priority' => $data['priority'] ?? 'normal', 'assignee_id' => $data['assignee_id'] ?? null,
            'requires_review' => (bool) ($data['requires_review'] ?? false), 'stage_id' => $data['stage_id'] ?? null,
        ]);
        $label = $task->kind === 'order' ? 'Предписание' : 'Задача';
        self::log($task, $user, 'created', "$label поставлена".($task->due_date ? ', срок '.Fmt::ru($task->due_date) : ''));
        Activity::log($project->organization_id, $project->id, 'task', "$label: {$task->title}", $user->name);
        if ($task->assignee_id) {
            Notify::push($project->organization_id, [$task->assignee_id], "Вам назначена задача по объекту «{$project->name}»: {$task->title}",
                $project->id, self::link($project->id), null, $user->id);
        }

        return response()->json($this->out($task->fresh()), 201);
    }

    /** Переводит задачу в новый статус по правилам. Бросает 409, 403 и 422, если нельзя. */
    private function applyStatus(SiteTask $task, User $user, string $new, string $reason): void
    {
        if ($new === $task->status) {
            return;
        }
        $decider = self::isDecider($task, $user);
        // по просроченной задаче исполнитель продолжает работу только после согласованного продления
        if (! $decider && self::isOverdue($task) && $task->assignee_id === $user->id) {
            ApiError::abort(409, 'extension_required');
        }
        // исполнитель не закрывает чужую задачу сам: «Выполнено» уходит ЛПР на проверку
        if ($new === 'done' && ! $decider && $task->status !== 'in_review') {
            $new = 'in_review';
        }
        if (! in_array($new, self::TRANSITIONS[$task->status] ?? [], true)) {
            ApiError::abort(409, 'bad_transition');
        }
        // принять работу или вернуть её (в том числе уже выполненную) может только ЛПР
        if (in_array($task->status, ['in_review', 'done'], true) && in_array($new, ['done', 'rejected', 'in_progress'], true) && ! $decider) {
            ApiError::abort(403, 'not_decider');
        }
        if ($new === 'done' && $task->requires_review && $task->status !== 'in_review' && ! $decider) {
            ApiError::abort(409, 'review_required');
        }
        if (in_array($new, self::NEEDS_REASON, true) && trim($reason) === '') {
            ApiError::abort(422, 'reason_required');
        }
        $project = Project::find($task->project_id);
        $old = $task->status;
        $task->status = $new;
        $task->reason = in_array($new, self::NEEDS_REASON, true) ? trim($reason) : '';
        $task->done_at = $new === 'done' ? now() : null;
        $task->save();
        $comment = trim($reason) !== '' ? '. Комментарий: '.trim($reason) : '';
        self::log($task, $user, 'status', 'Статус: '.self::SHOWN[$old].' → '.self::SHOWN[$new].$comment);
        // причину не пишем: ленту объекта видит заказчик
        Activity::log($task->organization_id, $task->project_id, 'task', "Задача «{$task->title}»: ".self::SHOWN[$new], $user->name);
        $link = self::link($task->project_id);
        $org = $task->organization_id;
        if ($new === 'in_review') {
            Notify::push($org, self::deciders($task), "Задача ждёт проверки по объекту «{$project->name}»: {$task->title}", $project->id, $link, null, $user->id);
        } elseif ($new === 'rejected') {
            $from = $old === 'done' ? 'Выполненная задача возвращена' : 'Задача возвращена';
            Notify::push($org, [$task->assignee_id], "$from на доработку («{$project->name}»): {$task->title}. Причина: {$task->reason}", $project->id, $link, null, $user->id);
        } elseif ($new === 'waiting_materials') {
            Notify::push($org, Notify::people($org, ['owner', 'pm', 'buyer']), "Работа стоит, нужны материалы («{$project->name}»): {$task->title}. {$task->reason}", $project->id, $link, null, $user->id);
        } elseif ($new === 'done' && $task->assignee_id && $task->assignee_id !== $user->id) {
            Notify::push($org, [$task->assignee_id], "Задача принята («{$project->name}»): {$task->title}", $project->id, $link);
        } elseif ($new === 'in_progress' && $old === 'done') {
            Notify::push($org, [$task->assignee_id], "Задача снова в работе («{$project->name}»): {$task->title}", $project->id, $link, null, $user->id);
        }
    }

    public function update(Request $request, mixed $id)
    {
        $task = $this->task($id);
        $user = $this->user();
        if (! self::canEdit($task, $user)) {
            ApiError::abort(403, 'forbidden');
        }
        $project = Project::find($task->project_id);
        $changes = $request->validate([
            'kind' => 'sometimes|nullable|in:task,order',
            'side' => 'sometimes|nullable|in:contractor,customer',
            'title' => 'sometimes|nullable',
            'note' => 'sometimes|nullable|string|max:1000',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'priority' => 'sometimes|nullable|in:low,normal,high,critical',
            'assignee_id' => 'sometimes|nullable|integer',
            'requires_review' => 'sometimes|nullable|boolean',
            'stage_id' => 'sometimes|nullable|integer',
            'done' => 'sometimes|nullable|boolean',
        ]);
        if (array_key_exists('title', $changes) && $changes['title'] !== null) {
            $changes['title'] = self::text($changes['title'], 300, 'title');
        }
        $done = array_key_exists('done', $changes) ? $changes['done'] : null;
        unset($changes['done']);
        $oldDue = $task->due_date?->toDateString();
        $dueChanged = array_key_exists('due_date', $changes) && $changes['due_date'] !== $oldDue;
        // срок продлевает только ЛПР: исполнитель просит продление
        if ($dueChanged && ! self::isDecider($task, $user)) {
            ApiError::abort(403, 'extension_required');
        }
        if (array_key_exists('assignee_id', $changes)) {
            $this->checkAssignee($project, $changes['assignee_id']);
        }
        if (array_key_exists('stage_id', $changes)) {
            self::checkStage($project, $changes['stage_id']);
        }
        $oldAssignee = $task->assignee_id;
        $nullable = ['due_date', 'start_date', 'assignee_id', 'stage_id'];
        foreach ($changes as $key => $value) {
            if ($value === null && ! in_array($key, $nullable, true)) {
                continue;
            }
            $task->{$key} = is_string($value) ? trim($value) : $value;
        }
        $task->save();
        if ($dueChanged) {
            self::log($task, $user, 'due', 'Срок изменён: '.($oldDue ? Fmt::ru(Carbon::parse($oldDue)) : 'без срока').' → '.($task->due_date ? Fmt::ru($task->due_date) : 'без срока'));
        }
        if ($task->assignee_id && $task->assignee_id !== $oldAssignee) {
            self::log($task, $user, 'assignee', 'Исполнитель: '.(self::names($task->organization_id)[$task->assignee_id] ?? ''));
            Notify::push($task->organization_id, [$task->assignee_id], "Вам назначена задача по объекту «{$project->name}»: {$task->title}",
                $project->id, self::link($project->id), null, $user->id);
        }
        if ($done === true) {
            $this->applyStatus($task, $user, 'done', '');
        } elseif ($done === false && $task->status === 'done') {
            $this->applyStatus($task, $user, 'in_progress', '');
        }

        return $this->out($task);
    }

    /** Ход выполнения. Менять может исполнитель, поставивший и тот, кто управляет задачами объекта. */
    public function status(Request $request, mixed $id)
    {
        $data = $request->validate([
            'status' => 'required|in:new,in_progress,waiting_materials,in_review,done,rejected',
            'reason' => 'sometimes|string|max:300',
        ]);
        $task = $this->task($id);
        $user = $this->user();
        if (! ($task->assignee_id === $user->id || self::canEdit($task, $user) || self::isDecider($task, $user))) {
            ApiError::abort(403, 'not_your_task');
        }
        $this->applyStatus($task, $user, $data['status'], $data['reason'] ?? '');

        return $this->out($task);
    }

    /** Комментарий в задаче: видят исполнитель, поставивший и руководство объекта. */
    public function comment(Request $request, mixed $id)
    {
        $task = $this->task($id);
        $text = self::text($request->input('text'), 1000, 'text');
        $user = $this->user();
        self::log($task, $user, 'comment', $text);
        $project = Project::find($task->project_id);
        Notify::push($task->organization_id, [$task->assignee_id, $task->created_by], "{$user->name} написал(а) в задаче «{$task->title}» («{$project->name}»): ".mb_substr($text, 0, 120),
            $project->id, self::link($project->id), null, $user->id);

        return response()->json($this->show($task->id), 201);
    }

    /** Исполнитель просит продлить срок: новая дата и обязательный комментарий. Решает ЛПР. */
    public function requestExtension(Request $request, mixed $id)
    {
        $data = $request->validate(['due_date' => 'required|date_format:Y-m-d', 'comment' => 'required']);
        $comment = self::text($data['comment'], 500, 'comment');
        $task = $this->task($id);
        $user = $this->user();
        if ($task->assignee_id !== $user->id) {
            ApiError::abort(403, 'not_your_task');
        }
        if ($task->done_at !== null) {
            ApiError::abort(409, 'task_closed');
        }
        $newDue = Carbon::createFromFormat('!Y-m-d', $data['due_date']);
        if ($newDue->lt(today()) || ($task->due_date && $newDue->lte($task->due_date))) {
            ApiError::abort(422, 'extension_date_invalid');
        }
        $task->fill(['extension_due' => $newDue->toDateString(), 'extension_reason' => $comment, 'extension_by' => $user->id, 'extension_at' => now()])->save();
        self::log($task, $user, 'extension', 'Просит продлить срок до '.Fmt::ru($newDue).". Комментарий: $comment");
        $project = Project::find($task->project_id);
        Notify::push($task->organization_id, self::deciders($task), "{$user->name} просит продлить срок задачи «{$task->title}» до ".Fmt::ru($newDue)." («{$project->name}»): $comment",
            $project->id, self::link($project->id), null, $user->id);

        return $this->out($task->fresh());
    }

    /** ЛПР согласует продление (новый срок вступает в силу) или отказывает. Отказ — с комментарием. */
    public function decideExtension(Request $request, mixed $id)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'comment' => 'sometimes|nullable|string|max:500']);
        $task = $this->task($id);
        $user = $this->user();
        if (! self::isDecider($task, $user)) {
            ApiError::abort(403, 'not_decider');
        }
        if ($task->extension_due === null) {
            ApiError::abort(409, 'no_extension_request');
        }
        $comment = trim($data['comment'] ?? '');
        if ($data['decision'] === 'rejected' && $comment === '') {
            ApiError::abort(422, 'comment_required');
        }
        $approved = $data['decision'] === 'approved';
        $newDue = $task->extension_due;
        if ($approved) {
            $task->due_date = $newDue;
        }
        $task->fill(['extension_due' => null, 'extension_reason' => '', 'extension_by' => null, 'extension_at' => null])->save();
        $note = $comment !== '' ? ". Комментарий: $comment" : '';
        self::log($task, $user, 'extension', ($approved ? 'Продление согласовано, новый срок '.Fmt::ru($newDue) : 'В продлении отказано').$note);
        $project = Project::find($task->project_id);
        Notify::push($task->organization_id, [$task->assignee_id],
            ($approved ? 'Продление согласовано до '.Fmt::ru($newDue) : 'В продлении отказано')." по задаче «{$task->title}» («{$project->name}»)$note",
            $project->id, self::link($project->id), null, $user->id);

        return $this->out($task->fresh());
    }

    public function destroy(mixed $id)
    {
        $task = $this->task($id);
        if (! self::canEdit($task, $this->user())) {
            ApiError::abort(403, 'forbidden');
        }
        InspectionRemark::where('task_id', $task->id)->update(['task_id' => null]);  // замечание остаётся в истории проверки
        SiteTaskEvent::where('task_id', $task->id)->delete();
        $task->delete();

        return $this->noContent();
    }

    // --- со стороны заказчика, по ссылке ---

    /** Заказчик пишет подрядчику просьбу или вопрос: обычная задача для подрядчика с пометкой «от заказчика». */
    public function customerAdd(Request $request, string $token)
    {
        $data = $request->validate(['title' => 'required', 'note' => 'sometimes|string|max:1000', 'author' => 'sometimes|string|max:120']);
        $title = self::text($data['title'], 300, 'title');
        RateLimit::take($request, 'customer:task', 20, 3600);
        $project = Site::projectByToken($token);
        $open = SiteTask::where('project_id', $project->id)->where('source', 'customer')->whereNull('done_at')->count();
        if ($open >= 50) {
            ApiError::abort(429, 'too_many_open');
        }
        $who = trim($data['author'] ?? '') ?: 'Заказчик';
        $task = SiteTask::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'kind' => 'task', 'side' => 'contractor',
            'source' => 'customer', 'title' => $title, 'note' => trim($data['note'] ?? ''), 'author' => $who,
        ]);
        self::log($task, null, 'created', 'Просьба от заказчика', $who);
        Activity::log($project->organization_id, $project->id, 'task', "Просьба от заказчика: {$task->title}", $who);
        Notify::push($project->organization_id, Notify::people($project->organization_id, self::STAFF_ROLES), "$who (заказчик) написал по объекту «{$project->name}»: {$task->title}",
            $project->id, self::link($project->id));

        return response()->json(Site::taskOut($task->fresh()), 201);
    }

    /** Заказчик отмечает выполненной только ту задачу, которую должен сделать сам. */
    public function customerDone(Request $request, string $token, mixed $taskId)
    {
        RateLimit::take($request, 'customer:done', 60, 3600);
        $project = Site::projectByToken($token);
        $task = is_numeric($taskId) ? SiteTask::find((int) $taskId) : null;
        if ($task === null || $task->project_id !== $project->id) {
            ApiError::abort(404, 'task_not_found');
        }
        if ($task->side !== 'customer') {
            ApiError::abort(403, 'not_customer_task');
        }
        if ($task->done_at === null) {
            $task->done_at = now();
            $task->status = 'done';
            $task->save();
            self::log($task, null, 'status', 'Заказчик отметил выполненной', 'Заказчик');
            Activity::log($task->organization_id, $task->project_id, 'task', "Заказчик выполнил: {$task->title}", 'Заказчик');
            Notify::push($project->organization_id, Notify::people($project->organization_id, self::STAFF_ROLES), "Заказчик выполнил задачу по объекту «{$project->name}»: {$task->title}",
                $project->id, self::link($project->id));
        }

        return Site::taskOut($task);
    }
}
