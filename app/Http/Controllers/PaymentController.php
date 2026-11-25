<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Purchase;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\BankImport;
use App\Support\Files;
use App\Support\Fmt;
use App\Support\Matching;
use App\Support\Telegram;
use Illuminate\Http\Request;

/** Банковская выписка, автосверка со счетами и ручная привязка платежей. */
class PaymentController extends Controller
{
    public static function out(Payment $p): array
    {
        return [
            'id' => $p->id, 'project_id' => $p->project_id, 'counterparty_id' => $p->counterparty_id, 'document_id' => $p->document_id,
            'match' => $p->match, 'pay_date' => Fmt::d($p->pay_date), 'amount' => (float) $p->amount, 'direction' => $p->direction,
            'purpose' => $p->purpose ?? '', 'party_name' => $p->party_name, 'party_inn' => $p->party_inn, 'doc_number' => $p->doc_number,
        ];
    }

    /** Для каждого контрагента: в каких объектах у него есть договоры или закупки. */
    private static function projectHints(int $org): array
    {
        $hints = [];
        foreach ([Contract::class, Purchase::class] as $class) {
            foreach ($class::org($org)->whereNotNull('counterparty_id')->get(['counterparty_id', 'project_id']) as $row) {
                $hints[$row->counterparty_id][$row->project_id] = true;
            }
        }

        return $hints;
    }

    private static function invoices(int $org)
    {
        return Document::org($org)->where('doc_type', 'invoice')->whereNotNull('amount')->where('status', '!=', 'rejected')->get();
    }

    private static function paid(int $org): array
    {
        return Payment::org($org)->whereNotNull('document_id')->groupBy('document_id')
            ->selectRaw('document_id, SUM(amount) as total')->pluck('total', 'document_id')->map(fn ($v) => (float) $v)->all();
    }

    private static function link(Payment $payment, Document $doc, string $how): void
    {
        $payment->document_id = $doc->id;
        $payment->match = $how;
        $payment->project_id = $doc->project_id;
        if ($payment->counterparty_id === null) {
            $payment->counterparty_id = $doc->counterparty_id;
        }
    }

    private static function notifyPaid(array $linked): void
    {
        foreach ($linked as [$payment, $doc]) {
            $verb = $payment->direction === 'out' ? 'Оплачено по счёту' : 'Получена оплата по счёту';
            $label = $doc->number ? "№{$doc->number} " : '';
            Telegram::notifyUsers([$doc->created_by, $doc->assignee_id], "{$verb} {$label}«{$doc->title}»: ".Fmt::money2((float) $payment->amount).' ₽');
        }
    }

    public function import(Request $request)
    {
        $dryRun = $this->qBool($request, 'dry_run', false);
        $request->validate(['file' => 'required|file']);
        $upload = $request->file('file');
        $data = Files::read($upload, config('stroy.max_file_bytes'));
        if ($data === '') {
            ApiError::abort(422, 'file_empty');
        }
        $name = mb_strtolower($upload->getClientOriginalName() ?? '');
        if (str_ends_with($name, '.xlsx')) {
            [$account, $rows, $skipped] = BankImport::parseXlsx($data);
        } elseif (str_ends_with($name, '.csv')) {
            [$account, $rows, $skipped] = BankImport::parseCsv(BankImport::decodeText($data));
        } elseif (str_ends_with($name, '.txt') || str_ends_with($name, '.1c')) {
            [$account, $rows, $skipped] = BankImport::parse1c(BankImport::decodeText($data));
        } else {
            ApiError::abort(422, 'file_type_not_allowed');
        }

        $org = $this->org();
        $byInn = [];
        foreach (Counterparty::org($org)->orderBy('id')->get() as $c) {
            if ($c->inn !== '') {
                $byInn[$c->inn] = $c;
            }
        }
        $hints = self::projectHints($org);
        $invoices = self::invoices($org);
        $paid = self::paid($org);
        $existing = array_flip(Payment::org($org)->pluck('dedup_key')->all());

        $seen = [];
        $newPayments = [];
        $linked = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            $amountKey = rtrim(rtrim(number_format($row['amount'], 2, '.', ''), '0'), '.');
            $base = $row['pay_date']->toDateString()."|{$row['direction']}|$amountKey|{$row['doc_number']}|{$row['party_inn']}";
            $key = $base.'|'.($seen[$base] ?? 0);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            if (isset($existing[$key])) {
                $duplicates++;

                continue;
            }
            $counterparty = $row['party_inn'] !== '' ? ($byInn[$row['party_inn']] ?? null) : null;
            $projects = $counterparty ? array_keys($hints[$counterparty->id] ?? []) : [];
            $payment = new Payment([
                'organization_id' => $org,
                'project_id' => count($projects) === 1 ? $projects[0] : null,
                'counterparty_id' => $counterparty?->id,
                'dedup_key' => mb_substr($key, 0, 300),
            ] + $row);
            $doc = Matching::pickInvoice($row['amount'], $payment->counterparty_id, $row['purpose'], $invoices, $paid);
            if ($doc !== null) {
                self::link($payment, $doc, 'auto');
                $paid[$doc->id] = ($paid[$doc->id] ?? 0) + $row['amount'];
                $linked[] = [$payment, $doc];
            }
            $newPayments[] = $payment;
        }

