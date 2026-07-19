<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_operational_users(): void
    {
        $this->seed();
        $owner = User::query()->where('username', 'owner')->firstOrFail();

        $created = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Kasir Shift Malam',
            'username' => 'kasir02',
            'email' => 'kasir02@example.test',
            'password' => 'rahasia123',
            'role' => 'Kasir',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'Kasir')
            ->assertJsonPath('data.is_active', true);

        $userId = $created->json('data.id');
        $user = User::query()->findOrFail($userId);
        $user->createToken('mobile-app');

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/users?role=Kasir')
            ->assertOk()
            ->assertJsonFragment(['username' => 'kasir02']);

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/users/{$userId}", [
            'name' => 'Waiter Shift Malam',
            'role' => 'Waiter',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.role', 'Waiter');

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/users/{$userId}/reset-password", [
            'password' => 'passwordBaru123',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('passwordBaru123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/users/{$userId}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_cannot_manage_or_assign_privileged_accounts(): void
    {
        $this->seed();
        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $admin = User::query()->create([
            'name' => 'Admin Operasional',
            'username' => 'admin01',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->syncRoles(['Admin']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Owner Kedua',
            'username' => 'owner02',
            'password' => 'password123',
            'role' => 'Owner',
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$owner->id}", [
            'name' => 'Nama Diubah',
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/users/roles')
            ->assertOk()
            ->assertJsonMissing(['Owner'])
            ->assertJsonMissing(['Admin'])
            ->assertJsonFragment(['Kasir']);
    }

    public function test_operational_roles_cannot_access_user_management(): void
    {
        $this->seed();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();

        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_owner_cannot_deactivate_current_or_last_privileged_account(): void
    {
        $this->seed();
        $owner = User::query()->where('username', 'owner')->firstOrFail();

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/users/{$owner->id}", [
            'is_active' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/users/{$owner->id}", [
            'role' => 'Kasir',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }
}
