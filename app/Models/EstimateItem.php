<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Позиция сметы. */
class EstimateItem extends Model
{
    use StroyModel;

    protected $table = 'estimate_items';

    public $timestamps = false;

    protected $attributes = [
        'position' => 0,
        'unit' => '',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
        ];
    }
}
