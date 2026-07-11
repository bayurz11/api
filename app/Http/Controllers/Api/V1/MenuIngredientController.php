<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Support\AuditLogger;
use App\Support\InventoryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuIngredientController extends Controller
{
    public function index(Menu $menu): JsonResponse
    {
        $menu->load([
            'recipeIngredients' => fn ($query) => $query->orderBy('ingredients.name'),
        ]);

        return response()->json([
            'data' => [
                'menu' => [
                    'id' => $menu->id,
                    'sku' => $menu->sku,
                    'name' => $menu->name,
                    'station_type' => $menu->station_type,
                ],
                'ingredients' => $menu->recipeIngredients->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'code' => $ingredient->code,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'current_stock' => $ingredient->current_stock,
                    'minimum_stock' => $ingredient->minimum_stock,
                    'qty_per_portion' => $ingredient->pivot->qty_per_portion,
                ])->values(),
            ],
        ]);
    }

    public function sync(Request $request, Menu $menu): JsonResponse
    {
        $validated = $request->validate([
            'ingredients' => ['present', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'ingredients.*.qty_per_portion' => ['required', 'numeric', 'gt:0'],
        ]);

        $syncPayload = collect($validated['ingredients'])
            ->mapWithKeys(fn (array $row) => [
                (int) $row['ingredient_id'] => [
                    'qty_per_portion' => $row['qty_per_portion'],
                ],
            ])
            ->all();

        $before = $menu->recipeIngredients()
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'ingredient_id' => $ingredient->id,
                'qty_per_portion' => (float) $ingredient->pivot->qty_per_portion,
            ])
            ->values()
            ->all();

        $menu->recipeIngredients()->sync($syncPayload);
        $menu->load([
            'recipeIngredients' => fn ($query) => $query->orderBy('ingredients.name'),
        ]);
        InventoryManager::syncMenuStockAvailability($menu);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'menu.recipe_synced',
            entityType: 'menu',
            entityId: $menu->id,
            before: ['ingredients' => $before],
            after: ['ingredients' => collect($validated['ingredients'])->values()],
        );

        return response()->json([
            'message' => 'Resep menu berhasil diperbarui.',
            'data' => [
                'menu' => [
                    'id' => $menu->id,
                    'sku' => $menu->sku,
                    'name' => $menu->name,
                    'station_type' => $menu->station_type,
                ],
                'ingredients' => $menu->recipeIngredients->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'code' => $ingredient->code,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'current_stock' => $ingredient->current_stock,
                    'minimum_stock' => $ingredient->minimum_stock,
                    'qty_per_portion' => $ingredient->pivot->qty_per_portion,
                ])->values(),
            ],
        ]);
    }
}
