<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Даты и числа в ответах API так же, как в прежней версии. */
class Fmt
{
    /** Дата: 2026-10-15 или null. */
    public static function d(?CarbonInterface $value): ?string
    {
        return $value?->toDateString();
    }

    /** Дата и время: 2026-10-15T12:34:56 или null. */
    public static function dt(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d\TH:i:s');
    }

    /** Дата в виде 15.10.2026. */
    public static function ru(?CarbonInterface $value): string
    {
        return $value ? $value->format('d.m.Y') : '';
    }

    /** 1 850 000 (без копеек, пробелы между разрядами). */
    public static function money0(float $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    /** 1 850 000.00 */
    public static function money2(float $value): string
    {
        return number_format($value, 2, '.', ' ');
    }

    /** Число без лишних нулей: 12, 2.5 */
    public static function g(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
