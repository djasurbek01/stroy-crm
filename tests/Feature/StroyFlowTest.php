<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Сквозные сценарии: те же адреса и ответы, что у прежней версии на Python. */
class StroyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function owner(): User
    {
        $this->postJson('/auth/register', ['email' => 'boss@example.com', 'password' => 'secret123', 'name' => 'Иван', 'org_name' => 'СтройДом'])
            ->assertCreated()->assertJsonPath('role', 'owner')->assertJsonPath('plan', 'start');

        return User::where('email', 'boss@example.com')->first();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['token_version' => $user->token_version]);
    }

    public function test_auth_errors_use_detail_codes(): void
    {
        $this->getJson('/auth/me')->assertStatus(401)->assertExactJson(['detail' => 'not_authenticated']);
        $this->postJson('/auth/register', ['email' => 'a@b.ru', 'password' => 'onlyletters', 'name' => 'A', 'org_name' => 'B'])
            ->assertStatus(422)->assertExactJson(['detail' => 'weak_password']);
        $this->postJson('/auth/login', ['email' => 'nobody@b.ru', 'password' => 'x'])->assertStatus(401)->assertExactJson(['detail' => 'bad_credentials']);
        $this->postJson('/auth/register', ['email' => 'bad'])->assertStatus(422)->assertJsonStructure(['detail' => [['loc', 'msg']]]);
    }

    public function test_full_business_flow(): void
    {
        $owner = $this->owner();
        $me = $this->as($owner)->getJson('/auth/me')->assertOk()->json();
        $this->assertContains('deals.write', $me['can']);

        // демо-объект и рабочий стол
        $demo = $this->as($owner)->postJson('/demo')->assertOk()->json();
        $this->assertTrue($demo['is_demo']);
        $home = $this->as($owner)->getJson('/home')->assertOk()->json();
        $this->assertSame(4, array_sum(array_column($home['funnel'], 'count')));
        $this->assertNotNull($home['money']);
        $this->as($owner)->getJson('/dashboard')->assertOk()->assertJsonPath('0.documents', 5);

        // воронка и сделка с автоматикой «Договор подписан»
        $pipelines = $this->as($owner)->getJson('/pipelines')->assertOk()->json();
        $this->assertCount(8, $pipelines[0]['stages']);
        $signed = collect($pipelines[0]['stages'])->firstWhere('name', 'Договор подписан');
        $this->assertCount(3, $signed['automations']);
        $deal = $this->as($owner)->postJson('/deals', ['pipeline_id' => $pipelines[0]['id'], 'title' => '  Дом в Сосновке ', 'amount' => 1200000, 'customer_name' => 'Петров'])
            ->assertCreated()->json();
        $this->assertSame('Дом в Сосновке', $deal['title']);
        $moved = $this->as($owner)->postJson("/deals/{$deal['id']}/move", ['stage_id' => $signed['id']])->assertOk()->json();
        $this->assertNotNull($moved['project_id']);
        $this->assertCount(2, $moved['todos']);
        $list = $this->as($owner)->getJson('/deals?pipeline_id='.$pipelines[0]['id'])->assertOk()->assertHeader('X-Total-Count', '5');
        $this->assertCount(5, $list->json());

        // КП по ссылке
        $this->as($owner)->putJson("/deals/{$deal['id']}/offer", ['items' => [['title' => 'Фундамент', 'quantity' => 1, 'unit' => 'шт', 'price' => 500000]], 'note' => 'Срок 2 месяца'])
            ->assertOk()->assertJsonPath('amount', 500000);
        $link = $this->as($owner)->postJson("/deals/{$deal['id']}/offer/share")->assertOk()->json();
        $this->getJson('/public/offers/'.$link['token'])->assertOk()->assertJsonPath('total', 500000)->assertJsonPath('client', 'Петров');
        $this->postJson('/public/offers/'.$link['token'].'/accept', ['name' => 'Пётр'])->assertOk()->assertJsonPath('already', false);

        // документ с файлом, согласование
        $projectId = $moved['project_id'];
        $doc = $this->as($owner)->post("/projects/$projectId/documents", [
            'title' => 'Счёт на цемент', 'doc_type' => 'invoice', 'number' => '501', 'amount' => '15000', 'assignee_id' => $owner->id,
            'file' => UploadedFile::fake()->createWithContent('schet.pdf', '%PDF-1.4 test'),
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->as($owner)->postJson("/documents/{$doc['id']}/submit")->assertOk()->assertJsonPath('status', 'in_review');
        $this->as($owner)->postJson("/documents/{$doc['id']}/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'signed');
        $detail = $this->as($owner)->getJson("/documents/{$doc['id']}")->assertOk()->json();
        $this->assertSame('unpaid', $detail['pay_status']);
        $this->assertCount(1, $detail['versions']);
        $this->as($owner)->get("/documents/{$doc['id']}/download")->assertOk();

        // банковская выписка 1С: платёж привязывается к счёту по номеру
        $statement = "1CClientBankExchange\nРасчСчет=40702810000000000001\nСекцияДокумент=Платежное поручение\nНомер=77\nДата=10.11.2026\nСумма=15000.00\n"
            ."ПлательщикСчет=40702810000000000001\nПолучатель1=ООО Цемент\nПолучательИНН=6600000000\nДатаСписано=10.11.2026\nНазначениеПлатежа=Оплата по счёту № 501 за цемент\nКонецДокумента\nКонецФайла\n";
        $import = $this->as($owner)->post('/payments/import', ['file' => UploadedFile::fake()->createWithContent('vypiska.txt', $statement)], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame(1, $import['created']);
        $this->assertSame(1, $import['invoices']);
        $this->as($owner)->post('/payments/import', ['file' => UploadedFile::fake()->createWithContent('vypiska.txt', $statement)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('duplicates', 1);
        $this->as($owner)->getJson("/documents/{$doc['id']}")->assertJsonPath('pay_status', 'paid');
        $this->as($owner)->get('/export/payments.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->as($owner)->get('/export.xlsx')->assertOk();

        // график, задачи, приёмка, страница заказчика
        $schedule = $this->as($owner)->postJson("/projects/$projectId/stages/template")->assertCreated()->json();
        $this->assertCount(6, $schedule['stages']);
        $first = $schedule['stages'][0];
        $this->as($owner)->patchJson("/work-stages/{$first['id']}", ['percent' => 100])->assertOk()->assertJsonPath('status', 'done');
        $insp = $this->as($owner)->postJson("/projects/$projectId/inspections", ['stage_id' => $first['id']])->assertCreated()->json();
        $failed = $this->as($owner)->postJson("/inspections/{$insp['id']}/result", ['result' => 'failed', 'fix_by' => now()->addDays(3)->toDateString(), 'remarks' => [['text' => 'Трещина']]])
            ->assertOk()->json();
        $this->assertFalse($failed['all_fixed']);
        $task = $this->as($owner)->postJson("/projects/$projectId/site-tasks", ['title' => 'Завезти арматуру', 'priority' => 'high'])->assertCreated()->json();
        $this->as($owner)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'waiting_materials'])->assertStatus(422)->assertExactJson(['detail' => 'reason_required']);
        $share = $this->as($owner)->postJson("/projects/$projectId/customer-link")->assertOk()->json();
        $public = $this->getJson('/public/customer/'.$share['token'])->assertOk()->json();
        $this->assertCount(6, $public['stages']);
        $this->assertArrayNotHasKey('responsible_id', $public['stages'][0]);
        $this->postJson('/public/customer/'.$share['token'].'/chat', ['body' => 'Когда кровля?', 'name' => 'Пётр'])->assertCreated();
        $this->as($owner)->getJson("/projects/$projectId/chat")->assertOk()->assertJsonPath('unread', 1);

        // склад и смета
        $material = $this->as($owner)->postJson('/materials', ['name' => 'Цемент М500', 'unit' => 'мешок', 'min_stock' => 5])->assertCreated()->json();
        $this->as($owner)->postJson("/materials/{$material['id']}/movements", ['kind' => 'in', 'quantity' => 10, 'price' => 450])->assertCreated();
        $this->as($owner)->postJson("/materials/{$material['id']}/movements", ['kind' => 'out', 'quantity' => 6, 'project_id' => $projectId])->assertCreated();
        $this->as($owner)->getJson('/materials?low=true')->assertOk()->assertJsonPath('0.stock', 4);
        $est = $this->as($owner)->postJson("/projects/$projectId/estimates", ['title' => 'Основная', 'markup_pct' => 10, 'items' => [
            ['section' => 'materials', 'title' => 'Цемент', 'quantity' => 10, 'price' => 450, 'material_id' => $material['id']],
            ['section' => 'work', 'title' => 'Заливка', 'quantity' => 1, 'price' => 20000],
        ]])->assertCreated()->json();
        $this->assertSame(26950.0, (float) $est['total']);
        $this->as($owner)->postJson("/estimates/{$est['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->as($owner)->postJson("/estimates/{$est['id']}/apply-budget", ['set_contract_value' => true])->assertOk();
        $finance = $this->as($owner)->getJson("/projects/$projectId/finance")->assertOk()->json();
        $this->assertSame(26950.0, (float) $finance['revenue']['contract_value']);
        $this->as($owner)->getJson('/portfolio')->assertOk()->assertJsonStructure(['kpi' => ['projects_total', 'money'], 'projects']);

        // корзина и журнал
        $this->as($owner)->deleteJson("/deals/{$deal['id']}")->assertNoContent();
        $this->as($owner)->getJson('/trash')->assertOk()->assertJsonPath('0.entity', 'deals');
        $this->as($owner)->postJson("/trash/deals/{$deal['id']}/restore")->assertNoContent();
        $audit = $this->as($owner)->getJson('/audit')->assertOk()->json();
        $this->assertNotEmpty(array_filter($audit, fn ($e) => str_contains($e['summary'], 'восстановлен')));
        $this->as($owner)->getJson('/search?q=сосновк')->assertOk();
        $this->as($owner)->deleteJson('/demo')->assertNoContent();
    }

    public function test_roles_and_isolation(): void
    {
        $owner = $this->owner();
        $this->as($owner)->postJson('/team', ['email' => 'foreman@example.com', 'name' => 'Прораб', 'password' => 'secret123', 'role' => 'foreman'])->assertCreated();
        $foreman = User::where('email', 'foreman@example.com')->first();
        $this->as($foreman)->postJson('/deals', ['pipeline_id' => 1, 'title' => 'x'])->assertStatus(403)->assertExactJson(['detail' => 'forbidden']);
        $this->as($foreman)->getJson('/payments')->assertStatus(403);

        $project = $this->as($owner)->postJson('/projects', ['name' => 'Объект 1'])->assertCreated()->json();
        $this->postJson('/auth/logout');
        $this->postJson('/auth/register', ['email' => 'other@example.com', 'password' => 'secret123', 'name' => 'Чужой', 'org_name' => 'Другая'])->assertCreated();
        $other = User::where('email', 'other@example.com')->first();
        $this->as($other)->getJson("/projects/{$project['id']}")->assertStatus(404)->assertExactJson(['detail' => 'project_not_found']);
        $this->as($other)->getJson('/projects')->assertOk()->assertJsonCount(0);
    }

    public function test_pages_are_served(): void
    {
        foreach (['/', '/lead.html', '/offer.html', '/customer.html'] as $path) {
            $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
