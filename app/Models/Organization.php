<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Организация (компания-клиент). */
class Organization extends Model
{
    use StroyModel;

    protected $table = 'organizations';

    const UPDATED_AT = null;

    protected $attributes = [
        'plan' => 'start',
        'phone' => '',
        'email' => '',
        'details' => '',
    ];

    protected function casts(): array
    {
        return [];
    }
}
