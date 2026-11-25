<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Версия файла документа. */
class DocumentVersion extends Model
{
    use StroyModel;

    protected $table = 'document_versions';

    const UPDATED_AT = null;

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
        ];
    }
}
