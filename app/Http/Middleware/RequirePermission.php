<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $roleFallbacks = [
            'settings.view' => ['Owner', 'Admin'],
            'settings.manage' => ['Owner', 'Admin'],
            'reservations.operate' => ['Owner', 'Admin', 'Kasir', 'Waiter'],
        ];
        $hasRoleFallback = isset($roleFallbacks[$permission])
            && $user?->hasAnyRole($roleFallbacks[$permission]);
        abort_unless(
            $hasRoleFallback
                || $this->hasPermission($user, $permission),
            403,
            'Anda tidak memiliki hak akses untuk aksi ini.',
        );

        return $next($request);
    }

    private function hasPermission(mixed $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        $modelType = $user->getMorphClass();
        $modelId = $user->getKey();

        // Read the permission pivots directly so authorization remains
        // consistent across PHP-FPM workers with different cache lifetimes.
        return DB::table('permissions as permissions')
            ->where('permissions.name', $permission)
            ->where('permissions.guard_name', 'web')
            ->where(function ($query) use ($modelId, $modelType): void {
                $query
                    ->whereExists(function ($directPermission) use ($modelId, $modelType): void {
                        $directPermission
                            ->selectRaw('1')
                            ->from('model_has_permissions as model_permissions')
                            ->whereColumn('model_permissions.permission_id', 'permissions.id')
                            ->where('model_permissions.model_type', $modelType)
                            ->where('model_permissions.model_id', $modelId);
                    })
                    ->orWhereExists(function ($rolePermission) use ($modelId, $modelType): void {
                        $rolePermission
                            ->selectRaw('1')
                            ->from('model_has_roles as model_roles')
                            ->join(
                                'role_has_permissions as role_permissions',
                                'role_permissions.role_id',
                                '=',
                                'model_roles.role_id',
                            )
                            ->whereColumn('role_permissions.permission_id', 'permissions.id')
                            ->where('model_roles.model_type', $modelType)
                            ->where('model_roles.model_id', $modelId);
                    });
            })
            ->exists();
    }
}
