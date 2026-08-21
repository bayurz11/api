<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchAuthorization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly BranchAuthorization $authorization) {}

    /**
     * Authenticate the user and return a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()
            ->where('username', $credentials['username'])
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password tidak valid.'],
            ]);
        }

        $expirationMinutes = max((int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 10080), 1);
        $activeBranch = $this->defaultBranchFor($user);
        $newAccessToken = $user->createToken(
            'mobile-app',
            ['*'],
            now()->addMinutes($expirationMinutes),
        );
        $newAccessToken->accessToken->forceFill([
            'branch_id' => $activeBranch?->id,
        ])->save();

        return response()->json([
            'token' => $newAccessToken->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => array_values(array_filter([$this->authorization->roleName($user, $activeBranch?->id)])),
                'permissions' => $this->authorization->permissions($user, $activeBranch?->id),
                'branches' => $this->branchesFor($user),
                'active_branch' => $activeBranch ? $this->serializeBranch($activeBranch) : null,
            ],
        ]);
    }

    /**
     * Return the current authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => array_values(array_filter([$this->authorization->roleName($user)])),
                'permissions' => $this->authorization->permissions($user),
                'branches' => $this->branchesFor($user),
                'active_branch' => $this->activeBranchFor($request),
            ],
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException;
        }

        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    private function defaultBranchFor(User $user): ?Branch
    {
        return $user->branches()
            ->where('branches.is_active', true)
            ->wherePivot('is_active', true)
            ->orderByPivot('is_default', 'desc')
            ->orderBy('branches.name')
            ->first();
    }

    private function activeBranchFor(Request $request): ?array
    {
        $branchId = $request->user()->currentAccessToken()?->branch_id;
        if (! $branchId) {
            return null;
        }

        $branch = $request->user()->branches()
            ->whereKey($branchId)
            ->wherePivot('is_active', true)
            ->first();

        return $branch ? $this->serializeBranch($branch) : null;
    }

    private function branchesFor(User $user): array
    {
        return $user->branches()
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
