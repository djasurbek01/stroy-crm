<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Сообщение в чате объекта. */
class ChatMessage extends Model
{
    use StroyModel;

    protected $table = 'chat_messages';

    const UPDATED_AT = null;

    protected $attributes = [
        'side' => 'staff',
    ];

    protected function casts(): array
    {
        return [];
    }
}
