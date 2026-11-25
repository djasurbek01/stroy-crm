<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\Perm;
use Illuminate\Http\Request;

/** Удаление в корзину, восстановление и журнал действий. */
class LifecycleController extends Controller
{
    // сущность в адресе -> [модель, право на удаление, поле названия, как называть, код «не найдено»]
    private const ENTITIES = [
        'deals' => [Deal::class, 'deals.write', 'title', 'Сделка', 'deal'],
        'documents' => [Document::class, 'documents.write', 'title', 'Документ', 'document'],
        'contracts' => [Contract::class, 'contracts.write', 'title', 'Договор', 'contract'],
        'purchases' => [Purchase::class, 'purchases.write', 'item', 'Закупка', 'purchase'],
        'contacts' => [Counterparty::class, 'contacts.write', 'name', 'Контакт', 'contact'],
    ];

    private function softDelete(string $kind, $id)
    {
        [$class, $cap, , , $name] = self::ENTITIES[$kind];
        $user = $this->user();
        if (! Perm::allows($user, $cap)) {
            ApiError::abort(403, 'forbidden');
        }
        $item = $this->own($class, $id, "{$name}_not_found");
        if ($item instanceof Document) {
            if ($user->role !== 'owner' && $item->created_by !== $user->id) {
                ApiError::abort(403, 'forbidden');  // чужие документы удаляет только владелец
            }
            if (Payment::where('document_id', $item->id)->exists()) {
                ApiError::abort(409, 'document_has_payments');
            }
        }
        $item->delete();

        return $this->noContent();
    }

    public function deleteDeal($id)
    {
        return $this->softDelete('deals', $id);
    }

    public function deleteDocument($id)
    {
        return $this->softDelete('documents', $id);
    }

    public function deleteContract($id)
    {
        return $this->softDelete('contracts', $id);
    }

    public function deletePurchase($id)
    {
        return $this->softDelete('purchases', $id);
    }

    public function deleteCounterparty($id)
    {
        return $this->softDelete('contacts', $id);
    }

    public function trash()
    {
        $items = [];
        foreach (self::ENTITIES as $kind => [$class, , $field, $ru]) {
            foreach ($class::onlyTrashed()->where('organization_id', $this->org())->orderByDesc('deleted_at')->get() as $row) {
                $items[] = ['entity' => $kind, 'kind' => $ru, 'id' => $row->id, 'title' => (string) ($row->{$field} ?? ''), 'deleted_at' => Fmt::dt($row->deleted_at)];
            }
        }
        usort($items, fn ($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']));

        return $items;
    }

    public function restore(string $kind, $id)
    {
        if (! isset(self::ENTITIES[$kind])) {
            ApiError::abort(404, 'unknown_entity');
        }
        $class = self::ENTITIES[$kind][0];
        $item = is_numeric($id) ? $class::onlyTrashed()->find((int) $id) : null;
        if ($item === null || $item->organization_id !== $this->org()) {
            ApiError::abort(404, 'not_in_trash');
        }
        $item->restore();

        return $this->noContent();
    }

    public function audit(Request $request)
    {
        $query = AuditEvent::where('organization_id', $this->org());
        if ($request->query('entity')) {
            $query->where('entity', $request->query('entity'));
        }
        if (($entityId = $this->qInt($request, 'entity_id')) !== null) {
            $query->where('entity_id', $entityId);
        }
        if (($userId = $this->qInt($request, 'user_id')) !== null) {
            $query->where('user_id', $userId);
        }
        if ($request->query('action')) {
            $query->where('action', $request->query('action'));
        }
        $names = User::org($this->org())->pluck('name', 'id');

        return $this->paged($request, $query->orderByDesc('id'), fn ($rows) => $rows->map(fn ($r) => [
            'id' => $r->id,
            'created_at' => Fmt::dt($r->created_at),
            'user' => $names[$r->user_id] ?? '',
            'user_id' => $r->user_id,
            'action' => $r->action,
            'entity' => $r->entity,
            'entity_id' => $r->entity_id,
            'summary' => $r->summary,
            'details' => (object) ($r->details ?? []),
        ]));
    }
}
