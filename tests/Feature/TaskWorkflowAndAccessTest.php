<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Доработки: возврат выполненной задачи, продление срока через ЛПР, задачи от участников, доступ только к своим объектам. */
class TaskWorkflowAndAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $contractor;

    private User $other;

    private int $projectId;

    private int $otherProjectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postJson('/auth/register', ['email' => 'boss@example.com', 'password' => 'secret123', 'name' => 'Владелец', 'org_name' => 'СтройДом'])->assertCreated();
        $this->owner = User::where('email', 'boss@example.com')->first();
        Organization::where('id', $this->owner->organization_id)->update(['plan' => 'business']);
        foreach ([['sub@example.com', 'Подрядчик Иванов', 'contractor'], ['other@example.com', 'Чужой подрядчик', 'contractor']] as [$email, $name, $role]) {
            $this->as($this->owner)->postJson('/team', ['email' => $email, 'name' => $name, 'password' => 'secret123', 'role' => $role])->assertCreated();
        }
        $this->contractor = User::where('email', 'sub@example.com')->first();
        $this->other = User::where('email', 'other@example.com')->first();
        $this->projectId = $this->as($this->owner)->postJson('/projects', ['name' => 'Дом на Лесной'])->assertCreated()->json('id');
        $this->otherProjectId = $this->as($this->owner)->postJson('/projects', ['name' => 'Баня в Сысерти'])->assertCreated()->json('id');
        $this->as($this->owner)->putJson("/projects/{$this->projectId}/members", ['user_ids' => [$this->contractor->id]])->assertOk();
        $this->as($this->owner)->putJson("/projects/{$this->otherProjectId}/members", ['user_ids' => [$this->other->id]])->assertOk();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['token_version' => $user->fresh()->token_version]);
    }

    private function taskFor(User $assignee, ?string $due = null): array
    {
        return $this->as($this->owner)->postJson("/projects/{$this->projectId}/site-tasks", [
            'title' => 'Залить фундамент', 'assignee_id' => $assignee->id, 'due_date' => $due ?? now()->addDays(5)->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_participants_see_only_their_objects(): void
    {
        $this->as($this->contractor)->getJson('/projects')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Дом на Лесной');
        $this->as($this->contractor)->getJson("/projects/{$this->otherProjectId}")->assertNotFound()->assertExactJson(['detail' => 'project_not_found']);
        $this->as($this->contractor)->getJson("/projects/{$this->otherProjectId}/site-tasks")->assertNotFound();
        $this->as($this->contractor)->getJson('/deals')->assertForbidden();
        $team = collect($this->as($this->contractor)->getJson('/team')->assertOk()->json())->pluck('id');
        $this->assertFalse($team->contains($this->other->id));

        $this->as($this->owner)->getJson('/projects')->assertJsonCount(2);
        $this->assertContains('contractor', array_column($this->as($this->owner)->getJson("/projects/{$this->projectId}/members")->json(), 'role'));
    }

    public function test_assignee_must_be_participant_and_participants_can_create_tasks(): void
    {
        $this->as($this->owner)->postJson("/projects/{$this->projectId}/site-tasks", ['title' => 'x', 'assignee_id' => $this->other->id])
            ->assertStatus(422)->assertExactJson(['detail' => 'assignee_not_member']);
        $task = $this->as($this->contractor)->postJson("/projects/{$this->projectId}/site-tasks", ['title' => 'Нужен кран', 'assignee_id' => $this->owner->id])
            ->assertCreated()->json();
        $this->assertSame($this->contractor->id, $task['created_by']);
        $this->as($this->contractor)->postJson("/projects/{$this->otherProjectId}/site-tasks", ['title' => 'Чужое'])->assertNotFound();
    }

    public function test_done_goes_to_review_and_decider_can_return_done_task(): void
    {
        $task = $this->taskFor($this->contractor);
        $sent = $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'done'])->assertOk()->json();
        $this->assertSame('in_review', $sent['status']);

        $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'done'])->assertOk()->assertJsonPath('status', 'done');
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'in_progress'])->assertForbidden()->assertExactJson(['detail' => 'not_decider']);
        $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'rejected'])->assertStatus(422)->assertExactJson(['detail' => 'reason_required']);
        $back = $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'rejected', 'reason' => 'Опалубка не снята'])->assertOk()->json();
        $this->assertSame('rejected', $back['status']);
        $this->assertFalse($back['done']);

        $history = $this->as($this->contractor)->getJson("/site-tasks/{$task['id']}")->assertOk()->json('events');
        $this->assertStringContainsString('Опалубка не снята', end($history)['text']);
    }

    public function test_assignee_requests_extension_and_decider_approves(): void
    {
        $task = $this->taskFor($this->contractor);
        $this->as($this->contractor)->patchJson("/site-tasks/{$task['id']}", ['due_date' => now()->addDays(20)->toDateString()])->assertForbidden();
        $newDue = now()->addDays(15)->toDateString();
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/extension", ['due_date' => $newDue])->assertStatus(422);
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/extension", ['due_date' => $newDue, 'comment' => 'Нет бетона у поставщика'])
            ->assertOk()->assertJsonPath('extension.due_date', $newDue);
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/extension/decide", ['decision' => 'approved'])->assertForbidden();
        $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/extension/decide", ['decision' => 'rejected'])->assertStatus(422)->assertExactJson(['detail' => 'comment_required']);
        $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/extension/decide", ['decision' => 'approved', 'comment' => 'Согласовано'])
            ->assertOk()->assertJsonPath('due_date', $newDue)->assertJsonPath('extension', null);
    }

    public function test_overdue_task_needs_approved_extension_to_continue(): void
    {
        $task = $this->taskFor($this->contractor, now()->subDays(2)->toDateString());
        $this->as($this->contractor)->getJson("/projects/{$this->projectId}/site-tasks")->assertJsonPath('0.needs_extension', true);
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'in_progress'])->assertStatus(409)->assertExactJson(['detail' => 'extension_required']);
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/extension", ['due_date' => now()->addDays(3)->toDateString(), 'comment' => 'Дождь неделю']);
        $this->as($this->owner)->postJson("/site-tasks/{$task['id']}/extension/decide", ['decision' => 'approved'])->assertOk();
        $this->as($this->contractor)->postJson("/site-tasks/{$task['id']}/status", ['status' => 'in_progress'])->assertOk()->assertJsonPath('status', 'in_progress');
    }
}
