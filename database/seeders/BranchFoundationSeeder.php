<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BranchFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $organizationId = DB::table('organizations')
            ->where('code', 'WARUNG-BABEH')
            ->value('id');

        if (! $organizationId) {
            $organizationId = DB::table('organizations')->insertGetId([
                'code' => 'WARUNG-BABEH',
                'name' => 'Warung Babeh',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $branchId = DB::table('branches')
            ->where('organization_id', $organizationId)
            ->where('code', 'UTAMA')
            ->value('id');

        if (! $branchId) {
            $branchId = DB::table('branches')->insertGetId([
                'organization_id' => $organizationId,
                'code' => 'UTAMA',
                'name' => 'Cabang Utama',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('users')->orderBy('id')->each(function (object $user) use ($branchId, $now): void {
            $roleName = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->id)
                ->value('roles.name');

            DB::table('branch_user')->updateOrInsert(
                ['branch_id' => $branchId, 'user_id' => $user->id],
                [
                    'role_name' => $roleName,
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });

        DB::table('menus')->orderBy('id')->each(function (object $menu) use ($branchId, $now): void {
            DB::table('branch_menus')->updateOrInsert(
                ['branch_id' => $branchId, 'menu_id' => $menu->id],
                [
                    'local_sku' => $menu->sku,
                    'price' => $menu->price,
                    'station_type' => $menu->station_type,
                    'is_available' => $menu->is_available,
                    'is_active' => $menu->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });
    }
}
