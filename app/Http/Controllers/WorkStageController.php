<?php

namespace App\Http\Controllers;

use App\Models\Inspection;
use App\Models\Organization;
use App\Models\ProjectDoc;
use App\Models\SiteReport;
use App\Models\SiteTask;
use App\Models\WorkStage;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\RateLimit;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** График работ: этапы объекта, готовность, сроки с прогнозом и ссылка, по которой заказчик видит ход стройки. */
class WorkStageController extends Controller
{
    // Типовой дом: название и длительность в днях, этапы идут один за другим.
    private const HOUSE_TEMPLATE = [
        ['Фундамент', 21],
        ['Стены и перекрытия', 35],
        ['Кровля', 14],
        ['Окна и двери', 7],
        ['Инженерные сети', 21],
        ['Отделка', 28],
    ];

    private function stage($id): WorkStage
    {
        return $this->own(WorkStage::class, $id, 'stage_not_found');
    }

    private static function checkDates(?string $start, ?string $end): void
    {
        if ($start && $end && $end < $start) {
            ApiError::abort(422, 'dates_order');
        }
    }

    /** Зависимость: этап из того же объекта, не сам на себя и без кругов. */
    private static function checkDependency(int $projectId, ?int $stageId, $dependsOnId): void
    {
        if ($dependsOnId === null) {
            return;
        }
        $dep = WorkStage::find($dependsOnId);
        if ($dep === null || $dep->project_id !== $projectId) {
            ApiError::abort(404, 'stage_not_found');
        }
        $seen = [];
        $cursor = $dep;
        while ($cursor !== null && ! isset($seen[$cursor->id])) {
            if ($stageId !== null && $cursor->id === $stageId) {
                ApiError::abort(422, 'depends_cycle');
            }
            $seen[$cursor->id] = true;
            $cursor = $cursor->depends_on_id ? WorkStage::find($cursor->depends_on_id) : null;
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

    private const RULES = [
        'start_date' => 'sometimes|nullable|date_format:Y-m-d',
        'end_date' => 'sometimes|nullable|date_format:Y-m-d',
        'percent' => 'sometimes|integer|min:0|max:100',
        'note' => 'sometimes|string|max:500',
        'depends_on_id' => 'sometimes|nullable|integer',
        'responsible_id' => 'sometimes|nullable|integer',
        'delay_reason' => 'sometimes|string|max:300',
    ];

    public function index($projectId)
    {
        return Site::schedule($this->ownProject($projectId));
    }

    public function store(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['name' => 'required'] + self::RULES);
        $name = self::name($data['name']);
        self::checkDates($data['start_date'] ?? null, $data['end_date'] ?? null);
        self::checkDependency($project->id, null, $data['depends_on_id'] ?? null);
        $this->checkUser($data['responsible_id'] ?? null);
        $position = (WorkStage::where('project_id', $project->id)->max('position') ?? -1) + 1;
        $stage = WorkStage::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name, 'position' => $position,
            'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null, 'percent' => $data['percent'] ?? 0,
            'note' => trim($data['note'] ?? ''), 'depends_on_id' => $data['depends_on_id'] ?? null, 'responsible_id' => $data['responsible_id'] ?? null,
            'delay_reason' => trim($data['delay_reason'] ?? ''),
        ]);
        Activity::log($project->organization_id, $project->id, 'stage', "Добавлен этап «{$stage->name}»", $this->user()->name);

