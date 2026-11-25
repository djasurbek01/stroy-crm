<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DealItem;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Todo;
use App\Support\ApiError;
use App\Support\CrmEngine;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public static function stageKinds(int $org)
    {
        return Stage::org($org)->get()->keyBy('id');
    }

    public static function out(Deal $deal, $stages): array
    {
        $stage = $stages[$deal->stage_id] ?? null;

        return [
            'id' => $deal->id,
            'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id,
            'title' => $deal->title,
            'counterparty_id' => $deal->counterparty_id,
            'amount' => (float) $deal->amount,
            'responsible_id' => $deal->responsible_id,
            'due_date' => Fmt::d($deal->due_date),
            'address' => $deal->address,
            'project_id' => $deal->project_id,
            'is_demo' => (bool) $deal->is_demo,
            'unsorted' => (bool) $deal->unsorted,
            'source' => $deal->source,
            'state' => $stage ? $stage->kind : 'open',
            'created_at' => Fmt::dt($deal->created_at),
        ];
    }

    public static function todoOut(Todo $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title, 'assignee_id' => $t->assignee_id, 'due_date' => Fmt::d($t->due_date),
            'done' => (bool) $t->done, 'deal_id' => $t->deal_id, 'project_id' => $t->project_id,
            'overdue' => ! $t->done && $t->due_date !== null && $t->due_date->lt(today()),
        ];
    }

    /** Карточка сделки: поля, лента, задачи, расчёт стоимости и состояние КП. */
    public static function detail(Deal $deal): array
    {
        $stages = self::stageKinds($deal->organization_id);
        $events = DealEvent::where('deal_id', $deal->id)->orderByDesc('id')->get();
        $todos = Todo::where('deal_id', $deal->id)->orderBy('done')->orderBy('id')->get();
        $items = DealItem::where('deal_id', $deal->id)->orderBy('position')->orderBy('id')->get();

        return self::out($deal, $stages) + [
            'events' => $events->map(fn ($e) => ['id' => $e->id, 'user_id' => $e->user_id, 'kind' => $e->kind, 'text' => $e->text, 'created_at' => Fmt::dt($e->created_at)])->all(),
            'todos' => $todos->map(fn ($t) => self::todoOut($t))->all(),
            'items' => $items->map(fn ($i) => ['title' => $i->title, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'price' => (float) $i->price, 'total' => (float) ($i->quantity * $i->price)])->all(),
            'offer_note' => $deal->offer_note ?? '',
            'offer_valid_until' => Fmt::d($deal->offer_valid_until),
            'offer_shared' => (bool) $deal->offer_token,
            'offer_accepted_at' => Fmt::dt($deal->offer_accepted_at),
        ];
    }

    private function find($id): Deal
    {
        return $this->own(Deal::class, $id, 'deal_not_found');
    }

    /** Текст без пробелов по краям; из одних пробелов не бывает. */
    private static function text($value, int $max, string $field): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => $field]);
        }

        return $value;
    }

    /** Клиент сделки: выбранный контакт или новый по имени и телефону. */
    private function customer(array $data): ?int
    {
        if (($data['counterparty_id'] ?? null) !== null) {
            $this->checkCounterparty($data['counterparty_id']);

            return (int) $data['counterparty_id'];
        }
        $name = trim($data['customer_name'] ?? '');
        if ($name === '') {
            return null;
        }
        $found = Counterparty::org($this->org())->where('name', $name)->first();
        if ($found) {
            return $found->id;
        }

        return Counterparty::create([
            'organization_id' => $this->org(), 'name' => $name, 'kind' => 'customer', 'phone' => trim($data['customer_phone'] ?? ''),
        ])->id;
    }

    public function index(Request $request)
    {
        $query = Deal::org($this->org())->where('unsorted', $this->qBool($request, 'unsorted', false));
        if (($pipelineId = $this->qInt($request, 'pipeline_id')) !== null) {
            $query->where('pipeline_id', $pipelineId);
        }
        if (($responsible = $this->qInt($request, 'responsible_id')) !== null) {
            $query->where('responsible_id', $responsible);
        }
        $this->whereContains($query, ['title', 'address'], $request->query('q'));
        $stages = self::stageKinds($this->org());

        return $this->paged($request, $query->orderBy('id'), fn ($deals) => $deals->map(fn ($d) => self::out($d, $stages)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pipeline_id' => 'required|integer',
            'stage_id' => 'sometimes|nullable|integer',
            'title' => 'required',
            'counterparty_id' => 'sometimes|nullable|integer',
            'customer_name' => 'sometimes|nullable|string|max:200',
            'customer_phone' => 'sometimes|nullable|string|max:50',
            'amount' => 'sometimes|numeric|min:0',
            'responsible_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'address' => 'sometimes|string|max:300',
        ]);
        $title = self::text($data['title'], 300, 'title');
        $user = $this->user();
        CrmEngine::ensureDefault($user->organization_id);
        $pipeline = Pipeline::find($data['pipeline_id']);
        if ($pipeline === null || $pipeline->organization_id !== $user->organization_id) {
            ApiError::abort(404, 'pipeline_not_found');
        }
        if (($data['stage_id'] ?? null) !== null) {
            $stage = Stage::find($data['stage_id']);
            if ($stage === null || $stage->pipeline_id !== $pipeline->id) {
                ApiError::abort(404, 'stage_not_found');
            }
        } else {
            $stage = Stage::where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->first();
            if ($stage === null) {
                ApiError::abort(409, 'pipeline_has_no_stages');
            }
        }
        $this->checkUser($data['responsible_id'] ?? null);
        $deal = Deal::create([
            'organization_id' => $user->organization_id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => $title,
            'counterparty_id' => $this->customer($data),
            'amount' => (float) ($data['amount'] ?? 0),
            'responsible_id' => ($data['responsible_id'] ?? null) ?: $user->id,
            'due_date' => $data['due_date'] ?? null,
            'address' => trim($data['address'] ?? ''),
            'closed_at' => $stage->kind !== 'open' ? now() : null,
        ]);
        CrmEngine::event($deal, 'created', "Сделка создана на этапе «{$stage->name}»", $user->id, null, $stage->id);
        CrmEngine::runAutomations($deal, $stage, $user);

        return response()->json(self::detail($deal->fresh()), 201);
    }

    public function show($id)
    {
        return self::detail($this->find($id));
    }

    public function update(Request $request, $id)
    {
        $deal = $this->find($id);
        $changes = $request->validate([
            'title' => 'sometimes|nullable',
            'counterparty_id' => 'sometimes|nullable|integer',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'responsible_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'address' => 'sometimes|nullable|string|max:300',
        ]);
        if (array_key_exists('title', $changes) && $changes['title'] !== null) {
            $changes['title'] = self::text($changes['title'], 300, 'title');
        }
        if (array_key_exists('counterparty_id', $changes)) {
            $this->checkCounterparty($changes['counterparty_id']);
        }
        if (array_key_exists('responsible_id', $changes)) {
            $this->checkUser($changes['responsible_id']);
        }
        foreach ($changes as $field => $value) {
            if ($value === null && in_array($field, ['title', 'address', 'amount'], true)) {
                continue;
            }
            $deal->{$field} = $value;
        }
        $deal->save();

        return self::detail($deal);
    }

    public function move(Request $request, $id)
    {
        $data = $request->validate(['stage_id' => 'required|integer']);
        $deal = $this->find($id);
        $user = $this->user();
        $target = Stage::find($data['stage_id']);
        if ($target === null || $target->organization_id !== $user->organization_id || $target->pipeline_id !== $deal->pipeline_id) {
            ApiError::abort(404, 'stage_not_found');
        }
        if ($target->id !== $deal->stage_id) {
            $old = Stage::find($deal->stage_id);
            $deal->stage_id = $target->id;
            $deal->closed_at = $target->kind !== 'open' ? now() : null;
            $deal->save();
            CrmEngine::event($deal, 'moved', 'Этап: «'.($old->name ?? '—')."» → «{$target->name}»", $user->id, $old?->id, $target->id);
            CrmEngine::runAutomations($deal, $target, $user);
        }

        return self::detail($deal->fresh());
    }

    public function note(Request $request, $id)
    {
        $deal = $this->find($id);
        $text = self::text($request->input('text'), 2000, 'text');
        CrmEngine::event($deal, 'note', $text, $this->user()->id);

        return response()->json(self::detail($deal), 201);
    }

    // --- личные задачи ---

    public function todos(Request $request)
    {
        $user = $this->user();
        $query = Todo::org($user->organization_id);
        if ($this->qBool($request, 'mine', false)) {
            $query->where('assignee_id', $user->id);
        }
        if (($done = $this->qBool($request, 'done', null)) !== null) {
            $query->where('done', $done);
        }
        if (($dealId = $this->qInt($request, 'deal_id')) !== null) {
            $query->where('deal_id', $dealId);
        }
        $query->orderBy('done')->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id');

        return $this->paged($request, $query, fn ($items) => $items->map(fn ($t) => self::todoOut($t)));
    }

    public function storeTodo(Request $request)
    {
        $data = $request->validate([
            'title' => 'required',
            'assignee_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'deal_id' => 'sometimes|nullable|integer',
            'project_id' => 'sometimes|nullable|integer',
        ]);
        $title = self::text($data['title'], 300, 'title');
        $this->checkUser($data['assignee_id'] ?? null);
        if (($data['deal_id'] ?? null) !== null) {
            $this->find($data['deal_id']);
        }
        $todo = Todo::create([
            'organization_id' => $this->org(), 'title' => $title, 'assignee_id' => ($data['assignee_id'] ?? null) ?: $this->user()->id,
            'due_date' => $data['due_date'] ?? null, 'deal_id' => $data['deal_id'] ?? null, 'project_id' => $data['project_id'] ?? null,
            'created_by' => $this->user()->id,
        ]);

        return response()->json(self::todoOut($todo->fresh()), 201);
    }

    public function updateTodo(Request $request, $id)
    {
        $todo = $this->own(Todo::class, $id, 'todo_not_found');
        $changes = $request->validate([
            'title' => 'sometimes|nullable',
            'assignee_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'done' => 'sometimes|nullable|boolean',
        ]);
        if (array_key_exists('title', $changes) && $changes['title'] !== null) {
            $changes['title'] = self::text($changes['title'], 300, 'title');
        }
        if (array_key_exists('assignee_id', $changes)) {
            $this->checkUser($changes['assignee_id']);
        }
        foreach ($changes as $field => $value) {
            if ($value === null && in_array($field, ['title', 'done'], true)) {
                continue;
            }
            $todo->{$field} = $value;
        }
        $todo->save();

        return self::todoOut($todo);
    }
}
