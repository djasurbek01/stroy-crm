<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentVersion;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Stage;
use App\Models\Todo;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\CrmEngine;
use App\Support\Files;
use Illuminate\Support\Facades\DB;

/** Демо-объект с примерами: сделки, документы, закупки, платёж. В журнал не попадает. */
class DemoController extends Controller
{
    public const DEMO_NAME = 'Демо: жилой дом на Ленина';

    private function counterparty(int $org, string $name, string $kind): Counterparty
    {
        return Counterparty::org($org)->where('name', $name)->first()
            ?? Counterparty::create(['organization_id' => $org, 'name' => $name, 'kind' => $kind]);
    }

    private function document(User $user, Project $project, string $title, string $docType, string $status, ?Contract $contract = null,
        ?Counterparty $supplier = null, ?int $dueInDays = null, ?User $assignee = null, string $number = '', ?float $amount = null): Document
    {
        $doc = Document::create([
            'organization_id' => $user->organization_id, 'project_id' => $project->id, 'contract_id' => $contract?->id,
            'counterparty_id' => $supplier?->id, 'assignee_id' => $assignee?->id, 'title' => $title, 'number' => $number,
            'amount' => $amount, 'doc_type' => $docType, 'status' => $status,
            'due_date' => $dueInDays !== null ? today()->addDays($dueInDays) : null, 'created_by' => $user->id, 'version' => 1,
        ]);
        $text = "Образец документа для демо-объекта.\n$title\n";
        $path = Files::saveBytes($user->organization_id, '.txt', $text);
        DocumentVersion::create([
            'organization_id' => $user->organization_id, 'document_id' => $doc->id, 'version' => 1, 'filename' => "$title.txt",
            'stored_path' => $path, 'size' => strlen($text), 'uploaded_by' => $user->id,
        ]);

        return $doc;
    }

