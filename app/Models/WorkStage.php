<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Этап строительства объекта. */
class WorkStage extends Model
{
    use StroyModel;

    protected $table = 'work_stages';

    const UPDATED_AT = null;

    protected $attributes = [
        'position' => 0,
        'percent' => 0,
        'note' => '',
        'delay_reason' => '',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'percent' => 'integer',
            'accepted_at' => 'datetime',
            'position' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
