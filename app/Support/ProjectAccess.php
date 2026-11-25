<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Кто какие объекты видит. Владелец видит все объекты компании. Остальные — только те,
 * где они участники (или назначены руководителем проекта либо прорабом), и не попадают в чужие.
 */
class ProjectAccess
{
    public static function seesAll(User $user): bool
    {
        return $user->role === 'owner';
    }

    /**
     * Номера доступных объектов или null, если доступны все.
     *
     * @return array<int, int>|null
     */
    public static function ids(User $user): ?array
    {
        if (self::seesAll($user)) {
            return null;
        }
        $members = ProjectMember::where('user_id', $user->id)->pluck('project_id')->all();
        $assigned = Project::where('organization_id', $user->organization_id)
            ->where(fn ($q) => $q->where('manager_id', $user->id)->orWhere('foreman_id', $user->id))
            ->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($members, $assigned))));
    }

    public static function allows(User $user, int $projectId): bool
    {
        $ids = self::ids($user);

        return $ids === null || in_array($projectId, $ids, true);
    }

    /** Ограничить запрос доступными объектами. withUnassigned: записи без объекта тоже показывать. */
    public static function scope(Builder $query, User $user, string $column = 'project_id', bool $withUnassigned = false): Builder
    {
        $ids = self::ids($user);
        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($column, $ids, $withUnassigned) {
            $q->whereIn($column, $ids ?: [0]);
            if ($withUnassigned) {
                $q->orWhereNull($column);
            }
        });
    }

    /** Добавить участников объекта (повторно не добавляются). */
    public static function addMembers(Project $project, iterable $userIds): void
    {
        foreach ($userIds as $userId) {
            if ($userId) {
                ProjectMember::firstOrCreate(
                    ['project_id' => $project->id, 'user_id' => (int) $userId],
                    ['organization_id' => $project->organization_id],
                );
            }
        }
    }

    /**
     * Люди, которые видят объект: владельцы, участники, руководитель проекта и прораб.
     *
     * @return array<int, int>
     */
    public static function people(Project $project): array
    {
        $ids = ProjectMember::where('project_id', $project->id)->pluck('user_id')->all();
        $ids[] = $project->manager_id;
        $ids[] = $project->foreman_id;
        $owners = User::where('organization_id', $project->organization_id)->where('role', 'owner')->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_filter(array_merge($owners, $ids)))));
    }
}
