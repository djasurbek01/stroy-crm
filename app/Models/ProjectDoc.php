<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Проектная документация объекта. */
class ProjectDoc extends Model
{
    use StroyModel;

    protected $table = 'project_docs';

    const UPDATED_AT = null;

    protected $attributes = [
        'section' => '',
        'version' => 1,
        'status' => 'on_review',
        'released_by' => '',
        'created_by' => '',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'version' => 'integer',
            'size' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
