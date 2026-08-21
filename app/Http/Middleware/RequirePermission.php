<?php

namespace App\Http\Middleware;

use App\Support\BranchAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly BranchAuthorization $authorization) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $roleFallbacks = [
            'settings.view' => ['Owner', 'Admin'],
            'settings.manage' => ['Owner', 'Admin'],
            'reservations.operate' => ['Owner', 'Admin', 'Kasir', 'Waiter'],
        ];
        $roleName = $user ? $this->authorization->roleName($user) : null;
        $hasRoleFallback = isset($roleFallbacks[$permission])
            && in_array($roleName, $roleFallbacks[$permission], true);
        abort_unless(
            $hasRoleFallback
                || ($user && $this->authorization->hasPermission($user, $permission)),
            403,
            'Anda tidak memiliki hak akses untuk aksi ini.',
        );

        return $next($request);
    }
}
