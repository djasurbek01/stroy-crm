<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Журнал действий: кто, что и когда менял. Только добавляется. */
class AuditEvent extends Model
{
    use StroyModel;

    protected $table = 'audit_events';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }
}
