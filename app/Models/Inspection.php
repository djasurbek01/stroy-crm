<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Проверка (приёмка) этапа. */
class Inspection extends Model
{
    use StroyModel;

    protected $table = 'inspections';

    const UPDATED_AT = null;

    protected $attributes = [
        'status' => 'requested',
        'requested_by' => '',
        'inspector' => '',
        'notes' => '',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'inspected_at' => 'datetime',
            'fix_by' => 'date',
            'stage_id' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
