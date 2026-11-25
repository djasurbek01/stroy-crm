<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Закупка по объекту. */
class Purchase extends Model
{
    use Audited, SoftDeletes, StroyModel;

    protected $table = 'purchases';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['purchase', 'item', 'Закупка', true];

    protected $attributes = [
        'quantity' => 1,
        'unit' => '',
        'amount' => 0,
        'status' => 'request',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'quantity' => 'float',
            'organization_id' => 'integer',
            'project_id' => 'integer',
        ];
    }
}
