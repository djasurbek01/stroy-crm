<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/**
 * Журнал пишется автоматически: создание, изменение, удаление и восстановление записи.
 * Нет действия, нет и записи, и наоборот. Кто это сделал, берётся из текущего входа.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function ($model) {
            if (static::$audit[3]) {
                Audit::record($model, 'create', Audit::named($model).' создан(а)');
            }
        });

        static::updated(function ($model) {
            $diff = Audit::changesOf($model);
            if (! $diff) {
                return;
            }
            $names = implode(', ', array_map(fn ($key) => Audit::FIELD_NAMES[$key] ?? $key, array_keys($diff)));
            Audit::record($model, 'update', Audit::named($model).": изменено: $names", ['changes' => $diff]);
        });

        static::deleted(function ($model) {
            $soft = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
            Audit::record($model, 'delete', Audit::named($model).($soft ? ' удалён(а) в корзину' : ' удалён(а) навсегда'));
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                Audit::record($model, 'restore', Audit::named($model).' восстановлен(а) из корзины');
            });
        }
    }
}
