<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\StageAutomation;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\CrmEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PipelineController extends Controller
{
    private function pipeline($id): Pipeline
    {
        return $this->own(Pipeline::class, $id, 'pipeline_not_found');
    }

    private function stage($id): Stage
    {
        return $this->own(Stage::class, $id, 'stage_not_found');
    }

    private static function stageOut(Stage $s, array $automations = []): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'position' => $s->position, 'kind' => $s->kind, 'automations' => $automations];
    }

    private static function autoOut(StageAutomation $a): array
    {
        return ['id' => $a->id, 'action' => $a->action, 'params' => (object) ($a->params ?? [])];
    }

    private function out(iterable $pipelines): array
    {
        $pipelines = collect($pipelines);
        $stages = Stage::whereIn('pipeline_id', $pipelines->pluck('id'))->orderBy('position')->orderBy('id')->get();
        $autos = StageAutomation::whereIn('stage_id', $stages->pluck('id'))->orderBy('id')->get()->groupBy('stage_id');

        return $pipelines->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'position' => $p->position,
            'stages' => $stages->where('pipeline_id', $p->id)->values()->map(
                fn ($s) => self::stageOut($s, ($autos[$s->id] ?? collect())->map(fn ($a) => self::autoOut($a))->all())
            )->all(),
        ])->all();
    }

    /** Название: без пробелов по краям; из одних пробелов не бывает. */
    private function name(Request $request, int $max = 100, bool $required = true): ?string
    {
        $value = $request->input('name');
        if ($value === null && ! $required) {
            return null;
        }
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['name' => 'name']);
        }

        return $value;
    }

    public function index()
    {
        CrmEngine::ensureDefault($this->org());

        return $this->out(Pipeline::org($this->org())->orderBy('position')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $name = $this->name($request);
        CrmEngine::ensureDefault($this->org());
        $org = Organization::find($this->org());
        $count = Pipeline::org($org->id)->count();
        if ($count >= config('stroy.plan_limits')[$org->plan]['pipelines']) {
            ApiError::abort(403, 'pipeline_limit_reached');
        }
        $pipeline = Pipeline::create(['organization_id' => $org->id, 'name' => $name, 'position' => $count]);
        foreach ([['Новая', 'open'], ['В работе', 'open'], ['Успешно', 'won'], ['Отказ', 'lost']] as $position => [$stageName, $kind]) {
            Stage::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'name' => $stageName, 'position' => $position, 'kind' => $kind]);
        }

        return response()->json($this->out([$pipeline])[0], 201);
    }

    public function update(Request $request, $id)
    {
        $pipeline = $this->pipeline($id);
        $pipeline->name = $this->name($request);
        $pipeline->save();

        return $this->out([$pipeline])[0];
    }

    public function destroy($id)
    {
        $pipeline = $this->pipeline($id);
        if (Pipeline::org($this->org())->count() <= 1) {
            ApiError::abort(409, 'last_pipeline');
        }
        if (Deal::where('pipeline_id', $pipeline->id)->exists()) {
            ApiError::abort(409, 'pipeline_has_deals');
        }
        $stageIds = Stage::where('pipeline_id', $pipeline->id)->pluck('id');
        StageAutomation::whereIn('stage_id', $stageIds)->delete();
        Stage::whereIn('id', $stageIds)->delete();
        $pipeline->delete();

        return $this->noContent();
    }

    public function addStage(Request $request, $id)
    {
        $pipeline = $this->pipeline($id);
        $name = $this->name($request);
        $data = $request->validate(['kind' => 'sometimes|in:open,won,lost']);
        $last = Stage::where('pipeline_id', $pipeline->id)->max('position');
        $stage = Stage::create([
            'organization_id' => $this->org(), 'pipeline_id' => $pipeline->id, 'name' => $name,
            'kind' => $data['kind'] ?? 'open', 'position' => ($last ?? -1) + 1,
        ]);

        return response()->json(self::stageOut($stage), 201);
    }

    public function updateStage(Request $request, $id)
    {
        $stage = $this->stage($id);
        $data = $request->validate(['kind' => 'sometimes|nullable|in:open,won,lost']);
        if ($request->has('name') && $request->input('name') !== null) {
            $stage->name = $this->name($request);
        }
        if (! empty($data['kind'])) {
            $stage->kind = $data['kind'];
        }
        $stage->save();

        return self::stageOut($stage);
    }

    public function reorder(Request $request, $id)
    {
        $pipeline = $this->pipeline($id);
        $data = $request->validate(['ids' => 'present|array', 'ids.*' => 'integer']);
        $stages = Stage::where('pipeline_id', $pipeline->id)->get()->keyBy('id');
        $ids = array_map('intval', $data['ids']);
        $sortedGiven = $ids;
        sort($sortedGiven);
        $sortedHave = $stages->keys()->all();
        sort($sortedHave);
        if ($sortedGiven !== $sortedHave) {
            ApiError::abort(422, 'bad_stage_order');
        }
        foreach ($ids as $position => $stageId) {
            $stages[$stageId]->position = $position;
            $stages[$stageId]->save();
        }

        return $this->out([$pipeline])[0];
    }

    public function destroyStage($id)
    {
        $stage = $this->stage($id);
        if (Deal::where('stage_id', $stage->id)->exists()) {
            ApiError::abort(409, 'stage_has_deals');
        }
        if (Stage::where('pipeline_id', $stage->pipeline_id)->count() <= 1) {
            ApiError::abort(409, 'last_stage');
        }
        StageAutomation::where('stage_id', $stage->id)->delete();
        $stage->delete();

        return $this->noContent();
    }

    /** Проверка одного действия автоматики. Возвращает очищенные параметры или null при ошибке. */
    private static function cleanParams(string $action, $params): ?array
    {
        $params = is_array($params) ? $params : [];
        if ($action === 'create_tasks') {
            $tasks = $params['tasks'] ?? null;
            if (! is_array($tasks) || ! array_is_list($tasks) || ! $tasks || count($tasks) > 20) {
                return null;
            }
            $clean = [];
            foreach ($tasks as $item) {
                if (! is_array($item)) {
                    return null;
                }
                $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 300);
                $assignee = $item['assignee'] ?? 'responsible';
                $days = $item['days'] ?? 0;
                if ($title === '') {
                    return null;
                }
                if (! in_array($assignee, ['responsible', 'owner'], true) && ! is_int($assignee)) {
                    return null;
                }
                if (! is_int($days) || $days < 0 || $days > 365) {
                    return null;
                }
                $clean[] = ['title' => $title, 'assignee' => $assignee, 'days' => $days];
            }

            return ['tasks' => $clean];
        }
        if ($action === 'notify') {
            $text = mb_substr(trim((string) ($params['text'] ?? '')), 0, 500);

            return $text === '' ? null : ['text' => $text];
        }

        return [];
    }

    public function setAutomations(Request $request, $id)
    {
        $stage = $this->stage($id);
        $payload = $request->json()->all();
        if (! array_is_list($payload)) {
            throw ValidationException::withMessages(['body' => 'list']);
        }
        $rows = [];
        foreach ($payload as $item) {
            $action = is_array($item) ? ($item['action'] ?? null) : null;
            if (! in_array($action, ['create_project', 'create_tasks', 'notify'], true)) {
                throw ValidationException::withMessages(['action' => 'action']);
            }
            $params = self::cleanParams($action, $item['params'] ?? []);
            if ($params === null) {
                throw ValidationException::withMessages(['params' => 'params']);
            }
            $rows[] = ['action' => $action, 'params' => $params];
        }
        if (count($rows) > 10) {
            ApiError::abort(422, 'too_many_automations');
        }
        StageAutomation::where('stage_id', $stage->id)->delete();
        $created = [];
        foreach ($rows as $row) {
            $created[] = StageAutomation::create(['organization_id' => $this->org(), 'stage_id' => $stage->id] + $row);
        }
        Audit::log($this->user(), 'update', 'stage', $stage->id, "Автоматика этапа «{$stage->name}» изменена", null, ['actions' => array_column($rows, 'action')]);

        return array_map(fn ($a) => self::autoOut($a), $created);
    }
}
