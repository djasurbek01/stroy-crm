<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Личная задача сотрудника. */
class Todo extends Model
{
    use StroyModel;

    protected $table = 'todos';

    const UPDATED_AT = null;

    protected $attributes = [
        'done' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'done' => 'boolean',
        ];
    }
}
