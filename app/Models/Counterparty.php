<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Контрагент: заказчик, подрядчик или поставщик. */
class Counterparty extends Model
{
    use Audited, SoftDeletes, StroyModel;

    protected $table = 'counterparties';

    const UPDATED_AT = null;

    /** Журнал: [сущность, поле названия, как называть, писать ли создание] */
    public static array $audit = ['contact', 'name', 'Контакт', true];

    protected $attributes = [
        'kind' => 'supplier',
        'inn' => '',
        'phone' => '',
        'email' => '',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
        ];
    }
}
