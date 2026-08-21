<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveBranch
{
    public function __construct(private readonly BranchContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if(! $user, 401, 'Sesi tidak ditemukan.');

        $token = $user->currentAccessToken();
        $branchId = $token?->branch_id;
        $branch = $branchId
            ? $user->branches()->whereKey($branchId)->wherePivot('is_active', true)->first()
            : $user->branches()
                ->where('branches.is_active', true)
                ->wherePivot('is_active', true)
                ->orderByPivot('is_default', 'desc')
                ->first();

        if (! $branch && Branch::query()->where('is_active', true)->count() === 1) {
            $branch = Branch::query()->where('is_active', true)->first();
            $user->branches()->syncWithoutDetaching([
                $branch->id => [
                    'role_name' => $user->getRoleNames()->first(),
                    'is_default' => true,
                    'is_active' => true,
                ],
            ]);
        }

        abort_if(! $branch, 409, 'Cabang aktif belum dipilih atau akses cabang sudah dinonaktifkan.');

        if ($token instanceof PersonalAccessToken && $token->branch_id !== $branch->id) {
            $token->forceFill(['branch_id' => $branch->id])->save();
        }

        $this->context->set($branch);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
