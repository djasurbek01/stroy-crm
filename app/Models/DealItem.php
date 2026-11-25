<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Строка расчёта стоимости по сделке. */
class DealItem extends Model
{
    use StroyModel;

    protected $table = 'deal_items';

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
