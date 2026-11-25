<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Документ объекта: файл с версиями, тип, статус согласования, срок. */
class Document extends Model
{
    use Audited, SoftDeletes, StroyModel;

    public const TYPES = ['contract', 'invoice', 'act', 'waybill', 'receipt', 'drawing', 'letter', 'other'];

    /** Статьи расходов для чеков. */
    public const EXPENSE_CATEGORIES = ['materials', 'tools', 'fuel', 'labor', 'transport', 'subcontract', 'equipment', 'other'];

    public const OPEN_STATUSES = ['draft', 'in_review'];

    protected $table = 'documents';

    const UPDATED_AT = null;

    public static array $audit = ['document', 'title', 'Документ', true];

    protected $attributes = [
        'number' => '',
        'doc_type' => 'other',
        'category' => '',
        'status' => 'draft',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_date' => 'date',
            'version' => 'integer',
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** Срок прошёл, а документ ещё не закрыт. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && in_array($this->status, self::OPEN_STATUSES, true);
    }
}
