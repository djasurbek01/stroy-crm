<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Заявка на материалы с объекта. */
class MaterialRequest extends Model
{
    use StroyModel;

    protected $table = 'material_requests';

    const UPDATED_AT = null;

    protected $attributes = [
        'unit' => '',
        'issued' => 0,
        'reason' => '',
        'status' => 'new',
        'created_by' => '',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'issued' => 'float',
            'needed_by' => 'date',
            'organization_id' => 'integer',
            'project_id' => 'integer',
        ];
    }
}