        return response()->json(Site::stageOut($stage->fresh(), Site::context($project->id)), 201);
    }

    /** Типовой график дома. Только если этапов ещё нет. Каждый этап зависит от предыдущего. */
    public function template($projectId)
    {
        $project = $this->ownProject($projectId);
        if (WorkStage::where('project_id', $project->id)->exists()) {
            ApiError::abort(409, 'stages_exist');
        }
        $day = ($project->start_date ?? today())->copy();
        $previous = null;
        foreach (self::HOUSE_TEMPLATE as $position => [$name, $length]) {
            $end = $day->copy()->addDays($length - 1);
            $stage = WorkStage::create([
                'organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name, 'position' => $position,
                'start_date' => $day->toDateString(), 'end_date' => $end->toDateString(), 'depends_on_id' => $previous?->id,
            ]);
            $previous = $stage;
            $day = $end->copy()->addDay();
        }
        Activity::log($project->organization_id, $project->id, 'stage', 'Составлен типовой график работ', $this->user()->name);

        return response()->json(Site::schedule($project), 201);
    }

    /** Сдвигает этот этап и все следующие за ним на несколько дней. */
    public function shift(Request $request, $projectId, $stageId)
    {
        $project = $this->ownProject($projectId);
        $target = $this->stage($stageId);
        if ($target->project_id !== $project->id) {
            ApiError::abort(404, 'stage_not_found');
        }
        $data = $request->validate(['days' => 'required|integer|min:-365|max:365']);
        $days = (int) $data['days'];
        $moving = false;
        foreach (WorkStage::where('project_id', $project->id)->orderBy('position')->orderBy('id')->get() as $stage) {
            if ($stage->id === $target->id) {
                $moving = true;
            }
            if ($moving) {
                if ($stage->start_date) {
                    $stage->start_date = $stage->start_date->copy()->addDays($days);
                }
                if ($stage->end_date) {
                    $stage->end_date = $stage->end_date->copy()->addDays($days);
                }
                $stage->save();
            }
        }
        $sign = $days > 0 ? '+' : '';
        Activity::log($project->organization_id, $project->id, 'stage', "График сдвинут на $sign$days дн., начиная с этапа «{$target->name}»", $this->user()->name);

        return Site::schedule($project);
    }

    public function update(Request $request, $id)
    {
        $stage = $this->stage($id);
        $changes = $request->validate(['name' => 'sometimes|nullable'] + array_map(fn ($r) => str_replace('sometimes|', 'sometimes|nullable|', str_replace('sometimes|nullable|', 'sometimes|', $r)), self::RULES));
        if (array_key_exists('name', $changes) && $changes['name'] !== null) {
            $changes['name'] = self::name($changes['name']);
        }
        $oldPercent = $stage->percent;
        $start = array_key_exists('start_date', $changes) ? $changes['start_date'] : $stage->start_date?->toDateString();
        $end = array_key_exists('end_date', $changes) ? $changes['end_date'] : $stage->end_date?->toDateString();
        self::checkDates($start, $end);
        if (array_key_exists('depends_on_id', $changes)) {
            self::checkDependency($stage->project_id, $stage->id, $changes['depends_on_id']);
        }
        if (array_key_exists('responsible_id', $changes)) {
            $this->checkUser($changes['responsible_id']);
        }
        $nullable = ['start_date', 'end_date', 'depends_on_id', 'responsible_id'];
        foreach ($changes as $key => $value) {
            if (in_array($key, ['name', 'note', 'delay_reason'], true) && $value !== null) {
                $value = trim($value);
            }
            if ($value === null && ! in_array($key, $nullable, true)) {
                continue;
            }
            $stage->{$key} = $value;
        }
        if ($stage->percent < 100 && $stage->accepted_at !== null) {
            $stage->accepted_at = null;  // снова пошла работа: прежняя приёмка не действует
        }
        $stage->save();
        $actor = $this->user()->name;
        if ($stage->percent !== $oldPercent) {
            $done = $stage->percent >= 100 ? ' Этап завершён.' : '';
            Activity::log($stage->organization_id, $stage->project_id, 'stage', "Этап «{$stage->name}»: готовность $oldPercent% → {$stage->percent}%.$done", $actor);
        }
        if (! empty($changes['delay_reason'])) {
            Activity::log($stage->organization_id, $stage->project_id, 'stage', "Причина задержки этапа «{$stage->name}»: {$stage->delay_reason}", $actor);
        }

        return Site::stageOut($stage->fresh(), Site::context($stage->project_id));
    }

    public function destroy($id)
    {
        $stage = $this->stage($id);
        if (Inspection::where('stage_id', $stage->id)->exists()) {
            ApiError::abort(409, 'stage_has_inspections');  // история приёмки не теряется
        }
        SiteReport::where('stage_id', $stage->id)->update(['stage_id' => null]);  // фото остаются в объекте, просто без этапа
        SiteTask::where('stage_id', $stage->id)->update(['stage_id' => null]);
        WorkStage::where('depends_on_id', $stage->id)->update(['depends_on_id' => null]);
        $stage->delete();

        return $this->noContent();
    }

    // --- ссылка для заказчика ---

    public function share($projectId)
    {
        $project = $this->ownProject($projectId);
        if (! $project->customer_token) {
            $project->customer_token = Str::random(24);
            $project->save();
        }
        $path = "/customer.html?t={$project->customer_token}";
        $base = config('stroy.base_url');

        return ['token' => $project->customer_token, 'path' => $path, 'url' => $base ? $base.$path : ''];
    }

    public function unshare($projectId)
    {
        $project = $this->ownProject($projectId);
        if ($project->customer_token) {
            $project->customer_token = null;
            $project->save();
        }

        return $this->noContent();
    }

    /** Что видит заказчик: объект, этапы, сроки и готовность. Денег, договоров и внутренних документов здесь нет. */
    public function publicCustomer(Request $request, string $token)
    {
        RateLimit::take($request, 'customer:ip', 240, 3600);
        $project = Site::projectByToken($token);
        $org = Organization::find($project->organization_id);
        $ctx = Site::context($project->id);

        return [
            'company' => ['name' => $org->name, 'phone' => $org->phone, 'email' => $org->email],
            'project' => ['name' => $project->name, 'address' => $project->address],
            'stages' => $ctx['stages']->map(fn ($s) => Site::stageOut($s, $ctx, false))->all(),
            'overall' => Site::overallPercent($ctx['stages']),
            'schedule' => Site::summary($ctx),
            'tasks' => Site::projectTasks($project->id)->map(fn ($t) => Site::taskOut($t))->all(),
            'activity' => Activity::recent($project->id, 30)->map(fn ($e) => Activity::out($e))->all(),
            'camera' => Site::publicCamera($project),
            'reports' => Site::projectReports($project->id)->map(fn ($r) => Site::reportOut($r, "/public/customer/$token/reports"))->all(),
            'docs' => ProjectDoc::where('project_id', $project->id)->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn ($d) => Site::docOut($d, "/public/customer/$token/docs"))->all(),
        ];
    }
}
