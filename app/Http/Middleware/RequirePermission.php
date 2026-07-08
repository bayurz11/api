<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(
            $request->user()?->hasPermissionTo($permission),
            403,
            'Anda tidak memiliki hak akses untuk aksi ini.',
        );

        return $next($request);
    }
}
