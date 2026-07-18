<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

abstract class Controller
{
    protected function requirePermission(Request $request, string $permission): void
    {
        try {
            $allowed = $request->user()?->hasPermissionTo($permission) ?? false;
        } catch (PermissionDoesNotExist) {
            $allowed = false;
        }

        abort_unless(
            $allowed,
            403,
            'Anda tidak memiliki hak akses untuk aksi ini.',
        );
    }
}
