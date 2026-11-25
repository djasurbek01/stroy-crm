<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Perm;
use App\Support\ProjectAccess;
use App\Support\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    private static function out(User $u): array
    {
        return ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'role' => $u->role];
    }

    public function index()
    {
        $query = User::org($this->org())->orderBy('id');
        // подрядчик и ген. директор видят только людей со своих объектов
        if (! Perm::allows($this->user(), 'directory.read')) {
            $ids = [$this->user()->id];
            foreach (Project::whereIn('id', ProjectAccess::ids($this->user()) ?: [0])->get() as $project) {
                $ids = array_merge($ids, ProjectAccess::people($project));
            }
            $query->whereIn('id', $ids);
        }

        return $query->get()->map(fn ($u) => self::out($u));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:200',
            'name' => 'required|string|min:1|max:200',
            'password' => 'required|string|min:8|max:64',
            'role' => 'sometimes|in:manager,foreman,buyer,accountant,pm,storekeeper,director,contractor',
        ]);
        if (AuthController::passwordProblem($data['password'], $data['email'])) {
            ApiError::abort(422, 'weak_password');
        }
        $org = Organization::find($this->org());
        $limit = config('stroy.plan_limits')[$org->plan]['users'];
        if (User::org($org->id)->count() >= $limit) {
            ApiError::abort(403, 'user_limit_reached');
        }
        $email = mb_strtolower($data['email']);
        if (User::where('email', $email)->exists()) {
            ApiError::abort(409, 'email_taken');
        }
        $member = User::create([
            'organization_id' => $org->id, 'email' => $email, 'password_hash' => Hash::make($data['password']),
            'name' => $data['name'], 'role' => $data['role'] ?? 'foreman',
        ]);
        Audit::log($this->user(), 'create', 'user', $member->id, "Добавлен сотрудник {$member->name} ({$member->role})");

        return response()->json(self::out($member), 201);
    }

    /** Владелец задаёт сотруднику новый пароль. Старые входы сотрудника закрываются. */
    public function setPassword(Request $request, $id)
    {
        $data = $request->validate(['password' => 'required|string|min:8|max:64']);
        $member = $this->own(User::class, $id, 'user_not_found');
        if ($member->id === $this->user()->id) {
            ApiError::abort(409, 'use_change_password');
        }
        if (AuthController::passwordProblem($data['password'], $member->email)) {
            ApiError::abort(422, 'weak_password');
        }
        $member->password_hash = Hash::make($data['password']);
        $member->token_version++;
        $member->save();
        Audit::log($this->user(), 'update', 'user', $member->id, "Владелец сменил пароль сотрудника {$member->name}");

        return $this->noContent();
    }

    // --- Telegram ---

    public function telegramStatus()
    {
        return ['enabled' => Telegram::enabled(), 'linked' => $this->user()->telegram_chat_id !== null];
    }

    public function telegramLink()
    {
        if (! Telegram::enabled()) {
            ApiError::abort(503, 'telegram_not_configured');
        }
        $code = Str::random(12);
        $user = $this->user();
        $user->telegram_link_code = $code;
        $user->save();

        return ['link' => 'https://t.me/'.config('stroy.tg_bot')."?start=$code"];
    }

    public function telegramUnlink()
    {
        $user = $this->user();
        $user->telegram_chat_id = null;
        $user->telegram_link_code = null;
        $user->save();

        return $this->noContent();
    }

    // --- права ролей ---

    /** Роли, права которых можно настраивать (владелец всегда со всеми правами). */
    private static function editableRoles(): array
    {
        return array_values(array_filter(Perm::ROLES, fn ($role) => $role !== 'owner'));
    }

    /**
     * Права ролей компании: каталог прав, текущие значения и стандартные значения.
     *
     * @return array{roles: array, groups: array, matrix: array, defaults: array, counts: array}
     */
    private function permissionsOut(): array
    {
        $org = $this->org();
        $matrix = [];
        $defaults = [];
        foreach (self::editableRoles() as $role) {
            foreach (Perm::editableKeys() as $cap) {
                $matrix[$role][$cap] = Perm::canInOrg($org, $role, $cap);
                $defaults[$role][$cap] = Perm::can($role, $cap);
            }
        }
        $counts = User::org($org)->get()->countBy('role')->all();

        return [
            'roles' => array_map(fn ($role) => ['key' => $role, 'name' => Perm::ROLE_NAMES[$role], 'people' => $counts[$role] ?? 0], self::editableRoles()),
            'groups' => array_map(fn ($group) => [
                'title' => $group['title'],
                'caps' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys($group['caps']), $group['caps']),
            ], Perm::editable()),
            'matrix' => $matrix,
            'defaults' => $defaults,
            'counts' => $counts,
        ];
    }

    public function permissions()
    {
        return $this->permissionsOut();
    }

    /** Сохранить права ролей. Хранятся только отличия от стандарта. */
    public function savePermissions(Request $request)
    {
        $data = $request->validate(['matrix' => 'present|array']);
        $org = $this->org();
        $editable = Perm::editableKeys();
        $changed = [];
        foreach (self::editableRoles() as $role) {
            foreach ($editable as $cap) {
                $wanted = $data['matrix'][$role][$cap] ?? null;
                if (! is_bool($wanted)) {
                    continue;  // не прислали: оставляем как есть
                }
                $before = Perm::canInOrg($org, $role, $cap);
                if ($wanted === Perm::can($role, $cap)) {
                    RolePermission::where('organization_id', $org)->where('role', $role)->where('cap', $cap)->delete();
                } else {
                    RolePermission::updateOrCreate(['organization_id' => $org, 'role' => $role, 'cap' => $cap], ['allowed' => $wanted]);
                }
                if ($before !== $wanted) {
                    $changed[] = Perm::ROLE_NAMES[$role].': '.($wanted ? '+' : '−').$cap;
                }
            }
        }
        Perm::forget($org);
        if ($changed) {
            Audit::log($this->user(), 'update', 'settings', null, 'Изменены права ролей: '.count($changed), null, ['changes' => $changed]);
        }

        return $this->permissionsOut();
    }
}
