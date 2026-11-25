<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** До какого сообщения сотрудник дочитал чат объекта. */
class ChatSeen extends Model
{
    use StroyModel;

    protected $table = 'chat_seen';

    public $timestamps = false;

    protected $attributes = [
        'last_id' => 0,
    ];

    protected function casts(): array
    {
        return [
            'last_id' => 'integer',
        ];
    }
}
