<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Purchase;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Fmt;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Выгрузка данных для бухгалтера: Excel с листами или CSV по одному разделу. */
class ExportController extends Controller
{
    private const CONTRACT_STATUS = ['draft' => 'Черновик', 'review' => 'На согласовании', 'signed' => 'Подписан', 'closed' => 'Закрыт'];

    private const PURCHASE_STATUS = ['request' => 'Нужно купить', 'ordered' => 'Заказано', 'delivered' => 'Привезли', 'paid' => 'Оплачено'];

    private const DIRECTION = ['out' => 'Расход', 'in' => 'Приход'];

    private const SHEETS = ['contracts' => 'Договоры', 'invoices' => 'Счета', 'purchases' => 'Закупки', 'payments' => 'Платежи'];

    private const PAY_STATUS = ['paid' => 'Оплачен', 'partial' => 'Частично', 'unpaid' => 'Не оплачен'];

    /** Текст, начинающийся с = + - @, Excel принял бы за формулу. Экранируем. */
    private static function safe($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function tables(int $org, ?int $projectId): array
    {
        $projects = Project::org($org)->pluck('name', 'id');
        $parties = Counterparty::org($org)->get()->keyBy('id');
        $scoped = function (string $class) use ($org, $projectId) {
            $query = $this->scopeProjects($class::org($org), 'project_id', $class === Payment::class);
            if ($projectId !== null) {
                $query->where('project_id', $projectId);
            }

            return $query->orderBy('id')->get();
        };

        $contracts = [];
        foreach ($scoped(Contract::class) as $c) {
            $party = $parties[$c->counterparty_id] ?? null;
            $contracts[] = [$projects[$c->project_id] ?? '', $c->title, $party->name ?? '', $party->inn ?? '', (float) $c->amount,
                self::CONTRACT_STATUS[$c->status] ?? $c->status, Fmt::ru($c->due_date)];
        }
        $purchases = [];
        foreach ($scoped(Purchase::class) as $p) {
            $party = $parties[$p->counterparty_id] ?? null;
            $purchases[] = [$projects[$p->project_id] ?? '', $p->item, (float) $p->quantity, $p->unit, (float) $p->amount, $party->name ?? '',
                self::PURCHASE_STATUS[$p->status] ?? $p->status];
        }
        $pays = $scoped(Payment::class);
        $paid = [];
        foreach ($pays as $pay) {
            if ($pay->document_id) {
                $paid[$pay->document_id] = ($paid[$pay->document_id] ?? 0.0) + (float) $pay->amount;
            }
        }
        $invoiceDocs = $scoped(Document::class)->where('doc_type', 'invoice');
        $invoices = [];
        foreach ($invoiceDocs as $d) {
            $party = $parties[$d->counterparty_id] ?? null;
            $total = $paid[$d->id] ?? 0.0;
            $status = '';
            if ($d->amount !== null) {
                $status = self::PAY_STATUS[$total >= $d->amount - 0.01 ? 'paid' : ($total > 0 ? 'partial' : 'unpaid')];
            }
            $invoices[] = [$projects[$d->project_id] ?? '', $d->number, $d->title, $party->name ?? '', $party->inn ?? '',
                $d->amount ?? '', $total, $status, Fmt::ru($d->due_date)];
        }
        $docsById = $invoiceDocs->keyBy('id');
        $payments = [];
        foreach ($pays as $pay) {
            $party = $parties[$pay->counterparty_id] ?? null;
            $doc = $docsById[$pay->document_id] ?? null;
            $payments[] = [Fmt::ru($pay->pay_date), $pay->project_id ? ($projects[$pay->project_id] ?? '') : '', $party ? $party->name : $pay->party_name,
                $party ? $party->inn : $pay->party_inn, self::DIRECTION[$pay->direction] ?? $pay->direction, (float) $pay->amount, $pay->purpose ?? '',
                $pay->doc_number, ($doc && $doc->number ? "№{$doc->number} " : '').($doc ? $doc->title : '')];
        }

        return [
            'contracts' => [['Объект', 'Договор', 'Контрагент', 'ИНН', 'Сумма', 'Статус', 'Срок'], $contracts],
            'invoices' => [['Объект', 'Номер', 'Счёт', 'Контрагент', 'ИНН', 'Сумма', 'Оплачено', 'Статус оплаты', 'Срок'], $invoices],
            'purchases' => [['Объект', 'Наименование', 'Количество', 'Ед. изм.', 'Сумма', 'Поставщик', 'Статус'], $purchases],
            'payments' => [['Дата', 'Объект', 'Контрагент', 'ИНН', 'Направление', 'Сумма', 'Назначение', 'Номер', 'Счёт'], $payments],
        ];
    }

    private function scope(Request $request): ?int
    {
        $projectId = $this->qInt($request, 'project_id');
        if ($projectId !== null) {
            $this->ownProject($projectId);
        }

        return $projectId;
    }

    public function xlsx(Request $request)
    {
        $projectId = $this->scope($request);
        Audit::log($this->user(), 'export', 'export', $projectId, 'Выгрузка данных в Excel');
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($this->tables($this->org(), $projectId) as $key => [$header, $rows]) {
            $sheet = $book->createSheet();
            $sheet->setTitle(self::SHEETS[$key]);
            foreach ($header as $i => $title) {
                $sheet->setCellValue([$i + 1, 1], $title);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(max(14, mb_strlen($title) + 4));
            }
            foreach ($rows as $r => $row) {
                foreach ($row as $i => $value) {
                    $value = self::safe($value);
                    if (is_string($value)) {
                        $sheet->setCellValueExplicit([$i + 1, $r + 2], $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue([$i + 1, $r + 2], $value);
                    }
                }
            }
        }
        $book->setActiveSheetIndex(0);
        $writer = new Xlsx($book);

        return response()->streamDownload(fn () => $writer->save('php://output'), 'stroy-export.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function csv(Request $request, string $kind)
    {
        if (! isset(self::SHEETS[$kind])) {
            ApiError::abort(404, 'unknown_export');
        }
        $projectId = $this->scope($request);
        [$header, $rows] = $this->tables($this->org(), $projectId)[$kind];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => is_float($v) ? str_replace('.', ',', self::pyFloat($v)) : self::safe($v), $row), ';', '"', '');
        }
        rewind($handle);
        $text = "\u{FEFF}".stream_get_contents($handle);
        fclose($handle);

        return response($text, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"stroy-$kind.csv\"",
        ]);
    }

    /** Число как в прежней версии: 1850000.0, 2.5 */
    private static function pyFloat(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return str_contains($text, '.') ? $text : $text.'.0';
    }
}
