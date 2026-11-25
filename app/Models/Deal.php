<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Сделка в воронке. */
class Deal extends Model
{
    use Audited, SoftDeletes, StroyModel;

    protected $table = 'deals';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['deal', 'title', 'Сделка', true];

    protected $attributes = [
        'amount' => 0,
        'address' => '',
        'is_demo' => false,
        'unsorted' => false,
        'source' => 'manual',
        'offer_note' => '',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_date' => 'date',
            'is_demo' => 'boolean',
            'unsorted' => 'boolean',
            'offer_valid_until' => 'date',
            'offer_accepted_at' => 'datetime',
            'closed_at' => 'datetime',
            'organization_id' => 'integer',
            'pipeline_id' => 'integer',
            'stage_id' => 'integer',
        ];
    }
}
