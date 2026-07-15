<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuOption;
use App\Support\AuditLogger;
use App\Support\InventoryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MenuController extends Controller
{
    private const STATION_TYPES = [
        'KITCHEN',
        'BAR',
    ];

    public function index(Request $request): JsonResponse
    {
        $menus = Menu::query()
            ->with([
                'category:id,name,station_type',
                'stockItem:id,code,name,unit,current_stock,is_active',
                'options:id,menu_id,name,price_delta,is_available,is_active,sort_order',
            ])
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'station_type' => ['required', 'string', Rule::in(self::STATION_TYPES)],
            'stock_item_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'stock_deduction_qty' => ['nullable', 'numeric', 'gt:0'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.price_delta' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_available' => ['nullable', 'boolean'],
            'options.*.is_active' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = $this->resolveValidCategory($validated['category_id'], $validated['station_type']);

        try {
            $menu = DB::transaction(function () use ($validated, $category) {
                $menu = Menu::query()->create([
                    'category_id' => $validated['category_id'],
                    'stock_item_id' => $validated['stock_item_id'] ?? null,
                    'stock_deduction_qty' => $validated['stock_deduction_qty'] ?? 1,
                    'sku' => $this->generateSkuForCategory($category),
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'image_url' => $validated['image_url'] ?? null,
                    'price' => $validated['price'],
                    'station_type' => $validated['station_type'],
                    'is_available' => $validated['is_available'] ?? true,
                    'is_stock_available' => true,
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                $this->syncMenuOptions($menu, $validated['options'] ?? []);

                return $menu;
            });
        } catch (Throwable $exception) {
            return $this->handleWriteFailure($exception, $request, null, $validated);
        }

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
            'data' => $menu->load([
                'category:id,name,station_type',
                'stockItem:id,code,name,unit,current_stock,is_active',
                'options:id,menu_id,name,price_delta,is_available,is_active,sort_order',
            ]),
        ], 201);
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', 'exists:menu_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'station_type' => ['sometimes', 'required', 'string', Rule::in(self::STATION_TYPES)],
            'stock_item_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'stock_deduction_qty' => ['nullable', 'numeric', 'gt:0'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer', 'exists:menu_options,id'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.price_delta' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_available' => ['nullable', 'boolean'],
            'options.*.is_active' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $resolvedCategoryId = $validated['category_id'] ?? $menu->category_id;
        $resolvedStationType = $validated['station_type'] ?? $menu->station_type;

        $this->resolveValidCategory($resolvedCategoryId, $resolvedStationType);

        $before = $menu->only(['category_id', 'stock_item_id', 'stock_deduction_qty', 'sku', 'name', 'description', 'image_url', 'price', 'station_type', 'is_available', 'is_active']);

        try {
            DB::transaction(function () use ($menu, $validated, $resolvedCategoryId) {
                $menu->fill($validated);

                if (array_key_exists('category_id', $validated) && $resolvedCategoryId !== $menu->getOriginal('category_id')) {
                    $category = MenuCategory::query()->findOrFail($resolvedCategoryId);
                    $menu->sku = $this->generateSkuForCategory($category, $menu->id);
                }

                $menu->save();
                if (array_key_exists('options', $validated)) {
                    $this->syncMenuOptions($menu, $validated['options'] ?? []);
                }
            });
        } catch (Throwable $exception) {
            return $this->handleWriteFailure($exception, $request, $menu->id, $validated);
        }

        InventoryManager::syncMenuStockAvailability($menu);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu.updated',
            entityType: 'menu',
            entityId: $menu->id,
            before: $before,
            after: $menu->only(['category_id', 'stock_item_id', 'stock_deduction_qty', 'sku', 'name', 'description', 'image_url', 'price', 'station_type', 'is_available', 'is_active']),
        );

        return response()->json([
            'message' => 'Menu berhasil diperbarui.',
            'data' => $menu->fresh([
                'category:id,name,station_type',
                'stockItem:id,code,name,unit,current_stock,is_active',
                'options:id,menu_id,name,price_delta,is_available,is_active,sort_order',
            ]),
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

    private function generateSkuForCategory(MenuCategory $category, ?int $ignoreMenuId = null): string
    {
        $prefix = $this->resolveSkuPrefix($category->name);
        $skus = Menu::query()
            ->when($ignoreMenuId !== null, fn ($query) => $query->whereKeyNot($ignoreMenuId))
            ->where('sku', 'like', "{$prefix}-%")
            ->pluck('sku');

        $maxSequence = 0;

        foreach ($skus as $sku) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $sku, $matches) === 1) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $maxSequence + 1);
    }

    private function resolveSkuPrefix(string $categoryName): string
    {
        $normalized = Str::upper(trim(Str::ascii($categoryName)));

        if (str_contains($normalized, 'MAKAN')) {
            return 'MKN';
        }

        if (str_contains($normalized, 'MINUM')) {
            return 'MNM';
        }

        $letters = preg_replace('/[^A-Z]/', '', $normalized) ?? '';
        $consonants = preg_replace('/[AIUEO]/', '', $letters) ?? '';
        $prefix = Str::substr($consonants.$letters, 0, 3);

        return str_pad($prefix, 3, 'X');
    }

    private function syncMenuOptions(Menu $menu, array $options): void
    {
        $existingIds = $menu->options()->pluck('id')->all();
        $keptIds = [];

        foreach ($options as $index => $optionPayload) {
            $optionId = isset($optionPayload['id']) ? (int) $optionPayload['id'] : null;
            $attributes = [
                'name' => $optionPayload['name'],
                'price_delta' => $optionPayload['price_delta'] ?? 0,
                'is_available' => $optionPayload['is_available'] ?? true,
                'is_active' => $optionPayload['is_active'] ?? true,
                'sort_order' => $optionPayload['sort_order'] ?? $index,
            ];

            if ($optionId !== null) {
                $option = $menu->options()->whereKey($optionId)->firstOrFail();
                $option->update($attributes);
                $keptIds[] = $option->id;
                continue;
            }

            $keptIds[] = $menu->options()->create($attributes)->id;
        }

        $deleteIds = array_diff($existingIds, $keptIds);
        if ($deleteIds !== []) {
            MenuOption::query()->whereIn('id', $deleteIds)->delete();
        }
    }

    private function handleWriteFailure(Throwable $exception, Request $request, ?int $menuId, array $payload): JsonResponse
    {
        Log::error('menu.write_failed', [
            'menu_id' => $menuId,
            'user_id' => $request->user()?->id,
            'path' => $request->path(),
            'message' => $exception->getMessage(),
            'payload' => $payload,
        ]);

        $message = 'Terjadi kesalahan pada server.';
        $loweredMessage = Str::lower($exception->getMessage());

        if (
            str_contains($loweredMessage, 'menu_options')
            || str_contains($loweredMessage, 'menu_option_id')
            || str_contains($loweredMessage, 'base table or view not found')
            || str_contains($loweredMessage, 'unknown column')
        ) {
            $message = 'Struktur database di server belum diperbarui. Jalankan migrasi deploy terbaru.';
        }

        return response()->json([
            'message' => $message,
        ], 500);
    }
}
