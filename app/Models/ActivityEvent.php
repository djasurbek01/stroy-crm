<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Лента событий объекта. */
class ActivityEvent extends Model
{
    use StroyModel;

    protected $table = 'activity_events';

    const UPDATED_AT = null;

    protected $attributes = [
        'actor' => '',
    ];

    protected function casts(): array
    {
        return [];
    }
}
