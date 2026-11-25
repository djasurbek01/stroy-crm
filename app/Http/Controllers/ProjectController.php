<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Perm;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public const STATUSES = 'planning,in_progress,done,warranty,archived';

    public static function out(Project $p): array
    {
        return [
            'name' => $p->name,
            'address' => $p->address,
            'status' => $p->status,
            'start_date' => Fmt::d($p->start_date),
            'end_date' => Fmt::d($p->end_date),
            'contract_value' => (float) $p->contract_value,
            'manager_id' => $p->manager_id,
            'foreman_id' => $p->foreman_id,
            'id' => $p->id,
            'is_demo' => (bool) $p->is_demo,
        ];
    }

    public function index()
    {
        return $this->scopeProjects(Project::org($this->org()), 'id')->orderBy('id')->get()->map(fn ($p) => self::out($p));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:1|max:200',
            'address' => 'sometimes|string|max:300',
            'status' => 'sometimes|in:'.self::STATUSES,
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'contract_value' => 'sometimes|numeric|min:0|max:100000000000',
            'manager_id' => 'sometimes|nullable|integer',
            'foreman_id' => 'sometimes|nullable|integer',
        ]);
        $org = Organization::find($this->org());
        $limit = config('stroy.plan_limits')[$org->plan]['projects'];
        if (Project::org($org->id)->where('is_demo', false)->count() >= $limit) {
            ApiError::abort(403, 'project_limit_reached');
        }
        $this->checkUser($data['manager_id'] ?? null);
        $this->checkUser($data['foreman_id'] ?? null);
        $project = Project::create(['organization_id' => $org->id] + $data);
        if (! ProjectAccess::seesAll($this->user())) {
            ProjectAccess::addMembers($project, [$this->user()->id]);  // кто создал, тот и видит свой объект
        }

        return response()->json(self::out($project->fresh()), 201);
    }

    public function show($id)
    {
        return self::out($this->ownProject($id));
    }

    public function update(Request $request, $id)
    {
        $project = $this->ownProject($id);
        $changes = $request->validate([
            'name' => 'sometimes|nullable|string|min:1|max:200',
            'address' => 'sometimes|nullable|string|max:300',
            'status' => 'sometimes|nullable|in:'.self::STATUSES,
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'contract_value' => 'sometimes|nullable|numeric|min:0|max:100000000000',
            'manager_id' => 'sometimes|nullable|integer',
            'foreman_id' => 'sometimes|nullable|integer',
        ]);
        foreach (['manager_id', 'foreman_id'] as $field) {
            if (array_key_exists($field, $changes)) {
                $this->checkUser($changes[$field]);
            }
        }
        foreach ($changes as $field => $value) {
            if ($value === null && in_array($field, ['contract_value', 'name', 'address', 'status'], true)) {
                continue;  // договорная стоимость и название не бывают пустыми
            }
            $project->{$field} = $value;
        }
        $project->save();

        return self::out($project);
    }

    /**
     * Кто видит объект. via: owner (владелец видит всё), manager, foreman, member.
     *
     * @return array<int, array{id: int, name: string, role: string, role_name: string, via: string}>
     */
    public static function membersOut(Project $project): array
    {
        $out = [];
        foreach (User::whereIn('id', ProjectAccess::people($project))->orderBy('name')->get() as $user) {
            $via = match (true) {
                $user->role === 'owner' => 'owner',
                $user->id === $project->manager_id => 'manager',
                $user->id === $project->foreman_id => 'foreman',
                default => 'member',
            };
            $out[] = ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'role_name' => Perm::ROLE_NAMES[$user->role] ?? $user->role, 'via' => $via];
        }

        return $out;
    }

    public function members($id)
    {
        return self::membersOut($this->ownProject($id));
    }

    /** Задать участников объекта целиком: кого нет в списке, у того объект пропадает (кроме владельца и назначенных РП и прораба). */
    public function setMembers(Request $request, $id)
    {
        $project = $this->ownProject($id);
        $data = $request->validate(['user_ids' => 'present|array|max:200', 'user_ids.*' => 'integer']);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        foreach ($ids as $userId) {
            $this->checkUser($userId);
        }
        ProjectMember::where('project_id', $project->id)->whereNotIn('user_id', $ids ?: [0])->delete();
        ProjectAccess::addMembers($project, $ids);

        return self::membersOut($project);
    }
}
