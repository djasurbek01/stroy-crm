<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Действие, которое выполняется, когда сделка попадает на этап. */
class StageAutomation extends Model
{
    use StroyModel;

    protected $table = 'stage_automations';

    public $timestamps = false;

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }
}
