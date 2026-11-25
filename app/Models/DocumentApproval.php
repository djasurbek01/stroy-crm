<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Решение по документу на согласовании. */
class DocumentApproval extends Model
{
    use StroyModel;

    protected $table = 'document_approvals';

    const UPDATED_AT = null;

    protected $attributes = [
        'comment' => '',
    ];

    protected function casts(): array
    {
        return [];
    }
}
