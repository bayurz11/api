<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        $user->tokens()->where('name', 'mobile-app')->delete();

        $expirationMinutes = max((int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 10080), 1);
        $token = $user->createToken(
            'mobile-app',
            ['*'],
            now()->addMinutes($expirationMinutes),
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $this->permissionsFor($user),
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
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $this->permissionsFor($user),
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

    private function permissionsFor(User $user): array
    {
        $modelType = $user->getMorphClass();
        $modelId = $user->getKey();

        return DB::table('permissions')
            ->where('guard_name', 'web')
            ->where(function ($query) use ($modelId, $modelType): void {
                $query
                    ->whereExists(function ($directPermission) use ($modelId, $modelType): void {
                        $directPermission
                            ->selectRaw('1')
                            ->from('model_has_permissions')
                            ->whereColumn('model_has_permissions.permission_id', 'permissions.id')
                            ->where('model_has_permissions.model_type', $modelType)
                            ->where('model_has_permissions.model_id', $modelId);
                    })
                    ->orWhereExists(function ($rolePermission) use ($modelId, $modelType): void {
                        $rolePermission
                            ->selectRaw('1')
                            ->from('model_has_roles')
                            ->join(
                                'role_has_permissions',
                                'role_has_permissions.role_id',
                                '=',
                                'model_has_roles.role_id',
                            )
                            ->whereColumn('role_has_permissions.permission_id', 'permissions.id')
                            ->where('model_has_roles.model_type', $modelType)
                            ->where('model_has_roles.model_id', $modelId);
                    });
            })
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }
}
