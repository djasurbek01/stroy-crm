<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Ссылка сброса пароля (хранится только хэш). */
class PasswordReset extends Model
{
    use StroyModel;

    protected $table = 'password_resets';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
