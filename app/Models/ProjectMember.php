<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Участник объекта: сотрудник, подрядчик или ген. директор, которому открыт этот объект. */
class ProjectMember extends Model
{
    use StroyModel;

    protected $table = 'project_members';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
