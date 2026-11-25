<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Уведомление внутри системы («колокольчик»). */
class Notification extends Model
{
    use StroyModel;

    protected $table = 'notifications';

    const UPDATED_AT = null;

    protected $attributes = [
        'link' => '',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
