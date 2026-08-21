<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
