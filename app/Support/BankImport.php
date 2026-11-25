<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Разбор банковской выписки: формат 1CClientBankExchange, Excel и CSV.
 *
 * Банки выгружают немного по-разному, поэтому разбор терпимый: разные названия полей, несколько счетов,
 * назначение платежа, разбитое на строки, шапка таблицы не в первой строке, разные знаки минуса.
 * Строки, которые не удалось прочитать, не теряются молча: они возвращаются счётчиком skipped.
 */
class BankImport
{
    // Названия колонок в таблицах. Сравнение по началу слова, чтобы «Сумма (руб.)» тоже подошло.
    public const ALIASES = [
        'date' => ['дата платежа', 'дата операции', 'дата проводки', 'дата списания', 'дата'],
        'amount' => ['сумма операции', 'сумма платежа', 'сумма'],
        'income' => ['приход', 'поступление', 'кредит', 'зачисление'],
        'expense' => ['расход', 'списание', 'дебет'],
        'inn' => ['инн контрагента', 'инн получателя', 'инн плательщика', 'инн'],
        'party' => ['контрагент', 'получатель', 'плательщик', 'наименование контрагента', 'наименование'],
        'purpose' => ['назначение платежа', 'назначение', 'описание', 'комментарий'],
        'number' => ['номер документа', 'номер', '№'],
    ];

    public const MINUSES = ['−', '–', '—', '‒', '﹣', '－'];

    public static function decodeText(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        $converted = @mb_convert_encoding($data, 'UTF-8', 'Windows-1251');
        if ($converted === false) {
            ApiError::abort(422, 'bad_statement_file');
        }

        return $converted;
    }

    public static function parseDate($value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }
        $text = trim((string) ($value ?? ''));
        foreach (['d.m.Y', 'Y-m-d', 'd.m.y', 'Ymd', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $format) {
            $parsed = \DateTime::createFromFormat('!'.$format, $text);
            if ($parsed !== false && $parsed->format($format) === $text) {
                return Carbon::instance($parsed)->startOfDay();
            }
        }

        return null;
    }

