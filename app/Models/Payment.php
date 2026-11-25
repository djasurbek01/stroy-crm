<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Платёж из банковской выписки. direction: out (расход) или in (приход). */
class Payment extends Model
{
    use Audited, StroyModel;

    protected $table = 'payments';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['payment', 'party_name', 'Платёж', false];

    protected $attributes = [
        'match' => '',
        'direction' => 'out',
        'purpose' => '',
        'party_name' => '',
        'party_inn' => '',
        'doc_number' => '',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'pay_date' => 'date',
            'organization_id' => 'integer',
        ];
    }
}
