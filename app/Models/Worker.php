<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Человек, которому платят за часы работы. */
class Worker extends Model
{
    use Audited, StroyModel;

    protected $table = 'workers';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['worker', 'name', 'Сотрудник на объектах', true];

    protected $attributes = [
        'position' => '',
        'hourly_rate' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'float',
            'active' => 'boolean',
            'organization_id' => 'integer',
        ];
    }
}
