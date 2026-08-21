<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Setting;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RestaurantProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profilePayload($request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'],
            'restaurant_address' => ['nullable', 'string', 'max:1000'],
            'restaurant_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $newLogoPath = null;
        $previousLogo = Setting::getValue('restaurant_logo_path');

        if ($request->hasFile('restaurant_logo')) {
            $newLogoPath = $request->file('restaurant_logo')->store('restaurant-profile', 'public');

            if (! is_string($newLogoPath) || $newLogoPath === '') {
                throw ValidationException::withMessages([
                    'restaurant_logo' => ['Logo gagal disimpan. Periksa izin folder storage pada server.'],
                ]);
            }
        }

        DB::transaction(function () use ($validated, $newLogoPath): void {
            Setting::setValue('restaurant_name', trim($validated['restaurant_name']), 'restaurant');
            $restaurantAddress = isset($validated['restaurant_address'])
                ? trim($validated['restaurant_address'])
                : null;
            Setting::setValue('restaurant_address', $restaurantAddress, 'restaurant');

            $branch = app(BranchContext::class)->branch();
            if ($branch) {
                $branch->update(['address' => $restaurantAddress]);
            }

            if ($newLogoPath !== null) {
                Setting::setValue('restaurant_logo_path', $newLogoPath, 'restaurant');
                Setting::setValue('restaurant_logo_updated_at', now()->timestamp, 'restaurant');
            }
        });

        if ($newLogoPath !== null && is_string($previousLogo) && $previousLogo !== '' && $previousLogo !== $newLogoPath) {
            $stillUsed = Setting::query()
                ->withoutGlobalScope('branch')
                ->where('key', 'restaurant_logo_path')
                ->where('value', $previousLogo)
                ->exists();
            if (! $stillUsed) {
                Storage::disk('public')->delete($previousLogo);
            }
        }

        return response()->json([
            'message' => 'Profil restoran berhasil diperbarui.',
            'data' => $this->profilePayload($request),
        ]);
    }

    public function logo(): BinaryFileResponse
    {
        $branchId = app(BranchContext::class)->id()
            ?? Branch::query()->where('is_active', true)->orderBy('id')->value('id');

        return $this->logoResponse($branchId ? (int) $branchId : null);
    }

    public function branchLogo(string $branchCode): BinaryFileResponse
    {
        $branchId = Branch::query()
            ->where('code', strtoupper($branchCode))
            ->where('is_active', true)
            ->value('id');
        abort_if(! $branchId, 404, 'Cabang tidak ditemukan.');

        return $this->logoResponse((int) $branchId);
    }

    private function logoResponse(?int $branchId): BinaryFileResponse
    {
        $logoPath = $this->settingValueForBranch($branchId, 'restaurant_logo_path');

        abort_unless(
            is_string($logoPath) && $logoPath !== '' && Storage::disk('public')->exists($logoPath),
            404,
            'Logo restoran belum tersedia.',
        );

        return response()->file(Storage::disk('public')->path($logoPath), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function profilePayload(?Request $request = null, ?int $branchId = null): array
    {
        $controller = app(self::class);
        $branch = $branchId
            ? Branch::query()->find($branchId)
            : app(BranchContext::class)->branch();
        $branchId = $branch?->id;
        $logoPath = $controller->settingValueForBranch($branchId, 'restaurant_logo_path');
        $logoVersion = $controller->settingValueForBranch($branchId, 'restaurant_logo_updated_at', '1');
        $hasLogo = is_string($logoPath) && $logoPath !== '';
        $logoUrl = null;

        if ($hasLogo) {
            $logoUrl = $request !== null
                ? $request->getSchemeAndHttpHost().'/api/v1/'.($branch
                    ? 'branches/'.$branch->code.'/restaurant-profile/logo'
                    : 'restaurant-profile/logo').'?v='.$logoVersion
                : Storage::disk('public')->url($logoPath);
        }

        return [
            'restaurant_name' => $controller->settingValueForBranch($branchId, 'restaurant_name', config('app.name', 'Warung Babeh')),
            'restaurant_address' => filled($branch?->address)
                ? $branch->address
                : $controller->settingValueForBranch($branchId, 'restaurant_address'),
            'restaurant_logo_url' => $logoUrl,
        ];
    }

    public static function logoPathForBranch(?int $branchId): ?string
    {
        $controller = app(self::class);
        $logoPath = $controller->settingValueForBranch($branchId, 'restaurant_logo_path');

        return is_string($logoPath)
            && $logoPath !== ''
            && Storage::disk('public')->exists($logoPath)
                ? Storage::disk('public')->path($logoPath)
                : null;
    }

    private function settingValueForBranch(?int $branchId, string $key, mixed $default = null): mixed
    {
        if (! $branchId) {
            return $default;
        }

        return Setting::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }
}
