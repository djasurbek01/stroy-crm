<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Обращение к ИИ: для суточного лимита и учёта расхода. */
class AiUsage extends Model
{
    use StroyModel;

    protected $table = 'ai_usage';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
        ];
    }
}
