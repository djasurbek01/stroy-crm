<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Support\ApiError;
use App\Support\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    protected function user(): User
    {
        return request()->user();
    }

    protected function org(): int
    {
        return $this->user()->organization_id;
    }

    /**
     * Запись своей организации или 404 с кодом. Запись объекта (у неё есть project_id)
     * отдаётся, только если этот объект доступен человеку. Сделки и личные дела к объекту лишь привязаны.
     */
    protected function own(string $class, $id, string $code)
    {
        $item = is_numeric($id) ? $class::find((int) $id) : null;
        if ($item === null || (int) $item->organization_id !== $this->org()) {
            ApiError::abort(404, $code);
        }
        $projectId = $item instanceof Project ? $item->id : (in_array($class, [Deal::class, Todo::class], true) ? null : $item->project_id);
        if ($projectId !== null && ! ProjectAccess::allows($this->user(), (int) $projectId)) {
            ApiError::abort(404, $code);
        }

        return $item;
    }

    /** Ограничить список доступными человеку объектами. */
    protected function scopeProjects($query, string $column = 'project_id', bool $withUnassigned = false)
    {
        return ProjectAccess::scope($query, $this->user(), $column, $withUnassigned);
    }

    protected function ownProject($id): Project
    {
        return $this->own(Project::class, $id, 'project_not_found');
    }

    protected function checkCounterparty($id): void
    {
        if ($id !== null) {
            $this->own(Counterparty::class, $id, 'counterparty_not_found');
        }
    }

    protected function checkUser($id): void
    {
        if ($id !== null) {
            $this->own(User::class, $id, 'assignee_not_found');
        }
    }

    protected function checkContract($id): void
    {
        if ($id !== null) {
            $this->own(Contract::class, $id, 'contract_not_found');
        }
    }

    /** Логический параметр адреса: true/false, 1/0, yes/no, on/off. */
    protected function qBool(Request $request, string $name, ?bool $default = false): ?bool
    {
        if (! $request->query->has($name)) {
            return $default;
        }
        $value = strtolower((string) $request->query($name));
        if (in_array($value, ['1', 'true', 'yes', 'on', 't', 'y'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'f', 'n'], true)) {
            return false;
        }
        throw ValidationException::withMessages([$name => 'bool']);
    }

    /** Целый параметр адреса или null. */
    protected function qInt(Request $request, string $name, ?int $default = null): ?int
    {
        $value = $request->query($name);
        if ($value === null || $value === '') {
            return $default;
        }
        if (! preg_match('/^-?\d+$/', (string) $value)) {
            throw ValidationException::withMessages([$name => 'int']);
        }

        return (int) $value;
    }

    /** Постраничный список: limit (1..1000, по умолчанию 500) и offset. Общее число в заголовке X-Total-Count. */
    protected function paged(Request $request, $query, callable $map): JsonResponse
    {
        $limit = $this->qInt($request, 'limit', 500);
        $offset = $this->qInt($request, 'offset', 0);
        if ($limit < 1 || $limit > 1000 || $offset < 0) {
            throw ValidationException::withMessages(['limit' => 'range']);
        }
        $total = (clone $query)->reorder()->count();
        $items = $query->limit($limit)->offset($offset)->get();
        $data = $map($items);

        return response()->json($data)->header('X-Total-Count', (string) $total);
    }

    /** Поиск без учёта регистра по нескольким колонкам. */
    protected function whereContains($query, array $columns, ?string $needle)
    {
        $needle = trim((string) $needle);
        if ($needle === '') {
            return $query;
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle)).'%';

        return $query->where(function ($q) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER(COALESCE('.$column.", '')) LIKE ?", [$pattern]);
            }
        });
    }

    /** Пустое тело ответа 204. */
    protected function noContent()
    {
        return response()->noContent();
    }
}
