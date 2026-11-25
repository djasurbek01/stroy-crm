<?php

namespace App\Support;

/** Разбор Excel-таблицы закупок: русские заголовки в любом порядке колонок. */
class ExcelImport
{
    public const ALIASES = [
        'item' => ['наименование', 'материал', 'позиция', 'название', 'товар', 'что купить'],
        'quantity' => ['количество', 'кол-во', 'колво', 'кол.', 'объем', 'объём'],
        'unit' => ['ед', 'ед.', 'ед. изм.', 'ед.изм.', 'единица', 'единицы'],
        'amount' => ['сумма', 'стоимость', 'цена', 'итого'],
        'supplier' => ['поставщик', 'контрагент', 'продавец'],
    ];

    public static function parseNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $cleaned = str_replace(',', '.', preg_replace('/[^\d,.\-]/u', '', (string) $value));

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private static function cellText($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_float($value) && floor($value) == $value) {
            return number_format($value, 1, '.', '');
        }

        return trim((string) ($value ?? ''));
    }

    private static function matchColumns(array $header): array
    {
        $found = [];
        foreach ($header as $index => $cell) {
            $text = mb_strtolower(trim((string) ($cell ?? '')));
            foreach (self::ALIASES as $key => $names) {
                if (! isset($found[$key]) && in_array($text, $names, true)) {
                    $found[$key] = $index;
                }
            }
        }

        return $found;
    }

    /** Возвращает [найденные колонки, строки, число пропущенных непустых строк без наименования]. */
    public static function readRows(string $data): array
    {
        $rows = Sheets::readFirst($data);
        if ($rows === null) {
            ApiError::abort(422, 'bad_excel_file');
        }
        if (! $rows) {
            ApiError::abort(422, 'empty_excel_file');
        }
        $columns = self::matchColumns($rows[0]);
        if (! isset($columns['item'])) {
            ApiError::abort(422, 'no_item_column');
        }

        $parsed = [];
        $skipped = 0;
        foreach (array_slice($rows, 1) as $row) {
            $cell = fn ($key) => isset($columns[$key]) && $columns[$key] < count($row) ? $row[$columns[$key]] : null;
            $item = self::cellText($cell('item'));
            if ($item === '') {
                if (array_filter($row, fn ($c) => $c !== null && $c !== '')) {
                    $skipped++;
                }

                continue;
            }
            $parsed[] = [
                'item' => mb_substr($item, 0, 300),
                'quantity' => self::parseNumber($cell('quantity')) ?: 1.0,
                'unit' => mb_substr(self::cellText($cell('unit')), 0, 30),
                'amount' => self::parseNumber($cell('amount')) ?: 0.0,
                'supplier' => mb_substr(self::cellText($cell('supplier')), 0, 200),
            ];
        }

        return [$columns, $parsed, $skipped];
    }
}
