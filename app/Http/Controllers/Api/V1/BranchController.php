<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BranchAuthorization;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class BranchController extends Controller
{
    public function __construct(private readonly BranchAuthorization $authorization) {}

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

    public function manageIndex(Request $request): JsonResponse
    {
        $organizationId = app(BranchContext::class)->branch()?->organization_id;

        $branches = Branch::query()
            ->where('organization_id', $organizationId)
            ->with(['users' => fn ($query) => $query->orderBy('users.name')])
            ->withCount([
                'users as active_users_count' => fn ($query) => $query->wherePivot('is_active', true),
                'menuSettings as active_menus_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                ...$this->serializeBranch($branch),
                'is_active' => $branch->is_active,
                'active_users_count' => (int) $branch->active_users_count,
                'active_menus_count' => (int) $branch->active_menus_count,
                'users' => $branch->users->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->pivot?->role_name,
                    'is_default' => (bool) ($user->pivot?->is_default ?? false),
                    'is_active' => (bool) ($user->pivot?->is_active ?? false),
                ])->values()->all(),
            ]);

        $users = User::query()
            ->whereHas('branches', fn ($query) => $query->where('organization_id', $organizationId))
            ->with('roles:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->getRoleNames()->first(),
                'is_active' => $user->is_active,
            ]);

        return response()->json([
            'data' => $branches,
            'available_users' => $users,
            'available_roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $sourceBranch = app(BranchContext::class)->branch();
        abort_if(! $sourceBranch, 409, 'Cabang aktif belum dipilih.');

        $this->normalizeBranchCode($request);

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('branches', 'code')->where('organization_id', $sourceBranch->organization_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['nullable', 'timezone'],
            'copy_menu_settings' => ['sometimes', 'boolean'],
            'copy_restaurant_settings' => ['sometimes', 'boolean'],
        ], [
            'code.alpha_dash' => 'Kode cabang hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah.',
            'code.unique' => 'Kode cabang sudah digunakan pada jaringan restoran ini.',
        ]);

        $branch = DB::transaction(function () use ($request, $sourceBranch, $validated): Branch {
            $branch = Branch::query()->create([
                'organization_id' => $sourceBranch->organization_id,
                'code' => strtoupper($validated['code']),
                'name' => $validated['name'],
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'timezone' => $validated['timezone'] ?? $sourceBranch->timezone,
                'is_active' => true,
            ]);

            $branch->users()->attach($request->user()->id, [
                'role_name' => $this->authorization->roleName($request->user(), $sourceBranch->id),
                'is_default' => false,
                'is_active' => true,
            ]);

            if ($validated['copy_menu_settings'] ?? true) {
                $this->copyMenuSettings($sourceBranch->id, $branch->id);
            }
            if ($validated['copy_restaurant_settings'] ?? true) {
                $this->copySettings($sourceBranch->id, $branch->id);
            }

            return $branch;
        });

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $this->authorization->roleName($request->user()),
            action: 'branch.created',
            entityType: 'branch',
            entityId: $branch->id,
            after: $branch->only(['organization_id', 'code', 'name', 'timezone', 'is_active']),
        );

        return response()->json([
            'message' => 'Cabang berhasil ditambahkan.',
            'data' => $this->serializeBranch($branch),
        ], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->ensureSameOrganization($branch);
        $this->normalizeBranchCode($request);
        $validated = $request->validate([
            'code' => [
                'sometimes', 'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('branches', 'code')
                    ->where('organization_id', $branch->organization_id)
                    ->ignore($branch->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.alpha_dash' => 'Kode cabang hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah.',
            'code.unique' => 'Kode cabang sudah digunakan pada jaringan restoran ini.',
        ]);

        abort_if(
            array_key_exists('is_active', $validated)
                && ! $validated['is_active']
                && app(BranchContext::class)->id() === $branch->id,
            422,
            'Cabang yang sedang aktif tidak dapat dinonaktifkan.',
        );

        $before = $branch->only(['code', 'name', 'address', 'phone', 'timezone', 'is_active']);
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        $branch->update($validated);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $this->authorization->roleName($request->user()),
            action: 'branch.updated',
            entityType: 'branch',
            entityId: $branch->id,
            before: $before,
            after: $branch->only(['code', 'name', 'address', 'phone', 'timezone', 'is_active']),
        );

        return response()->json([
            'message' => 'Cabang berhasil diperbarui.',
            'data' => $this->serializeBranch($branch),
        ]);
    }

    public function assignUser(Request $request, Branch $branch, User $user): JsonResponse
    {
        $this->ensureSameOrganization($branch);
        abort_if(! $user->is_active, 422, 'Pengguna tidak aktif tidak dapat ditempatkan di cabang.');

        $validated = $request->validate([
            'is_default' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);
        $isDefault = $validated['is_default'] ?? false;
        $roleName = $validated['role'] ?? $user->getRoleNames()->first();
        abort_if(! $roleName, 422, 'Role pengguna belum dipilih.');

        DB::transaction(function () use ($branch, $user, $isDefault, $roleName): void {
            if ($isDefault) {
                DB::table('branch_user')->where('user_id', $user->id)->update([
                    'is_default' => false,
                    'updated_at' => now(),
                ]);
            }

            $branch->users()->syncWithoutDetaching([
                $user->id => [
                    'role_name' => $roleName,
                    'is_default' => $isDefault,
                    'is_active' => true,
                ],
            ]);
        });

        return response()->json(['message' => "{$user->name} berhasil ditempatkan di {$branch->name}."]);
    }

    public function removeUser(Request $request, Branch $branch, User $user): JsonResponse
    {
        $this->ensureSameOrganization($branch);
        abort_if($request->user()->is($user) && app(BranchContext::class)->id() === $branch->id, 422, 'Akun yang sedang digunakan tidak dapat dikeluarkan dari cabang aktif.');

        $membership = $branch->users()->whereKey($user->id)->first();
        abort_if(! $membership, 404, 'Pengguna tidak terdaftar pada cabang ini.');

        $branch->users()->updateExistingPivot($user->id, [
            'is_active' => false,
            'is_default' => false,
        ]);
        $user->tokens()->where('branch_id', $branch->id)->delete();

        return response()->json(['message' => "Akses {$user->name} ke {$branch->name} berhasil dinonaktifkan."]);
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

    private function ensureSameOrganization(Branch $branch): void
    {
        abort_unless(
            $branch->organization_id === app(BranchContext::class)->branch()?->organization_id,
            404,
        );
    }

    private function normalizeBranchCode(Request $request): void
    {
        if (! $request->has('code')) {
            return;
        }

        $code = strtoupper(trim((string) $request->input('code')));
        $code = preg_replace('/[^A-Z0-9_-]+/', '-', $code) ?? '';
        $code = preg_replace('/-+/', '-', $code) ?? '';

        $request->merge(['code' => trim($code, '-')]);
    }

    private function copyMenuSettings(int $sourceBranchId, int $targetBranchId): void
    {
        $now = now();
        $rows = DB::table('branch_menus')
            ->where('branch_id', $sourceBranchId)
            ->get()
            ->map(fn (object $row): array => [
                'branch_id' => $targetBranchId,
                'menu_id' => $row->menu_id,
                'local_sku' => $row->local_sku,
                'price' => $row->price,
                'station_type' => $row->station_type,
                'is_available' => $row->is_available,
                'is_active' => $row->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('branch_menus')->insert($rows);
        }
    }

    private function copySettings(int $sourceBranchId, int $targetBranchId): void
    {
        $now = now();
        $rows = DB::table('settings')
            ->where('branch_id', $sourceBranchId)
            ->get()
            ->map(fn (object $row): array => [
                'branch_id' => $targetBranchId,
                'key' => $row->key,
                'value' => $row->value,
                'group' => $row->group,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('settings')->insert($rows);
        }
    }
}
