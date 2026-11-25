<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Задача или предписание по объекту. */
class SiteTask extends Model
{
    use StroyModel;

    protected $table = 'site_tasks';

    const UPDATED_AT = null;

    protected $attributes = [
        'kind' => 'task',
        'side' => 'contractor',
        'source' => 'staff',
        'note' => '',
        'author' => '',
        'status' => 'new',
        'priority' => 'normal',
        'reason' => '',
        'requires_review' => false,
        'extension_reason' => '',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'start_date' => 'date',
            'done_at' => 'datetime',
            'requires_review' => 'boolean',
            'extension_due' => 'date',
            'extension_at' => 'datetime',
            'created_by' => 'integer',
            'assignee_id' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
