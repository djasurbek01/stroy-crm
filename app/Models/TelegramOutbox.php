<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Очередь сообщений в Telegram. */
class TelegramOutbox extends Model
{
    use StroyModel;

    protected $table = 'telegram_outbox';

    const UPDATED_AT = null;

    protected $attributes = [
        'attempts' => 0,
        'failed' => false,
    ];

    protected function casts(): array
    {
        return [
            'next_try_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed' => 'boolean',
        ];
    }
}