    /** Принимает 1234.5, «1 234,50», «-100», «−100» (длинный минус), «100 р.». */
    public static function parseAmount($value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = str_replace(self::MINUSES, '-', trim((string) $value));
        $text = preg_replace('/[^\d,.\-]/u', '', $text);
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '', $text);  // 1,234.50
        } else {
            $text = str_replace(',', '.', $text);
        }
        if (in_array($text, ['', '-', '.', '-.'], true) || ! is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /** Возвращает [наш расчётный счёт или счета через запятую, платежи, число пропущенных документов]. */
    public static function parse1c(string $text): array
    {
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', ltrim($text, "\u{FEFF}")));
        $first = '';
        foreach ($lines as $line) {
            if ($line !== '') {
                $first = $line;
                break;
            }
        }
        if (! str_starts_with($first, '1CClientBankExchange')) {
            ApiError::abort(422, 'not_1c_statement');
        }

        $accounts = [];
        $documents = [];
        $current = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'СекцияДокумент')) {
                $current = [];
            } elseif ($line === 'КонецДокумента') {
                if ($current !== null) {
                    $documents[] = $current;
                }
                $current = null;
            } elseif (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($current !== null) {
                    $current[$key] = $value;
                } elseif ($key === 'РасчСчет' && $value !== '' && ! in_array($value, $accounts, true)) {
                    $accounts[] = $value;
                }
            }
        }

        $field = function (array $doc, string ...$names): string {
            foreach ($names as $name) {
                if (! empty($doc[$name])) {
                    return $doc[$name];
                }
            }

            return '';
        };

        $rows = [];
        $skipped = 0;
        foreach ($documents as $doc) {
            $payerAccount = $field($doc, 'ПлательщикСчет', 'ПлательщикРасчСчет');
            $outgoing = $accounts ? in_array($payerAccount, $accounts, true) : ! empty($doc['ДатаСписано']);
            if ($outgoing) {
                $when = self::parseDate($field($doc, 'ДатаСписано', 'Дата'));
                $name = $field($doc, 'Получатель1', 'Получатель');
                $inn = $field($doc, 'ПолучательИНН');
            } else {
                $when = self::parseDate($field($doc, 'ДатаПоступило', 'Дата'));
                $name = $field($doc, 'Плательщик1', 'Плательщик');
                $inn = $field($doc, 'ПлательщикИНН');
            }
            $amount = self::parseAmount($doc['Сумма'] ?? null);
            if ($when === null || $amount === null || $amount <= 0) {
                $skipped++;

                continue;
            }
            $keys = array_filter(array_keys($doc), fn ($k) => str_starts_with($k, 'НазначениеПлатежа') && $doc[$k] !== '');
            sort($keys, SORT_STRING);
            $purpose = implode(' ', array_map(fn ($k) => $doc[$k], $keys));
            $rows[] = [
                'pay_date' => $when,
                'amount' => $amount,
                'direction' => $outgoing ? 'out' : 'in',
                'purpose' => mb_substr($purpose, 0, 2000),
                'party_name' => mb_substr($name, 0, 300),
                'party_inn' => mb_substr(preg_replace('/\D/', '', $inn), 0, 20),
                'doc_number' => mb_substr($doc['Номер'] ?? '', 0, 50),
            ];
        }

        return [implode(', ', $accounts), $rows, $skipped];
    }

    private static function normalize($header): string
    {
        $text = str_replace('ё', 'е', mb_strtolower((string) ($header ?? '')));
        $text = preg_replace('/\(.*?\)/u', '', $text);

        return trim(preg_replace('/\s+/u', ' ', $text), ' .:');
    }

    private static function matchColumns(array $row): array
    {
        $columns = [];
        foreach ($row as $index => $cell) {
            $text = self::normalize($cell instanceof \DateTimeInterface ? '' : $cell);
            if ($text === '') {
                continue;
            }
            foreach (self::ALIASES as $key => $names) {
                if (isset($columns[$key])) {
                    continue;
                }
                foreach ($names as $name) {
                    if ($text === $name || (mb_strlen($name) > 3 && str_starts_with($text, $name))) {
                        $columns[$key] = $index;
                        break 2;
                    }
                }
            }
        }

        return $columns;
    }

    private static function hasNeeded(array $columns): bool
    {
        return isset($columns['date']) && (isset($columns['amount']) || isset($columns['income']) || isset($columns['expense']));
    }

    private static function text($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            return number_format($value, 1, '.', '');
        }

        return (string) $value;
    }

    /** Общий разбор таблицы (Excel и CSV). Шапку ищем в первых 20 строках: в выписках бывает преамбула. */
    public static function parseTable(array $table): array
    {
        $headerAt = null;
        $columns = [];
        foreach (array_slice($table, 0, 20) as $index => $row) {
            $found = self::matchColumns($row);
            if (self::hasNeeded($found)) {
                $headerAt = $index;
                $columns = $found;
                break;
            }
        }
        if ($headerAt === null) {
            ApiError::abort(422, 'no_payment_columns');
        }
        $cell = fn (array $row, string $key) => isset($columns[$key]) && $columns[$key] < count($row) ? $row[$columns[$key]] : null;

        $rows = [];
        $skipped = 0;
        foreach (array_slice($table, $headerAt + 1) as $row) {
            if (! array_filter($row, fn ($c) => $c !== null && $c !== '')) {
                continue;
            }
            $when = self::parseDate($cell($row, 'date'));
            $income = self::parseAmount($cell($row, 'income'));
            $expense = self::parseAmount($cell($row, 'expense'));
            $signed = self::parseAmount($cell($row, 'amount'));
            if ($income && $income > 0) {
                [$amount, $direction] = [$income, 'in'];
            } elseif ($expense && $expense > 0) {
                [$amount, $direction] = [$expense, 'out'];
            } elseif ($signed !== null && $signed != 0) {
                [$amount, $direction] = [abs($signed), $signed < 0 ? 'out' : 'in'];
            } else {
                [$amount, $direction] = [null, 'out'];
            }
            if ($when === null || $amount === null) {
                $skipped++;

                continue;
            }
            $rows[] = [
                'pay_date' => $when,
                'amount' => $amount,
                'direction' => $direction,
                'purpose' => mb_substr(self::text($cell($row, 'purpose')), 0, 2000),
                'party_name' => mb_substr(self::text($cell($row, 'party')), 0, 300),
                'party_inn' => mb_substr(preg_replace('/\D/', '', self::text($cell($row, 'inn'))), 0, 20),
                'doc_number' => mb_substr(self::text($cell($row, 'number')), 0, 50),
            ];
        }

        return ['', $rows, $skipped];
    }

    public static function parseXlsx(string $data): array
    {
        $table = Sheets::readFirst($data);
        if ($table === null) {
            ApiError::abort(422, 'bad_statement_file');
        }
        if (! $table) {
            ApiError::abort(422, 'empty_excel_file');
        }

        return self::parseTable($table);
    }

    public static function parseCsv(string $text): array
    {
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $text), 0, 20));
        $delimiter = ';';
        $best = -1;
        foreach ([';', "\t", ','] as $candidate) {
            $n = substr_count($sample, $candidate);
            if ($n > $best) {
                [$best, $delimiter] = [$n, $candidate];
            }
        }
        if ($best === 0) {
            ApiError::abort(422, 'bad_statement_file');
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, ltrim($text, "\u{FEFF}"));
        rewind($handle);
        $table = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $table[] = $row === [null] ? [] : $row;
        }
        fclose($handle);
        if (! $table) {
            ApiError::abort(422, 'empty_excel_file');
        }

        return self::parseTable($table);
    }
}
