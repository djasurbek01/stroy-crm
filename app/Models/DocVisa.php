<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Виза в цепочке согласования проектного документа. */
class DocVisa extends Model
{
    use StroyModel;

    protected $table = 'doc_visas';

    public $timestamps = false;

    protected $attributes = [
        'version' => 1,
        'position' => 0,
        'side' => 'staff',
        'decision' => 'pending',
        'comment' => '',
        'decided_by' => '',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'version' => 'integer',
            'position' => 'integer',
            'doc_id' => 'integer',
        ];
    }
}
