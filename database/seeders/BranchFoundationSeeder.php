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
                'name' => 'Warung Babeh Cabang Utama',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('branches')
                ->where('id', $branchId)
                ->where('name', 'Cabang Utama')
                ->update([
                    'name' => 'Warung Babeh Cabang Utama',
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

        $operationalTables = [
            'customers', 'tables', 'reservations', 'printers', 'settings',
            'bills', 'bill_items', 'orders', 'order_items', 'payments', 'deposits',
            'print_jobs', 'cashier_shifts', 'audit_logs', 'qr_orders', 'qr_order_items',
            'ingredients', 'menu_ingredients', 'ingredient_stock_movements',
            'shopping_notes', 'bill_discounts',
        ];

        foreach ($operationalTables as $table) {
            if (DB::getSchemaBuilder()->hasColumn($table, 'branch_id')) {
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $branchId]);
            }
        }
    }
}
