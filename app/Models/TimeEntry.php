<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Часы человека на объекте за день. */
class TimeEntry extends Model
{
    use StroyModel;

    protected $table = 'time_entries';

    const UPDATED_AT = null;

    protected $attributes = [
        'rate' => 0,
        'note' => '',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'float',
            'rate' => 'float',
        ];
    }
}
