<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Воронка продаж. */
class Pipeline extends Model
{
    use Audited, StroyModel;

    protected $table = 'pipelines';

    public $timestamps = false;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['pipeline', 'name', 'Воронка', true];

    protected $attributes = [
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
