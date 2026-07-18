<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('qr-orders.approve', 'web');

        Role::query()
            ->whereIn('name', ['Owner', 'Admin', 'Kasir', 'Waiter'])
            ->get()
            ->each(function (Role $role) use ($permission): void {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($permission = Permission::query()->where('name', 'qr-orders.approve')->where('guard_name', 'web')->first()) {
            Role::query()
                ->whereIn('name', ['Owner', 'Admin', 'Kasir', 'Waiter'])
                ->get()
                ->each(function (Role $role) use ($permission): void {
                    if ($role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
