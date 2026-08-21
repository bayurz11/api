<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BranchAuthorization
{
    public function roleName(User $user, ?int $branchId = null): ?string
    {
        $branchId ??= app(BranchContext::class)->id();

        if ($branchId) {
            $roleName = DB::table('branch_user')
                ->where('branch_id', $branchId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->value('role_name');

            if (is_string($roleName) && $roleName !== '') {
                return $roleName;
            }
        }

        return $user->getRoleNames()->first();
    }

    public function permissions(User $user, ?int $branchId = null): array
    {
        $roleName = $this->roleName($user, $branchId);
        $modelType = $user->getMorphClass();

        return DB::table('permissions')
            ->where('permissions.guard_name', 'web')
            ->where(function ($query) use ($modelType, $roleName, $user): void {
                $query->whereExists(function ($directPermission) use ($modelType, $user): void {
                    $directPermission
                        ->selectRaw('1')
                        ->from('model_has_permissions')
                        ->whereColumn('model_has_permissions.permission_id', 'permissions.id')
                        ->where('model_has_permissions.model_type', $modelType)
                        ->where('model_has_permissions.model_id', $user->id);
                });

                if ($roleName) {
                    $query->orWhereExists(function ($rolePermission) use ($roleName): void {
                        $rolePermission
                            ->selectRaw('1')
                            ->from('roles')
                            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
                            ->whereColumn('role_has_permissions.permission_id', 'permissions.id')
                            ->where('roles.guard_name', 'web')
                            ->where('roles.name', $roleName);
                    });
                }
            })
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->values()
            ->all();
    }

    public function hasPermission(User $user, string $permission, ?int $branchId = null): bool
    {
        return in_array($permission, $this->permissions($user, $branchId), true);
    }
}
