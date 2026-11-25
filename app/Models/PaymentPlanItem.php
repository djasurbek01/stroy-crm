<?php

namespace App\Models;

use App\Models\Concerns\StroyModel;
use Illuminate\Database\Eloquent\Model;

/** Платёж заказчика по графику из договора. */
class PaymentPlanItem extends Model
{
    use StroyModel;

    protected $table = 'payment_plan_items';

    const UPDATED_AT = null;

    protected $attributes = [
        'received' => 0,
        'note' => '',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'received_on' => 'date',
            'amount' => 'float',
            'received' => 'float',
            'organization_id' => 'integer',
        ];
    }
}
