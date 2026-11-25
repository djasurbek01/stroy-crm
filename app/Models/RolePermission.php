<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Отличие права роли от стандарта в этой компании: выдано (allowed = true) или отобрано (false). */
class RolePermission extends Model
{
    use StroyModel;

    protected $table = 'role_permissions';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'allowed' => 'boolean',
        ];
    }
}
