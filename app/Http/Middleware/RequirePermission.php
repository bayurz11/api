<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $hasOwnerAdminFallback = in_array($permission, ['settings.view', 'settings.manage'], true)
            && $user?->hasAnyRole(['Owner', 'Admin']);
        abort_unless(
            $hasOwnerAdminFallback
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

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
