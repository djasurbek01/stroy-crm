<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Договор по объекту. */
class Contract extends Model
{
    use Audited, SoftDeletes, StroyModel;

    protected $table = 'contracts';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['contract', 'title', 'Договор', true];

    protected $attributes = [
        'amount' => 0,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_date' => 'date',
            'organization_id' => 'integer',
            'project_id' => 'integer',
        ];
    }
}
