<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantProfileController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->profilePayload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'],
            'restaurant_address' => ['nullable', 'string', 'max:1000'],
            'restaurant_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        Setting::setValue('restaurant_name', $validated['restaurant_name'], 'restaurant');
        Setting::setValue('restaurant_address', $validated['restaurant_address'] ?? null, 'restaurant');

        if ($request->hasFile('restaurant_logo')) {
            $previousLogo = Setting::getValue('restaurant_logo_path');
            if (is_string($previousLogo) && $previousLogo !== '') {
                Storage::disk('public')->delete($previousLogo);
            }

            $path = $request->file('restaurant_logo')->store('restaurant-profile', 'public');
            Setting::setValue('restaurant_logo_path', $path, 'restaurant');
        }

        return response()->json([
            'message' => 'Profil restoran berhasil diperbarui.',
            'data' => $this->profilePayload(),
        ]);
    }

    public static function profilePayload(): array
    {
        $logoPath = Setting::getValue('restaurant_logo_path');

        return [
            'restaurant_name' => Setting::getValue('restaurant_name', config('app.name', 'Warung Babeh')),
            'restaurant_address' => Setting::getValue('restaurant_address'),
            'restaurant_logo_url' => is_string($logoPath) && $logoPath !== ''
                ? Storage::disk('public')->url($logoPath)
                : null,
        ];
    }
}
