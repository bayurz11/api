<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiBranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_default_branch_and_persists_it_on_token(): void
    {
        [$user, $defaultBranch, $otherBranch] = $this->userWithBranches();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.active_branch.id', $defaultBranch->id)
            ->assertJsonCount(2, 'user.branches');

        $this->assertSame(
            $defaultBranch->id,
            $user->tokens()->latest('id')->value('branch_id'),
        );
        $this->assertNotSame($defaultBranch->id, $otherBranch->id);
    }

    public function test_user_can_only_list_and_switch_to_assigned_branches(): void
    {
        [$user, $defaultBranch, $otherBranch] = $this->userWithBranches();
        $foreignBranch = Branch::query()->create([
            'organization_id' => $defaultBranch->organization_id,
            'code' => 'ASING',
            'name' => 'Cabang Asing',
        ]);
        $token = $user->createToken('test');
        $token->accessToken->forceFill(['branch_id' => $defaultBranch->id])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonPath('active_branch_id', $defaultBranch->id)
            ->assertJsonCount(2, 'data');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/switch-branch', ['branch_id' => $otherBranch->id])
            ->assertOk()
            ->assertJsonPath('active_branch.id', $otherBranch->id);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/switch-branch', ['branch_id' => $foreignBranch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_owner_can_create_branch_copy_catalog_and_assign_user(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $cashier = User::query()->where('username', 'kasir01')->firstOrFail();
        $sourceBranch = $owner->branches()->wherePivot('is_default', true)->firstOrFail();
        $token = $owner->createToken('multi-branch-test');
        $token->accessToken->forceFill(['branch_id' => $sourceBranch->id])->save();

        $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/branches', [
            'code' => 'SELATAN',
            'name' => 'Cabang Selatan',
            'address' => 'Jakarta Selatan',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'SELATAN')
            ->assertJsonPath('data.name', 'Cabang Selatan');

        $targetBranchId = (int) $response->json('data.id');
        $this->assertSame(
            DB::table('branch_menus')->where('branch_id', $sourceBranch->id)->count(),
            DB::table('branch_menus')->where('branch_id', $targetBranchId)->count(),
        );

        $this->withToken($token->plainTextToken)
            ->putJson("/api/v1/branches/{$targetBranchId}/users/{$cashier->id}", [
                'is_default' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('branch_user', [
            'branch_id' => $targetBranchId,
            'user_id' => $cashier->id,
            'role_name' => 'Kasir',
            'is_active' => true,
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/branches/manage')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cabang Selatan'])
            ->assertJsonFragment(['username' => 'kasir01'])
            ->assertJsonFragment(['username' => 'bar01']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/switch-branch', ['branch_id' => $targetBranchId])
            ->assertOk();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/tables')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function userWithBranches(): array
    {
        $organization = Organization::query()->create([
            'code' => 'TEST-ORG',
            'name' => 'Test Organization',
        ]);
        $defaultBranch = Branch::query()->create([
            'organization_id' => $organization->id,
            'code' => 'PUSAT',
            'name' => 'Cabang Pusat',
        ]);
        $otherBranch = Branch::query()->create([
            'organization_id' => $organization->id,
            'code' => 'DUA',
            'name' => 'Cabang Dua',
        ]);
        $user = User::factory()->create([
            'username' => 'branch-owner',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->branches()->attach($defaultBranch->id, [
            'role_name' => 'Owner',
            'is_default' => true,
            'is_active' => true,
        ]);
        $user->branches()->attach($otherBranch->id, [
            'role_name' => 'Owner',
            'is_default' => false,
            'is_active' => true,
        ]);

        return [$user, $defaultBranch, $otherBranch];
    }
}
