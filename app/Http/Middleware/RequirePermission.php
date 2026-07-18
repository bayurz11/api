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
        $fallbackPermissions = match ($permission) {
            // Keep QR approval usable while older production databases are
            // being upgraded with the dedicated permission.
            'qr-orders.approve' => ['orders.create'],
            default => [],
        };

        abort_unless(
            $hasOwnerAdminFallback
                || $this->hasPermission($user, $permission)
                || collect($fallbackPermissions)->contains(
                    fn (string $fallback) => $this->hasPermission($user, $fallback),
                ),
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
