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

    public function test_branch_code_is_normalized_before_validation(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $sourceBranch = $owner->branches()->wherePivot('is_default', true)->firstOrFail();
        $token = $owner->createToken('branch-code-test');
        $token->accessToken->forceFill(['branch_id' => $sourceBranch->id])->save();

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/branches', [
                'code' => 'TPI-Batu 10',
                'name' => 'Cabang A',
                'copy_menu_settings' => false,
                'copy_restaurant_settings' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TPI-BATU-10');

        $this->assertDatabaseHas('branches', [
            'organization_id' => $sourceBranch->organization_id,
            'code' => 'TPI-BATU-10',
            'name' => 'Cabang A',
        ]);
    }

    public function test_role_and_permissions_follow_the_active_branch(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $defaultBranch = $owner->branches()->wherePivot('is_default', true)->firstOrFail();
        $waiterBranch = Branch::query()->create([
            'organization_id' => $defaultBranch->organization_id,
            'code' => 'ROLE-TEST',
            'name' => 'Cabang Role Test',
        ]);
        $owner->branches()->attach($waiterBranch->id, [
            'role_name' => 'Waiter',
            'is_default' => false,
            'is_active' => true,
        ]);

        $token = $owner->createToken('branch-role-test');
        $token->accessToken->forceFill(['branch_id' => $waiterBranch->id])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.roles.0', 'Waiter')
            ->assertJsonFragment(['orders.serve'])
            ->assertJsonMissing(['users.manage']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/users')
            ->assertForbidden();

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/switch-branch', ['branch_id' => $defaultBranch->id])
            ->assertOk();
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/users')
            ->assertOk();
    }

    public function test_restaurant_profile_is_isolated_per_branch(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $defaultBranch = $owner->branches()->wherePivot('is_default', true)->firstOrFail();
        $otherBranch = Branch::query()->create([
            'organization_id' => $defaultBranch->organization_id,
            'code' => 'PROFILE-TEST',
            'name' => 'Cabang Profil Test',
        ]);
        $owner->branches()->attach($otherBranch->id, [
            'role_name' => 'Owner',
            'is_default' => false,
            'is_active' => true,
        ]);
        $token = $owner->createToken('branch-profile-test');
        $token->accessToken->forceFill(['branch_id' => $otherBranch->id])->save();

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/settings/restaurant-profile', [
                'restaurant_name' => 'Warung Babeh Cabang Profil',
                'restaurant_address' => 'Alamat khusus cabang profil',
            ])
            ->assertOk()
            ->assertJsonPath('data.restaurant_name', 'Warung Babeh Cabang Profil');

        $this->assertDatabaseHas('settings', [
            'branch_id' => $otherBranch->id,
            'key' => 'restaurant_name',
            'value' => 'Warung Babeh Cabang Profil',
        ]);
        $this->assertDatabaseMissing('settings', [
            'branch_id' => $defaultBranch->id,
            'key' => 'restaurant_name',
            'value' => 'Warung Babeh Cabang Profil',
        ]);
    }

    public function test_owner_report_combines_only_branches_in_the_same_organization(): void
    {
        $this->seed();

        $owner = User::query()->where('username', 'owner')->firstOrFail();
        $defaultBranch = $owner->branches()->wherePivot('is_default', true)->firstOrFail();
        $otherBranch = Branch::query()->create([
            'organization_id' => $defaultBranch->organization_id,
            'code' => 'REPORT-TEST',
            'name' => 'Cabang Laporan Test',
        ]);
        $owner->branches()->attach($otherBranch->id, [
            'role_name' => 'Owner',
            'is_default' => false,
            'is_active' => true,
        ]);
        $foreignOrganization = Organization::query()->create([
            'code' => 'FOREIGN-ORG',
            'name' => 'Organisasi Asing',
        ]);
        $foreignBranch = Branch::query()->create([
            'organization_id' => $foreignOrganization->id,
            'code' => 'FOREIGN-BRANCH',
            'name' => 'Cabang Asing',
        ]);

        $this->recordPaidBill($defaultBranch->id, $owner->id, 'A', 100000);
        $this->recordPaidBill($otherBranch->id, $owner->id, 'B', 75000);
        $this->recordPaidBill($foreignBranch->id, $owner->id, 'C', 900000);

        $token = $owner->createToken('branch-report-test');
        $token->accessToken->forceFill(['branch_id' => $defaultBranch->id])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/reports/branch-comparison')
            ->assertOk()
            ->assertJsonPath('summary.branches_count', 2)
            ->assertJsonPath('summary.net_sales', 175000)
            ->assertJsonCount(2, 'branches')
            ->assertJsonMissing(['branch_name' => 'Cabang Asing']);
    }

    private function recordPaidBill(int $branchId, int $userId, string $suffix, int $amount): void
    {
        $billId = DB::table('bills')->insertGetId([
            'branch_id' => $branchId,
            'bill_no' => 'BILL-BRANCH-'.$suffix,
            'bill_type' => 'TAKEAWAY',
            'opened_by' => $userId,
            'cashier_id' => $userId,
            'guest_count' => 1,
            'status' => 'PAID',
            'subtotal' => $amount,
            'grand_total' => $amount,
            'paid_total' => $amount,
            'balance_due' => 0,
            'opened_at' => now(),
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'branch_id' => $branchId,
            'bill_id' => $billId,
            'payment_no' => 'PAY-BRANCH-'.$suffix,
            'payment_method' => 'CASH',
            'payment_type' => 'REGULAR',
            'amount' => $amount,
            'paid_by' => $userId,
            'paid_at' => now(),
            'status' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
