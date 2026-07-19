<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    private const ROLES = ['Owner', 'Admin', 'Kasir', 'Waiter', 'Kitchen', 'Bar'];

    private const PRIVILEGED_ROLES = ['Owner', 'Admin'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::ROLES)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles:id,name')
            ->withCount('tokens')
            ->when(isset($validated['search']), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when(isset($validated['role']), fn ($query) => $query->role($validated['role']))
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 50);

        $users->getCollection()->transform(fn (User $user) => $this->serializeUser($user));

        return response()->json($users);
    }

    public function roles(Request $request): JsonResponse
    {
        $allowed = $this->actorIsOwner($request->user())
            ? self::ROLES
            : array_values(array_diff(self::ROLES, self::PRIVILEGED_ROLES));

        return response()->json(['data' => $allowed]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->ensureActorCanAssignRole($request->user(), $validated['role']);

        $user = DB::transaction(function () use ($validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'username' => strtolower($validated['username']),
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'is_active' => $validated['is_active'] ?? true,
            ]);
            $user->syncRoles([$validated['role']]);

            return $user->load('roles:id,name');
        });

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'user.created',
            entityType: 'user',
            entityId: $user->id,
            after: $this->auditData($user),
        );

        return response()->json([
            'message' => 'Pengguna berhasil ditambahkan.',
            'data' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $user->load('roles:id,name');
        $this->ensureActorCanManageTarget($request->user(), $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(self::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['role'])) {
            $this->ensureActorCanAssignRole($request->user(), $validated['role']);
        }
        if ($request->user()->is($user) && array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.']);
        }

        $before = $this->auditData($user);
        $nextRole = $validated['role'] ?? $user->getRoleNames()->first();
        $nextActive = $validated['is_active'] ?? $user->is_active;
        $this->ensurePrivilegedAccountRemains($user, $nextRole, $nextActive);

        DB::transaction(function () use ($user, $validated) {
            $user->fill(collect($validated)->except('role')->all());
            if (isset($validated['username'])) {
                $user->username = strtolower($validated['username']);
            }
            $user->save();
            if (isset($validated['role'])) {
                $user->syncRoles([$validated['role']]);
            }
            if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
                $user->tokens()->delete();
            }
        });

        $user->load('roles:id,name');
        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'user.updated',
            entityType: 'user',
            entityId: $user->id,
            before: $before,
            after: $this->auditData($user),
        );

        return response()->json([
            'message' => 'Pengguna berhasil diperbarui.',
            'data' => $this->serializeUser($user),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $user->load('roles:id,name');
        $this->ensureActorCanManageTarget($request->user(), $user);
        $validated = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255']]);

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'user.password_reset',
            entityType: 'user',
            entityId: $user->id,
        );

        return response()->json(['message' => 'Password berhasil diubah dan seluruh sesi pengguna dicabut.']);
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $user->load('roles:id,name');
        $this->ensureActorCanManageTarget($request->user(), $user);
        abort_if($request->user()->is($user), 422, 'Gunakan logout untuk mengakhiri sesi akun yang sedang digunakan.');

        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'user.sessions_revoked',
            entityType: 'user',
            entityId: $user->id,
        );

        return response()->json(['message' => 'Seluruh sesi pengguna berhasil dicabut.']);
    }

    private function ensureActorCanManageTarget(User $actor, User $target): void
    {
        if (! $this->actorIsOwner($actor) && $target->hasAnyRole(self::PRIVILEGED_ROLES)) {
            abort(403, 'Admin hanya dapat mengelola akun operasional.');
        }
    }

    private function ensureActorCanAssignRole(User $actor, string $role): void
    {
        abort_if(! $this->actorIsOwner($actor) && in_array($role, self::PRIVILEGED_ROLES, true), 403, 'Hanya Owner yang dapat menetapkan role Owner atau Admin.');
        abort_unless(Role::query()->where('name', $role)->where('guard_name', 'web')->exists(), 422, 'Role belum tersedia.');
    }

    private function ensurePrivilegedAccountRemains(User $target, ?string $nextRole, bool $nextActive): void
    {
        if (! $target->is_active || ! $target->hasAnyRole(self::PRIVILEGED_ROLES)) {
            return;
        }
        if ($nextActive && in_array($nextRole, self::PRIVILEGED_ROLES, true)) {
            return;
        }

        $otherPrivilegedAccounts = User::query()
            ->whereKeyNot($target->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::PRIVILEGED_ROLES))
            ->exists();

        throw_if(! $otherPrivilegedAccounts, ValidationException::withMessages([
            'role' => 'Minimal satu akun Owner atau Admin aktif harus tersedia.',
        ]));
    }

    private function actorIsOwner(User $user): bool
    {
        return $user->hasRole('Owner');
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role' => $user->getRoleNames()->first(),
            'active_sessions' => (int) ($user->tokens_count ?? $user->tokens()->count()),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function auditData(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'is_active' => $user->is_active,
            'role' => $user->getRoleNames()->first(),
        ];
    }
}
