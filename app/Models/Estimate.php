<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Смета объекта (версии хранятся отдельными записями). */
class Estimate extends Model
{
    use StroyModel;

    protected $table = 'estimates';

    const UPDATED_AT = null;

    protected $attributes = [
        'version' => 1,
        'status' => 'draft',
        'markup_pct' => 0,
        'discount_pct' => 0,
        'extra' => 0,
        'note' => '',
        'created_by' => '',
        'approved_by' => '',
    ];

    protected function casts(): array
    {
        return [
            'markup_pct' => 'float',
            'discount_pct' => 'float',
            'extra' => 'float',
            'approved_at' => 'datetime',
            'version' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
