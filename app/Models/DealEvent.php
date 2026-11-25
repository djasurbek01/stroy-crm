<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Лента сделки: создание, перемещения, заметки, действия автоматики. */
class DealEvent extends Model
{
    use StroyModel;

    protected $table = 'deal_events';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [];
    }
}
