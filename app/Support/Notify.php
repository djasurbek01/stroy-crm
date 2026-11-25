<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/** Уведомления внутри системы. Одна точка входа: запись в «колокольчик» и, если подключён, дубль в Telegram. */
class Notify
{
    /** Сотрудники организации с нужными ролями. */
    public static function people(int $organizationId, array $roles): array
    {
        return User::where('organization_id', $organizationId)->whereIn('role', $roles)->pluck('id')->all();
    }

    /**
     * Создаёт уведомления. dedupe: повторное напоминание с тем же ключом тому же человеку не создаётся.
     * exceptUser: самому автору действия не пишем. Возвращает число созданных.
     */
    public static function push(int $organizationId, iterable $userIds, string $text, ?int $projectId = null, string $link = '', ?string $dedupe = null, ?int $exceptUser = null): int
    {
        $ids = [];
        foreach ($userIds as $uid) {
            if ($uid && $uid !== $exceptUser) {
                $ids[$uid] = true;
            }
        }
        if (! $ids) {
            return 0;
        }
        $valid = User::where('organization_id', $organizationId)->whereIn('id', array_keys($ids))->orderBy('id')->get()
            // уведомление по объекту получают только те, кому объект открыт
            ->filter(fn ($user) => $projectId === null || ProjectAccess::allows($user, $projectId))
            ->pluck('id')->all();
        $created = [];
        foreach ($valid as $uid) {
            if ($dedupe !== null && Notification::where('user_id', $uid)->where('dedupe_key', $dedupe)->exists()) {
                continue;
            }
            Notification::create([
                'organization_id' => $organizationId, 'user_id' => $uid, 'project_id' => $projectId,
                'text' => mb_substr($text, 0, 300), 'link' => mb_substr($link, 0, 200), 'dedupe_key' => $dedupe,
            ]);
            $created[] = $uid;
        }
        if ($created) {
            Telegram::notifyUsers($created, $text);
        }

        return count($created);
    }
}
