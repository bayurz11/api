<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Menu;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryManager
{
    public static function deductForMenu(
        Menu $menu,
        int $qty,
        ?int $userId,
        string $reason,
    ): void {
        $recipeItems = $menu->recipeIngredients()->get();

        if ($recipeItems->isEmpty()) {
            self::syncMenuStockAvailability($menu);

            return;
        }

        $ingredientIds = $recipeItems->pluck('id')->all();
        /** @var Collection<int, Ingredient> $ingredients */
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($recipeItems as $recipeIngredient) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($recipeIngredient->id);
            abort_if(! $ingredient || ! $ingredient->is_active, 422, "Bahan baku {$recipeIngredient->name} tidak aktif.");

            $requiredQty = round((float) $recipeIngredient->pivot->qty_per_portion * $qty, 2);
            abort_if(
                (float) $ingredient->current_stock < $requiredQty,
                422,
                "Stok bahan baku {$ingredient->name} tidak cukup untuk menu {$menu->name}.",
            );
        }

        foreach ($recipeItems as $recipeIngredient) {
            /** @var Ingredient $ingredient */
            $ingredient = $ingredients->get($recipeIngredient->id);
            $requiredQty = round((float) $recipeIngredient->pivot->qty_per_portion * $qty, 2);
            $stockBefore = (float) $ingredient->current_stock;
            $stockAfter = round($stockBefore - $requiredQty, 2);

            $ingredient->update([
                'current_stock' => $stockAfter,
            ]);

            IngredientStockMovement::query()->create([
                'ingredient_id' => $ingredient->id,
                'movement_type' => 'ORDER_OUT',
                'qty_delta' => -$requiredQty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
        }

        self::syncMenusByIngredientIds($ingredientIds);
    }

    public static function restoreForOrderItem(
        OrderItem $orderItem,
        ?int $userId,
        string $reason,
    ): void {
        if (! $orderItem->stock_deducted) {
            return;
        }

        $menu = $orderItem->menu()->first();
        if (! $menu) {
            $orderItem->update(['stock_deducted' => false]);

            return;
        }

        $recipeItems = $menu->recipeIngredients()->get();
        if ($recipeItems->isEmpty()) {
            $orderItem->update(['stock_deducted' => false]);
            self::syncMenuStockAvailability($menu);

            return;
        }

        $ingredientIds = $recipeItems->pluck('id')->all();
        /** @var Collection<int, Ingredient> $ingredients */
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($recipeItems as $recipeIngredient) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($recipeIngredient->id);
            if (! $ingredient) {
                continue;
            }

            $restoreQty = round((float) $recipeIngredient->pivot->qty_per_portion * (int) $orderItem->qty, 2);
            $stockBefore = (float) $ingredient->current_stock;
            $stockAfter = round($stockBefore + $restoreQty, 2);

            $ingredient->update([
                'current_stock' => $stockAfter,
            ]);

            IngredientStockMovement::query()->create([
                'ingredient_id' => $ingredient->id,
                'movement_type' => 'ORDER_RESTORE',
                'qty_delta' => $restoreQty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
        }

        $orderItem->update(['stock_deducted' => false]);
        self::syncMenusByIngredientIds($ingredientIds);
    }

    public static function syncMenusByIngredientIds(array $ingredientIds): void
    {
        if ($ingredientIds === []) {
            return;
        }

        $menus = Menu::query()
            ->whereHas('recipeIngredients', fn ($query) => $query->whereIn('ingredients.id', $ingredientIds))
            ->get();

        foreach ($menus as $menu) {
            self::syncMenuStockAvailability($menu);
        }
    }

    public static function syncMenuStockAvailability(Menu $menu): void
    {
        $recipeItems = $menu->recipeIngredients()->get();
        $isStockAvailable = true;

        if ($recipeItems->isNotEmpty()) {
            $ingredientStocks = Ingredient::query()
                ->whereIn('id', $recipeItems->pluck('id'))
                ->get()
                ->keyBy('id');

            foreach ($recipeItems as $recipeIngredient) {
                /** @var Ingredient|null $ingredient */
                $ingredient = $ingredientStocks->get($recipeIngredient->id);
                if (! $ingredient || ! $ingredient->is_active) {
                    $isStockAvailable = false;
                    break;
                }

                if ((float) $ingredient->current_stock < (float) $recipeIngredient->pivot->qty_per_portion) {
                    $isStockAvailable = false;
                    break;
                }
            }
        }

        $menu->forceFill([
            'is_stock_available' => $isStockAvailable,
        ])->saveQuietly();
    }
}
