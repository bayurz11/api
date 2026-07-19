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
            'settings.view',
            'settings.manage',
            'users.view',
            'users.manage',
            'tables.view',
            'tables.update-status',
            'tables.manage',
            'menus.view',
            'menus.manage',
            'customers.view',
            'customers.manage',
            'reservations.view',
            'reservations.manage',
            'reservations.operate',
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
            'qr-orders.approve',
            'orders.update-status',
            'orders.serve',
            'payments.create',
            'payments.void',
            'prints.view',
            'prints.create',
            'prints.cancel',
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
                'settings.view',
                'tables.view',
                'tables.update-status',
                'menus.view',
                'customers.view',
                'customers.manage',
                'reservations.view',
                'reservations.manage',
                'reservations.operate',
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
                'qr-orders.approve',
                'payments.create',
                'payments.void',
                'prints.view',
                'prints.create',
                'prints.cancel',
            ],
            'Waiter' => [
                'auth.login',
                'dashboard.view',
                'settings.view',
                'tables.view',
                'tables.update-status',
                'menus.view',
                'customers.view',
                'reservations.view',
                'reservations.operate',
                'bills.view',
                'bills.create',
                'orders.create',
                'qr-orders.approve',
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
