<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Чтение первого листа Excel: значения ячеек, даты как DateTimeImmutable. */
class Sheets
{
    /** Возвращает строки листа или null, если файл не читается. */
    public static function readFirst(string $data): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $data);
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadEmptyCells(false);
            $book = $reader->load($tmp);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
        $sheet = $book->getSheet(0);
        $maxRow = $sheet->getHighestDataRow();
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $rows = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $cell = $sheet->getCell([$c, $r]);
                $value = $cell->getCalculatedValue();
                if ($value !== null && $value !== '' && is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $value = \DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value));
                }
                $row[] = $value;
            }
            // хвостовые пустые ячейки отбрасываем, как openpyxl в режиме только чтения
            while ($row && ($row[count($row) - 1] === null || $row[count($row) - 1] === '')) {
                array_pop($row);
            }
            $rows[] = $row;
        }
        // пустые строки в конце листа
        while ($rows && ! array_filter(end($rows), fn ($v) => $v !== null && $v !== '')) {
            array_pop($rows);
        }

        return $rows;
    }
}
