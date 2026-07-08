<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function requirePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->hasPermissionTo($permission),
            403,
            'Anda tidak memiliki hak akses untuk aksi ini.',
        );
    }
}
