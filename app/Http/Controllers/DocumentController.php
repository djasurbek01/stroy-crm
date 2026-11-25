<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentVersion;
use App\Models\Payment;
use App\Models\Project;
use App\Support\ApiError;
use App\Support\Files;
use App\Support\Fmt;
use App\Support\Telegram;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class DocumentController extends Controller
{
    private function find($id): Document
    {
        return $this->own(Document::class, $id, 'document_not_found');
    }

    /** Документы с суммой оплат и статусом оплаты (по привязанным платежам). */
    public static function decorate(iterable $docs): array
    {
        $docs = collect($docs);
        $paid = $docs->isEmpty() ? collect() : Payment::whereIn('document_id', $docs->pluck('id'))
            ->groupBy('document_id')->selectRaw('document_id, SUM(amount) as total')->pluck('total', 'document_id');
        $out = [];
        foreach ($docs as $doc) {
            $total = (float) ($paid[$doc->id] ?? 0);
            $payStatus = '';
            $overdue = $doc->isOverdue();
            if ($doc->amount !== null) {
                $payStatus = $total >= $doc->amount - 0.01 ? 'paid' : ($total > 0 ? 'partial' : 'unpaid');
                if ($payStatus === 'paid' && $doc->doc_type === 'invoice') {
                    $overdue = false;  // оплаченный счёт просроченным не считается
                }
            }
            $out[] = array_merge(self::out($doc, $overdue), ['paid_amount' => $total, 'pay_status' => $payStatus]);
        }

        return $out;
    }

    public static function out(Document $doc, ?bool $overdue = null): array
    {
        return [
            'id' => $doc->id,
            'project_id' => $doc->project_id,
            'counterparty_id' => $doc->counterparty_id,
            'assignee_id' => $doc->assignee_id,
            'contract_id' => $doc->contract_id,
            'title' => $doc->title,
            'created_by' => $doc->created_by,
            'number' => $doc->number,
            'amount' => $doc->amount,
            'paid_amount' => 0.0,
            'pay_status' => '',
            'doc_type' => $doc->doc_type,
            'category' => $doc->category,
            'status' => $doc->status,
            'due_date' => Fmt::d($doc->due_date),
            'version' => $doc->version,
            'overdue' => $overdue ?? $doc->isOverdue(),
            'created_at' => Fmt::dt($doc->created_at),
        ];
    }

    private function detail(Document $doc): array
    {
        $versions = DocumentVersion::where('document_id', $doc->id)->orderBy('version')->get();
        $approvals = DocumentApproval::where('document_id', $doc->id)->orderBy('id')->get();

        return self::decorate([$doc])[0] + [
            'versions' => $versions->map(fn ($v) => ['version' => $v->version, 'filename' => $v->filename, 'size' => $v->size, 'uploaded_by' => $v->uploaded_by, 'created_at' => Fmt::dt($v->created_at)]),
            'approvals' => $approvals->map(fn ($a) => ['user_id' => $a->user_id, 'decision' => $a->decision, 'comment' => $a->comment ?? '', 'created_at' => Fmt::dt($a->created_at)]),
        ];
    }

    private function addVersion(Document $doc, ?UploadedFile $upload): void
    {
        [$filename, $path, $size] = Files::saveUpload($this->org(), $upload);
        DocumentVersion::create([
            'organization_id' => $this->org(), 'document_id' => $doc->id, 'version' => $doc->version,
            'filename' => $filename, 'stored_path' => $path, 'size' => $size, 'uploaded_by' => $this->user()->id,
        ]);
    }

    public function store(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $data = $request->validate([
            'title' => 'required|string|min:1|max:300',
            'doc_type' => 'sometimes|string',
            'category' => 'sometimes|nullable|string',
            'counterparty_id' => 'sometimes|nullable|integer',
            'assignee_id' => 'sometimes|nullable|integer',
            'contract_id' => 'sometimes|nullable|integer',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'number' => 'sometimes|nullable|string|max:50',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'file' => 'required|file',
        ]);
        $nullable = fn ($key) => isset($data[$key]) && $data[$key] !== '' ? $data[$key] : null;
        $docType = $data['doc_type'] ?? 'other';
        $category = $data['category'] ?? '';
        if (! in_array($docType, Document::TYPES, true)) {
            ApiError::abort(422, 'bad_doc_type');
        }
        if ($category && ! in_array($category, Document::EXPENSE_CATEGORIES, true)) {
            ApiError::abort(422, 'bad_category');
        }
        $this->checkCounterparty($nullable('counterparty_id'));
        $this->checkUser($nullable('assignee_id'));
        $this->checkContract($nullable('contract_id'));
        $doc = Document::create([
            'organization_id' => $this->org(),
            'project_id' => (int) $projectId,
            'counterparty_id' => $nullable('counterparty_id'),
            'assignee_id' => $nullable('assignee_id'),
            'contract_id' => $nullable('contract_id'),
            'number' => trim($data['number'] ?? ''),
            'amount' => $nullable('amount'),
            'title' => $data['title'],
            'doc_type' => $docType,
            'category' => $docType === 'receipt' ? (string) $category : '',
            'due_date' => $nullable('due_date'),
            'created_by' => $this->user()->id,
            'version' => 1,
        ]);
        $this->addVersion($doc, $request->file('file'));

        return response()->json(self::out($doc->fresh()), 201);
    }

    public function index(Request $request)
    {
        $query = $this->scopeProjects(Document::org($this->org()));
        if (($projectId = $this->qInt($request, 'project_id')) !== null) {
            $query->where('project_id', $projectId);
        }
        if ($request->query('doc_type')) {
            $query->where('doc_type', $request->query('doc_type'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if (($cp = $this->qInt($request, 'counterparty_id')) !== null) {
            $query->where('counterparty_id', $cp);
        }
        if (($contract = $this->qInt($request, 'contract_id')) !== null) {
            $query->where('contract_id', $contract);
        }
        if ($this->qBool($request, 'overdue', null)) {
            $query->where('due_date', '<', today()->toDateString())->whereIn('status', Document::OPEN_STATUSES);
        }
        $this->whereContains($query, ['title', 'number'], $request->query('q'));

        return $this->paged($request, $query->orderBy('id'), fn ($docs) => self::decorate($docs));
    }

    public function show($id)
    {
        return $this->detail($this->find($id));
    }

    public function download(Request $request, $id)
    {
        $doc = $this->find($id);
        $wanted = $this->qInt($request, 'version') ?? $doc->version;
        $item = DocumentVersion::where('document_id', $doc->id)->where('version', $wanted)->first();
        if ($item === null) {
            ApiError::abort(404, 'version_not_found');
        }
        $path = Files::fullPath($item->stored_path);
        if (! is_file($path)) {
            ApiError::abort(404, 'file_missing');
        }

        return response()->download($path, $item->filename);
    }

    public function addVersionAction(Request $request, $id)
    {
        $doc = $this->find($id);
        if ($doc->status === 'signed') {
            ApiError::abort(409, 'document_signed');
        }
        $request->validate(['file' => 'required|file']);
        $doc->version++;
        $doc->status = 'draft';
        $this->addVersion($doc, $request->file('file'));
        $doc->save();

        return response()->json(self::out($doc), 201);
    }

    public function submit($id)
    {
        $doc = $this->find($id);
        if ($doc->status !== 'draft') {
            ApiError::abort(409, 'not_a_draft');
        }
        $doc->status = 'in_review';
        $doc->save();
        $user = $this->user();
        if ($doc->assignee_id && $doc->assignee_id !== $user->id) {
            $project = Project::find($doc->project_id);
            $due = $doc->due_date ? ', срок '.Fmt::ru($doc->due_date) : '';
            Telegram::notifyUsers([$doc->assignee_id], "На согласование: «{$doc->title}» ({$project->name}$due)");
        }

        return self::out($doc);
    }

    public function decide(Request $request, $id)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'comment' => 'sometimes|string|max:2000']);
        $doc = $this->find($id);
        $user = $this->user();
        if ($doc->status !== 'in_review') {
            ApiError::abort(409, 'not_in_review');
        }
        if ($doc->assignee_id !== null && $user->id !== $doc->assignee_id && $user->role !== 'owner') {
            ApiError::abort(403, 'not_your_document');
        }
        $comment = $data['comment'] ?? '';
        DocumentApproval::create([
            'organization_id' => $user->organization_id, 'document_id' => $doc->id, 'user_id' => $user->id,
            'decision' => $data['decision'], 'comment' => $comment,
        ]);
        $doc->status = $data['decision'] === 'approved' ? 'signed' : 'rejected';
        $doc->save();
        if ($doc->created_by !== $user->id) {
            $verdict = $data['decision'] === 'approved' ? 'согласован' : 'отклонён';
            $note = $comment !== '' ? ". $comment" : '';
            Telegram::notifyUsers([$doc->created_by], "Документ «{$doc->title}» $verdict$note");
        }

        return self::out($doc);
    }

    /** «Мои задачи»: что ждёт моего согласования и что просрочено. */
    public function tasks()
    {
        $user = $this->user();
        $docs = Document::org($user->organization_id)->where('assignee_id', $user->id)->whereIn('status', Document::OPEN_STATUSES)->orderBy('id')->get();

        return $docs->filter(fn ($d) => $d->status === 'in_review' || $d->isOverdue())->values()->map(fn ($d) => self::out($d));
    }
}
