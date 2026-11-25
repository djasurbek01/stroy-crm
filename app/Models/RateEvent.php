<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Событие для ограничения частоты: неудачный вход, регистрация, публичная заявка. */
class RateEvent extends Model
{
    use StroyModel;

    protected $table = 'rate_events';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [];
    }
}
