<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->availableBranches($request),
            'active_branch_id' => $request->user()->currentAccessToken()?->branch_id,
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branch = $request->user()->branches()
            ->whereKey($validated['branch_id'])
            ->where('branches.is_active', true)
            ->wherePivot('is_active', true)
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang tidak tersedia untuk akun ini.'],
            ]);
        }

        $token = $request->user()->currentAccessToken();
        abort_if(! $token, 401, 'Sesi tidak ditemukan. Silakan login kembali.');
        $token->forceFill(['branch_id' => $branch->id])->save();

        return response()->json([
            'message' => "Cabang aktif diubah ke {$branch->name}.",
            'active_branch' => $this->serializeBranch($branch),
        ]);
    }

    private function availableBranches(Request $request): array
    {
        return $request->user()->branches()
            ->where('branches.is_active', true)
            ->wherePivot('is_active', true)
            ->orderByPivot('is_default', 'desc')
            ->orderBy('branches.name')
            ->get()
            ->map(fn (Branch $branch): array => $this->serializeBranch($branch))
            ->all();
    }

    private function serializeBranch(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'organization_id' => $branch->organization_id,
            'code' => $branch->code,
            'name' => $branch->name,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'timezone' => $branch->timezone,
            'role' => $branch->pivot?->role_name,
            'is_default' => (bool) ($branch->pivot?->is_default ?? false),
        ];
    }
}
