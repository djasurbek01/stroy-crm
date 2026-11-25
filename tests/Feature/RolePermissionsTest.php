<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Настройка прав ролей владельцем в разделе «Команда». */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $foreman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postJson('/auth/register', ['email' => 'boss@example.com', 'password' => 'secret123', 'name' => 'Владелец', 'org_name' => 'СтройДом'])->assertCreated();
        $this->owner = User::where('email', 'boss@example.com')->first();
        Organization::where('id', $this->owner->organization_id)->update(['plan' => 'business']);
        $this->as($this->owner)->postJson('/team', ['email' => 'foreman@example.com', 'name' => 'Прораб', 'password' => 'secret123', 'role' => 'foreman'])->assertCreated();
        $this->foreman = User::where('email', 'foreman@example.com')->first();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['token_version' => $user->fresh()->token_version]);
    }

    public function test_owner_sees_matrix_with_defaults(): void
    {
        $data = $this->as($this->owner)->getJson('/team/permissions')->assertOk()->json();
        $this->assertNotContains('owner', array_column($data['roles'], 'key'));
        $this->assertTrue($data['matrix']['foreman']['crm.read']);
        $this->assertFalse($data['matrix']['foreman']['money.read']);
        $this->as($this->foreman)->getJson('/team/permissions')->assertForbidden();
    }

    public function test_revoked_and_granted_rights_apply_immediately(): void
    {
        $this->as($this->foreman)->getJson('/deals')->assertOk();
        $this->as($this->foreman)->getJson('/payments')->assertForbidden();

        $matrix = $this->as($this->owner)->putJson('/team/permissions', ['matrix' => ['foreman' => ['crm.read' => false, 'money.read' => true]]])
            ->assertOk()->json('matrix');
        $this->assertFalse($matrix['foreman']['crm.read']);
        $this->assertTrue($matrix['foreman']['money.read']);

        $this->as($this->foreman)->getJson('/deals')->assertForbidden();
        $this->as($this->foreman)->getJson('/payments')->assertOk();
        $can = $this->as($this->foreman)->getJson('/auth/me')->json('can');
        $this->assertNotContains('crm.read', $can);
        $this->assertContains('money.read', $can);
        // справочник контрагентов для форм остаётся, даже если раздел «Сделки» закрыт
        $this->as($this->foreman)->getJson('/counterparties')->assertOk();

        // вернуть стандарт: записи-отличия удаляются
        $this->as($this->owner)->putJson('/team/permissions', ['matrix' => ['foreman' => ['crm.read' => true, 'money.read' => false]]])->assertOk();
        $this->assertDatabaseCount('role_permissions', 0);
        $this->as($this->foreman)->getJson('/deals')->assertOk();
    }

    public function test_owner_rights_and_locked_rights_cannot_change(): void
    {
        $this->as($this->owner)->putJson('/team/permissions', ['matrix' => [
            'owner' => ['crm.read' => false],
            'foreman' => ['team.manage' => true, 'chat.read' => false],
        ]])->assertOk();
        $this->assertDatabaseCount('role_permissions', 0);
        $this->as($this->owner)->getJson('/deals')->assertOk();
        $this->as($this->foreman)->getJson('/team/permissions')->assertForbidden();
    }
}
