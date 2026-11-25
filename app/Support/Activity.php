<?php

namespace App\Support;

use App\Models\ActivityEvent;

/** Лента событий объекта. Её видит и заказчик, поэтому денег и внутренних причин здесь нет. */
class Activity
{
    public static function log(int $organizationId, int $projectId, string $kind, string $text, string $actor = ''): void
    {
        ActivityEvent::create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'kind' => $kind,
            'text' => mb_substr($text, 0, 300), 'actor' => mb_substr($actor, 0, 120),
        ]);
    }

    public static function out(ActivityEvent $event): array
    {
        return [
            'id' => $event->id,
            'kind' => $event->kind,
            'text' => $event->text,
            'actor' => $event->actor,
            'created_at' => Fmt::dt($event->created_at),
        ];
    }

    /** Последние события объекта. */
    public static function recent(int $projectId, int $limit = 50)
    {
        return ActivityEvent::where('project_id', $projectId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }
}
