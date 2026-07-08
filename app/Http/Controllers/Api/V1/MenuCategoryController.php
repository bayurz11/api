<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuCategoryController extends Controller
{
    private const STATION_TYPES = [
        'KITCHEN',
        'BAR',
    ];

    public function index(Request $request): JsonResponse
    {
        $categories = MenuCategory::query()
            ->when($request->filled('station_type'), fn ($query) => $query->where('station_type', $request->string('station_type')))
            ->when($request->boolean('active_only', false), fn ($query) => $query->where('is_active', true))
            ->withCount('menus')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:menu_categories,name'],
            'station_type' => ['required', 'string', Rule::in(self::STATION_TYPES)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = MenuCategory::query()->create([
            'name' => $validated['name'],
            'station_type' => $validated['station_type'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu_category.created',
            entityType: 'menu_category',
            entityId: $category->id,
            after: $category->toArray(),
        );

        return response()->json([
            'message' => 'Kategori menu berhasil dibuat.',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('menu_categories', 'name')->ignore($menuCategory->id)],
            'station_type' => ['sometimes', 'required', 'string', Rule::in(self::STATION_TYPES)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['station_type']) && $validated['station_type'] !== $menuCategory->station_type) {
            abort_if(
                $menuCategory->menus()->exists(),
                422,
                'Kategori yang sudah memiliki menu tidak bisa diubah station type-nya.',
            );
        }

        $before = $menuCategory->only(['name', 'station_type', 'sort_order', 'is_active']);

        $menuCategory->fill($validated);
        $menuCategory->save();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu_category.updated',
            entityType: 'menu_category',
            entityId: $menuCategory->id,
            before: $before,
            after: $menuCategory->only(['name', 'station_type', 'sort_order', 'is_active']),
        );

        return response()->json([
            'message' => 'Kategori menu berhasil diperbarui.',
            'data' => $menuCategory->fresh(),
        ]);
    }

    public function destroy(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        abort_if(
            $menuCategory->menus()->exists(),
            422,
            'Kategori menu yang masih memiliki menu tidak dapat dihapus.',
        );

        $before = $menuCategory->toArray();
        $menuCategory->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu_category.deleted',
            entityType: 'menu_category',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Kategori menu berhasil dihapus.',
        ]);
    }
}
