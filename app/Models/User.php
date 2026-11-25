<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Сотрудник организации. Роль задаёт права (см. App\Support\Perm). */
class User extends Authenticatable
{
    use StroyModel;

    protected $table = 'users';

    const UPDATED_AT = null;

    protected $attributes = [
        'role' => 'owner',
        'token_version' => 0,
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'token_version' => 'integer',
            'telegram_chat_id' => 'integer',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
