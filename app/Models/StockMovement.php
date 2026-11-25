<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Движение материала: in приход, out выдача, writeoff списание. */
class StockMovement extends Model
{
    use StroyModel;

    protected $table = 'stock_movements';

    const UPDATED_AT = null;

    protected $attributes = [
        'price' => 0,
        'doc_number' => '',
        'note' => '',
        'created_by' => '',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'moved_on' => 'date',
        ];
    }
}