    public function store()
    {
        $user = $this->user();
        $org = $user->organization_id;
        $existing = Project::org($org)->where('is_demo', true)->first();
        if ($existing) {
            return ProjectController::out($existing);
        }

        $project = Audit::paused(fn () => DB::transaction(function () use ($user, $org) {
            $project = Project::create([
                'organization_id' => $org, 'name' => self::DEMO_NAME, 'address' => 'Екатеринбург, ул. Ленина, 10',
                'status' => 'in_progress', 'is_demo' => true, 'start_date' => today()->subDays(30),
            ]);
            $brick = $this->counterparty($org, 'ООО Кирпичный завод', 'supplier');
            $builder = $this->counterparty($org, 'ИП Сидоров, фундаментные работы', 'contractor');
            $foundation = Contract::create(['organization_id' => $org, 'project_id' => $project->id, 'counterparty_id' => $builder->id,
                'title' => 'Договор подряда на фундамент', 'amount' => 1850000, 'status' => 'signed']);
            $supply = Contract::create(['organization_id' => $org, 'project_id' => $project->id, 'counterparty_id' => $brick->id,
                'title' => 'Договор поставки кирпича', 'amount' => 640000, 'status' => 'review']);

            $this->document($user, $project, 'Договор подряда на фундамент', 'contract', 'signed', $foundation, $builder);
            $this->document($user, $project, 'Акт скрытых работ: армирование', 'act', 'in_review', $foundation, $builder, 3, $user);
            $this->document($user, $project, 'Счёт на бетон М300', 'invoice', 'draft', null, $brick, -5, $user, '147', 212000);
            $brickInvoice = $this->document($user, $project, 'Счёт на кирпич', 'invoice', 'signed', null, $brick, null, null, '88', 168000);
            $this->document($user, $project, 'Накладная: кирпич, 5 паллет', 'waybill', 'signed', $supply, $brick);

            foreach ([
                ['Кирпич облицовочный', 12000, 'шт', 168000, 'ordered', $brick],
                ['Бетон М300', 40, 'м³', 212000, 'delivered', null],
                ['Арматура А500', 3, 'т', 186000, 'paid', null],
                ['Утеплитель 100 мм', 120, 'м²', 96000, 'request', null],
            ] as [$item, $qty, $unit, $amount, $status, $supplier]) {
                Purchase::create(['organization_id' => $org, 'project_id' => $project->id, 'item' => $item, 'quantity' => $qty,
                    'unit' => $unit, 'amount' => $amount, 'status' => $status, 'counterparty_id' => $supplier?->id]);
            }

            CrmEngine::ensureDefault($org);
            $pipeline = CrmEngine::firstPipeline($org);
            $stages = Stage::where('pipeline_id', $pipeline->id)->get()->keyBy('name');
            foreach ([
                ['Жилой дом на Ленина', 'Объект в работе', 1850000, 'Екатеринбург, ул. Ленина, 10', true],
                ['Дом 140 м², семья Ивановых', 'Заявка', 0, 'Берёзовский, участок 12', false],
                ['Баня из бруса, Петров', 'Замер или осмотр', 950000, 'Сысерть, СНТ Лесное', false],
                ['Коттедж Сидоровых', 'Смета и КП', 4200000, 'Екатеринбург, пос. Совхозный', false],
            ] as [$title, $stageName, $amount, $address, $linked]) {
                $deal = Deal::create([
                    'organization_id' => $org, 'pipeline_id' => $pipeline->id, 'stage_id' => $stages[$stageName]->id, 'title' => $title,
                    'amount' => $amount, 'responsible_id' => $user->id, 'address' => $address, 'is_demo' => true,
                    'project_id' => $linked ? $project->id : null,
                ]);
                DealEvent::create(['organization_id' => $org, 'deal_id' => $deal->id, 'user_id' => $user->id, 'kind' => 'created',
                    'text' => "Сделка создана на этапе «{$stageName}»", 'to_stage_id' => $stages[$stageName]->id]);
            }
            Payment::create([
                'organization_id' => $org, 'project_id' => $project->id, 'counterparty_id' => $brick->id, 'document_id' => $brickInvoice->id,
                'match' => 'auto', 'pay_date' => today()->subDays(2), 'amount' => 168000, 'direction' => 'out',
                'purpose' => 'Оплата по счёту № 88 за кирпич', 'party_name' => $brick->name, 'doc_number' => '501', 'dedup_key' => 'demo|1',
            ]);

            return $project;
        }));

        return ProjectController::out($project->fresh());
    }

    /** Удаляет все демо-проекты и всё, что к ним привязано. */
    public function destroy()
    {
        $org = $this->org();
        $projectIds = Project::org($org)->where('is_demo', true)->pluck('id')->all();
        if (! $projectIds) {
            ApiError::abort(404, 'demo_not_found');
        }
        DB::transaction(function () use ($org, $projectIds) {
            $docIds = Document::withTrashed()->whereIn('project_id', $projectIds)->pluck('id')->all() ?: [0];
            $dealIds = Deal::withTrashed()->where('organization_id', $org)->where('is_demo', true)->pluck('id')->all() ?: [0];
            Payment::whereIn('project_id', $projectIds)->orWhereIn('document_id', $docIds)->delete();
            Todo::whereIn('project_id', $projectIds)->orWhereIn('deal_id', $dealIds)->delete();
            DealEvent::whereIn('deal_id', $dealIds)->delete();
            Deal::withTrashed()->whereIn('id', $dealIds)->forceDelete();
            DocumentApproval::whereIn('document_id', $docIds)->delete();
            DocumentVersion::whereIn('document_id', $docIds)->delete();
            Document::withTrashed()->whereIn('id', $docIds)->forceDelete();
            Purchase::withTrashed()->whereIn('project_id', $projectIds)->forceDelete();
            Contract::withTrashed()->whereIn('project_id', $projectIds)->forceDelete();
            Project::whereIn('id', $projectIds)->delete();
        });

        return $this->noContent();
    }
}
