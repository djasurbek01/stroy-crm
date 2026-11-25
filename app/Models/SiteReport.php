<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Фото или видео с объекта. */
class SiteReport extends Model
{
    use StroyModel;

    protected $table = 'site_reports';

    const UPDATED_AT = null;

    protected $attributes = [
        'caption' => '',
        'author' => '',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
        ];
    }
}
