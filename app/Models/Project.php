<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Объект строительства. */
class Project extends Model
{
    use Audited, StroyModel;

    protected $table = 'projects';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['project', 'name', 'Объект', true];

    protected $attributes = [
        'address' => '',
        'status' => 'planning',
        'is_demo' => false,
        'camera_label' => '',
        'camera_public' => false,
        'contract_value' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'camera_public' => 'boolean',
            'contract_value' => 'float',
            'start_date' => 'date',
            'end_date' => 'date',
            'organization_id' => 'integer',
        ];
    }
}
