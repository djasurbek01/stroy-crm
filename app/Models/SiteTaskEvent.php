<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** История задачи объекта: комментарии, смены статуса, запросы на продление и решения по ним. */
class SiteTaskEvent extends Model
{
    use StroyModel;

    protected $table = 'site_task_events';

    const UPDATED_AT = null;

    protected $attributes = [
        'author' => '',
    ];
}
