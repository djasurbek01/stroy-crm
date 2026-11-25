<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Плановые расходы объекта по статье. */
class BudgetLine extends Model
{
    use StroyModel;

    protected $table = 'budget_lines';

    public $timestamps = false;

    protected $attributes = [
        'planned' => 0,
    ];

    protected function casts(): array
    {
        return [
            'planned' => 'float',
        ];
    }
}
