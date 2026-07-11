<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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

            if (!is_string($newLogoPath) || $newLogoPath === '') {
                throw ValidationException::withMessages([
                    'restaurant_logo' => ['Logo gagal disimpan. Periksa izin folder storage pada server.'],
                ]);
            }
        }

        DB::transaction(function () use ($validated, $newLogoPath): void {
            Setting::setValue('restaurant_name', trim($validated['restaurant_name']), 'restaurant');
            Setting::setValue('restaurant_address', isset($validated['restaurant_address'])
                ? trim($validated['restaurant_address'])
                : null, 'restaurant');

            if ($newLogoPath !== null) {
                Setting::setValue('restaurant_logo_path', $newLogoPath, 'restaurant');
                Setting::setValue('restaurant_logo_updated_at', now()->timestamp, 'restaurant');
            }
        });

        if ($newLogoPath !== null && is_string($previousLogo) && $previousLogo !== '' && $previousLogo !== $newLogoPath) {
            Storage::disk('public')->delete($previousLogo);
        }

        return response()->json([
            'message' => 'Profil restoran berhasil diperbarui.',
            'data' => $this->profilePayload($request),
        ]);
    }

    public function logo(): BinaryFileResponse
    {
        $logoPath = Setting::getValue('restaurant_logo_path');

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

    public static function profilePayload(?Request $request = null): array
    {
        $logoPath = Setting::getValue('restaurant_logo_path');
        $logoVersion = Setting::getValue('restaurant_logo_updated_at', '1');
        $hasLogo = is_string($logoPath) && $logoPath !== '';
        $logoUrl = null;

        if ($hasLogo) {
            $logoUrl = $request !== null
                ? $request->getSchemeAndHttpHost().'/api/v1/restaurant-profile/logo?v='.$logoVersion
                : Storage::disk('public')->url($logoPath);
        }

        return [
            'restaurant_name' => Setting::getValue('restaurant_name', config('app.name', 'Warung Babeh')),
            'restaurant_address' => Setting::getValue('restaurant_address'),
            'restaurant_logo_url' => $logoUrl,
        ];
    }
}
