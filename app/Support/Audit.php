<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/** Журнал действий: ручные записи и общие правила для автоматических. */
class Audit
{
    /** Служебные действия (воронка по умолчанию, демо-данные) в журнал не пишутся. */
    public static bool $paused = false;

    public const FIELD_NAMES = [
        'title' => 'название', 'name' => 'название', 'item' => 'позиция', 'amount' => 'сумма', 'status' => 'статус',
        'stage_id' => 'этап', 'responsible_id' => 'ответственный', 'counterparty_id' => 'контрагент',
        'assignee_id' => 'исполнитель', 'due_date' => 'срок', 'address' => 'адрес', 'number' => 'номер',
        'quantity' => 'количество', 'unit' => 'единица', 'kind' => 'тип', 'inn' => 'ИНН', 'phone' => 'телефон',
        'email' => 'почта', 'project_id' => 'объект', 'document_id' => 'счёт', 'contract_id' => 'договор',
        'match' => 'способ привязки', 'doc_type' => 'тип', 'version' => 'версия', 'start_date' => 'начало',
        'end_date' => 'окончание', 'direction' => 'направление', 'plan' => 'пакет',
        'hourly_rate' => 'ставка в час', 'position' => 'должность', 'active' => 'работает',
    ];

    public const IGNORED = ['created_at', 'deleted_at', 'closed_at', 'id', 'organization_id', 'token_version', 'position'];

    /** Выполнить действие без записи в журнал. */
    public static function paused(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;
        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    /** Ручная запись для действий, которых нет среди отслеживаемых моделей (выписка, выгрузка, настройки). */
    public static function log(?User $user, string $action, string $entity, ?int $entityId, string $summary, ?int $organizationId = null, array $details = []): void
    {
        $org = $organizationId ?? $user?->organization_id;
        if ($org === null) {
            return;
        }
        AuditEvent::create([
            'organization_id' => $org,
            'user_id' => $user?->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 500),
            'details' => array_filter($details, fn ($v) => $v !== null),
        ]);
    }

    public static function record($model, string $action, string $summary, array $details = []): void
    {
        if (self::$paused || $model->organization_id === null) {
            return;
        }
        AuditEvent::create([
            'organization_id' => $model->organization_id,
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => $model::$audit[0],
            'entity_id' => $model->getKey(),
            'summary' => mb_substr($summary, 0, 500),
            'details' => $details,
        ]);
    }

    /** «Сделка «Название»». */
    public static function named($model): string
    {
        [, $field, $ru] = $model::$audit;
        $value = (string) ($model->{$field} ?? '');
        if ($model instanceof Payment) {
            $value = ($value !== '' ? $value : 'без названия').', '.Fmt::money2($model->amount).' ₽';
        }

        return "$ru «".mb_substr($value, 0, 120).'»';
    }

    /** {поле: [было, стало]} для реально изменившихся колонок. */
    public static function changesOf($model): array
    {
        $result = [];
        foreach (array_keys($model->getChanges()) as $key) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }
            $old = self::plain($model->getOriginal($key));
            $new = self::plain($model->getAttribute($key));
            if ($old !== $new) {
                $result[$key] = [$old, $new];
            }
        }

        return $result;
    }

    private static function plain($value)
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('H:i:s') === '00:00:00' && strlen((string) $value->toDateString()) === 10 ? $value->toDateString() : $value->format('Y-m-d\TH:i:s');
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value == (int) $value && ! is_float($value) ? $value : (float) $value;
        }

        return $value;
    }
}
