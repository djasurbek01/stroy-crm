<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Замечание по проверке. */
class InspectionRemark extends Model
{
    use StroyModel;

    protected $table = 'inspection_remarks';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [];
    }
}
