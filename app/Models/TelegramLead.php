<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Чат Telegram, пришедший по ссылке приёма заявок. */
class TelegramLead extends Model
{
    use StroyModel;

    protected $table = 'telegram_leads';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [];
    }
}
