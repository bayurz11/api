<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Support\AuditLogger;
use App\Support\InventoryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    private const STATION_TYPES = [
        'KITCHEN',
        'BAR',
    ];

    public function index(Request $request): JsonResponse
    {
        $menus = Menu::query()
            ->with('category:id,name,station_type')
            ->withCount('recipeIngredients')
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->boolean('available_only', false), fn ($query) => $query->where('is_available', true)->where('is_stock_available', true)->where('is_active', true))
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(function ($innerQuery) use ($request) {
                    $term = $request->string('search')->toString();
                    $innerQuery
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%");
                }),
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $menus,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'sku' => ['required', 'string', 'max:50', 'unique:menus,sku'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'station_type' => ['required', 'string', Rule::in(self::STATION_TYPES)],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = $this->resolveValidCategory($validated['category_id'], $validated['station_type']);

        $menu = Menu::query()->create([
            'category_id' => $validated['category_id'],
            'sku' => $validated['sku'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'price' => $validated['price'],
            'station_type' => $validated['station_type'],
            'is_available' => $validated['is_available'] ?? true,
            'is_stock_available' => true,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        InventoryManager::syncMenuStockAvailability($menu);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu.created',
            entityType: 'menu',
            entityId: $menu->id,
            after: $menu->toArray(),
        );

        return response()->json([
            'message' => 'Menu berhasil dibuat.',
            'data' => $menu->load('category:id,name,station_type'),
        ], 201);
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', 'exists:menu_categories,id'],
            'sku' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('menus', 'sku')->ignore($menu->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'station_type' => ['sometimes', 'required', 'string', Rule::in(self::STATION_TYPES)],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resolvedCategoryId = $validated['category_id'] ?? $menu->category_id;
        $resolvedStationType = $validated['station_type'] ?? $menu->station_type;

        $this->resolveValidCategory($resolvedCategoryId, $resolvedStationType);

        $before = $menu->only(['category_id', 'sku', 'name', 'description', 'image_url', 'price', 'station_type', 'is_available', 'is_active']);

        $menu->fill($validated);
        $menu->save();
        InventoryManager::syncMenuStockAvailability($menu);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu.updated',
            entityType: 'menu',
            entityId: $menu->id,
            before: $before,
            after: $menu->only(['category_id', 'sku', 'name', 'description', 'image_url', 'price', 'station_type', 'is_available', 'is_active']),
        );

        return response()->json([
            'message' => 'Menu berhasil diperbarui.',
            'data' => $menu->fresh('category:id,name,station_type'),
        ]);
    }

    public function destroy(Request $request, Menu $menu): JsonResponse
    {
        $before = $menu->toArray();
        $menu->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu.deleted',
            entityType: 'menu',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Menu berhasil dihapus.',
        ]);
    }

    private function resolveValidCategory(int $categoryId, string $stationType): MenuCategory
    {
        $category = MenuCategory::query()->findOrFail($categoryId);

        abort_if(
            ! $category->is_active,
            422,
            'Kategori menu yang dipilih tidak aktif.',
        );

        abort_if(
            $category->station_type !== $stationType,
            422,
            'Station type menu harus sama dengan station type kategori.',
        );

        return $category;
    }
}
