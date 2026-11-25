<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Этап воронки: open, won или lost. */
class Stage extends Model
{
    use Audited, StroyModel;

    protected $table = 'stages';

    public $timestamps = false;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['stage', 'name', 'Этап', true];

    protected $attributes = [
        'position' => 0,
        'kind' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'pipeline_id' => 'integer',
        ];
    }
}
