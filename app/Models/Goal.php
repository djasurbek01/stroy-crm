<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** План продаж на месяц. */
class Goal extends Model
{
    use StroyModel;

    protected $table = 'goals';

    public $timestamps = false;

    protected $attributes = [
        'amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
