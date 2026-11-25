<?php

namespace App\Support;

use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\StockMovement;

/** Остатки склада считаются по движениям, а не хранятся числом: любое число можно объяснить операциями. */
class Stock
{
    public const OPEN_REQUEST = ['new', 'partial', 'purchase'];

    /** Остаток, резерв и средняя цена прихода по каждому материалу организации. */
    public static function levels(int $organizationId): array
    {
        $levels = [];
        foreach (Material::where('organization_id', $organizationId)->pluck('id') as $id) {
            $levels[$id] = ['in' => 0.0, 'out' => 0.0, 'in_cost' => 0.0, 'reserved' => 0.0];
        }
        foreach (StockMovement::where('organization_id', $organizationId)->get() as $mv) {
            if (! isset($levels[$mv->material_id])) {
                continue;
            }
            if ($mv->kind === 'in') {
                $levels[$mv->material_id]['in'] += $mv->quantity;
                $levels[$mv->material_id]['in_cost'] += $mv->quantity * $mv->price;
            } else {
                $levels[$mv->material_id]['out'] += $mv->quantity;
            }
        }
        $requests = MaterialRequest::where('organization_id', $organizationId)->whereNotNull('material_id')->whereIn('status', self::OPEN_REQUEST)->get();
        foreach ($requests as $req) {
            if (isset($levels[$req->material_id])) {
                $levels[$req->material_id]['reserved'] += max($req->quantity - $req->issued, 0);
            }
        }
        foreach ($levels as &$lv) {
            $lv['stock'] = round($lv['in'] - $lv['out'], 3);
            $lv['avg_price'] = $lv['in'] > 0 ? $lv['in_cost'] / $lv['in'] : 0.0;
        }

        return $levels;
    }
}
