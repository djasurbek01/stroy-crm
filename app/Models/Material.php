<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Материал в справочнике склада. */
class Material extends Model
{
    use StroyModel;

    protected $table = 'materials';

    const UPDATED_AT = null;

    protected $attributes = [
        'unit' => 'шт',
        'min_stock' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'float',
            'active' => 'boolean',
            'organization_id' => 'integer',
        ];
    }
}
