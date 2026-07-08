<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'auth.login',
            'dashboard.view',
            'tables.view',
            'tables.manage',
            'menus.view',
            'menus.manage',
            'customers.view',
            'customers.manage',
            'reservations.view',
            'reservations.manage',
            'deposits.manage',
            'bills.view',
            'bills.create',
            'bills.manage',
            'bills.transfer',
            'bills.merge',
            'bills.split',
            'bills.reopen',
            'bills.void',
            'bills.refund',
            'orders.create',
            'orders.update-status',
            'orders.serve',
            'payments.create',
            'payments.void',
            'prints.view',
            'prints.create',
            'reports.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roles = [
            'Owner' => $permissions,
            'Admin' => $permissions,
            'Kasir' => [
                'auth.login',
                'dashboard.view',
                'tables.view',
                'menus.view',
                'customers.view',
                'customers.manage',
                'reservations.view',
                'reservations.manage',
                'deposits.manage',
                'bills.view',
                'bills.create',
                'bills.manage',
                'bills.transfer',
                'bills.merge',
                'bills.split',
                'bills.reopen',
                'bills.void',
                'bills.refund',
                'orders.create',
                'payments.create',
                'payments.void',
                'prints.view',
                'prints.create',
            ],
            'Waiter' => [
                'auth.login',
                'dashboard.view',
                'tables.view',
                'menus.view',
                'customers.view',
                'reservations.view',
                'bills.view',
                'bills.create',
                'orders.create',
                'orders.serve',
                'prints.create',
            ],
            'Kitchen' => [
                'auth.login',
                'dashboard.view',
                'orders.update-status',
            ],
            'Bar' => [
                'auth.login',
                'dashboard.view',
                'orders.update-status',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }
    }
}
