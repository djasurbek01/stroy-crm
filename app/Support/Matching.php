<?php

namespace App\Support;

/**
 * Автосверка платежей со счетами.
 *
 * Правила простые и проверяемые, потому что речь о деньгах. Платёж привязывается к счёту, если:
 * 1. в назначении платежа указан номер счёта («по счёту № 123», «сч.123») и совпала сумма остатка
 *    или контрагент; либо
 * 2. номера нет, но совпали и контрагент, и сумма остатка, и такой счёт ровно один.
 * Если подходят несколько счетов или ни один, платёж остаётся без привязки для ручной проверки.
 */
class Matching
{
    public const TOLERANCE = 0.01;

    /** Номер счёта должен стоять после «№», «#», «счёт», «сч.», иначе «10» совпало бы с датой 01.10.2026. */
    public static function numberInPurpose(?string $number, ?string $purpose): bool
    {
        $number = trim($number ?? '');
        if ($number === '') {
            return false;
        }
        $pattern = '/(?:№|#|(?<![\p{L}\p{N}_])N(?![\p{L}\p{N}_])|сч[её]т[а-я]*|сч\.?)\s*'.preg_quote($number, '/').'(?![\p{L}\p{N}_])/iu';

        return preg_match($pattern, $purpose ?? '') === 1;
    }

    /**
     * @param  iterable  $invoices  документы-счета (с суммой)
     * @param  array  $paid  [id документа => уже оплачено]
     */
    public static function pickInvoice(float $amount, ?int $counterpartyId, ?string $purpose, iterable $invoices, array $paid)
    {
        $scored = [];
        foreach ($invoices as $doc) {
            if ($doc->amount === null) {
                continue;
            }
            $remaining = round($doc->amount - ($paid[$doc->id] ?? 0), 2);
            if ($remaining <= 0 || $amount > $remaining + self::TOLERANCE) {
                continue;
            }
            $scored[] = [
                $doc,
                self::numberInPurpose($doc->number, $purpose),
                $counterpartyId !== null && $doc->counterparty_id === $counterpartyId,
                abs($remaining - $amount) <= self::TOLERANCE + 1e-9,
            ];
        }

        $strong = array_values(array_filter($scored, fn ($s) => $s[1] && ($s[2] || $s[3])));
        if (count($strong) === 1) {
            return $strong[0][0];
        }
        if (count($strong) > 1) {
            $exact = array_values(array_filter($strong, fn ($s) => $s[3]));

            return count($exact) === 1 ? $exact[0][0] : null;
        }
        $weak = array_values(array_filter($scored, fn ($s) => $s[2] && $s[3]));

        return count($weak) === 1 ? $weak[0][0] : null;
    }
}
