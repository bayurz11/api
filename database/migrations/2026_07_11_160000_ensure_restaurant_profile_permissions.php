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

        $permissions = collect(['settings.view', 'settings.manage'])
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()
            ->whereIn('name', ['Owner', 'Admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->whereIn('name', ['Owner', 'Admin'])
            ->get()
            ->each(function (Role $role): void {
                $role->revokePermissionTo(['settings.view', 'settings.manage']);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