        $created = 0;
        if (! $dryRun) {
            foreach ($newPayments as $payment) {
                $payment->save();
            }
            Audit::log($this->user(), 'import', 'payment', null, 'Загружена выписка: платежей '.count($newPayments).', привязано к счетам '.count($linked), null, ['count' => count($newPayments), 'invoices' => count($linked)]);
            $created = count($newPayments);
            self::notifyPaid($linked);
        }

        return [
            'account' => $account,
            'rows' => count($rows),
            'new' => count($newPayments),
            'duplicates' => $duplicates,
            'matched' => count(array_filter($newPayments, fn ($p) => $p->project_id !== null)),
            'invoices' => count($linked),
            'skipped' => $skipped,
            'created' => $created,
            'preview' => array_map(fn ($p) => [
                'pay_date' => Fmt::d($p->pay_date), 'amount' => (float) $p->amount, 'direction' => $p->direction, 'party_name' => $p->party_name,
                'purpose' => $p->purpose, 'project_id' => $p->project_id, 'invoice' => (bool) $p->document_id,
            ], array_slice($newPayments, 0, 5)),
            'dry_run' => $dryRun,
        ];
    }

    /** Повторная сверка: после добавления счетов привязывает ранее загруженные платежи. */
    public function rematch()
    {
        $org = $this->org();
        $invoices = self::invoices($org);
        $paid = self::paid($org);
        $pending = Payment::org($org)->whereNull('document_id')->orderBy('pay_date')->orderBy('id')->get();
        $linked = [];
        foreach ($pending as $payment) {
            $doc = Matching::pickInvoice((float) $payment->amount, $payment->counterparty_id, $payment->purpose, $invoices, $paid);
            if ($doc !== null) {
                self::link($payment, $doc, 'auto');
                $payment->save();
                $paid[$doc->id] = ($paid[$doc->id] ?? 0) + $payment->amount;
                $linked[] = [$payment, $doc];
            }
        }
        self::notifyPaid($linked);

        return ['matched' => count($linked), 'checked' => $pending->count()];
    }

    public function index(Request $request)
    {
        $query = $this->scopeProjects(Payment::org($this->org()), 'project_id', true);
        if (($projectId = $this->qInt($request, 'project_id')) !== null) {
            $query->where('project_id', $projectId);
        }
        if (($documentId = $this->qInt($request, 'document_id')) !== null) {
            $query->where('document_id', $documentId);
        }
        if ($this->qBool($request, 'unassigned', false)) {
            $query->whereNull('project_id');
        }

        return $this->paged($request, $query->orderByDesc('pay_date')->orderByDesc('id'), fn ($items) => $items->map(fn ($p) => self::out($p)));
    }

    public function update(Request $request, $id)
    {
        $payment = $this->own(Payment::class, $id, 'payment_not_found');
        $changes = $request->validate([
            'project_id' => 'sometimes|nullable|integer',
            'counterparty_id' => 'sometimes|nullable|integer',
            'document_id' => 'sometimes|nullable|integer',
        ]);
        if (($changes['project_id'] ?? null) !== null) {
            $this->ownProject($changes['project_id']);
        }
        $this->checkCounterparty($changes['counterparty_id'] ?? null);
        if (array_key_exists('document_id', $changes)) {
            $docId = $changes['document_id'];
            unset($changes['document_id']);
            if ($docId === null) {
                $payment->document_id = null;
                $payment->match = '';
            } else {
                self::link($payment, $this->own(Document::class, $docId, 'document_not_found'), 'manual');
            }
        }
        foreach ($changes as $field => $value) {
            $payment->{$field} = $value;
        }
        $payment->save();

        return self::out($payment);
    }
}
