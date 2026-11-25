<?php

namespace App\Models\Concerns;

/**
 * Общее для моделей «Стройки»: поля заполняются массивом, записи организации выбираются одним вызовом.
 */
trait StroyModel
{
    public function initializeStroyModel(): void
    {
        $this->guarded = [];
    }

    /** Записи одной организации. */
    public function scopeOrg($query, int $organizationId)
    {
        return $query->where($this->getTable().'.organization_id', $organizationId);
    }
}
