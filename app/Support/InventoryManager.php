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
        if ($menu->stock_item_id !== null) {
            self::deductLinkedStockItem($menu, $qty, $userId, $reason);

            return;
        }

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
        ShoppingNoteManager::syncByIngredientIds($ingredientIds, $userId);
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

        if ($menu->stock_item_id !== null) {
            self::restoreLinkedStockItem($menu, (int) $orderItem->qty, $userId, $reason);
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
        ShoppingNoteManager::syncByIngredientIds($ingredientIds, $userId);
    }

    public static function syncMenusByIngredientIds(array $ingredientIds): void
    {
        if ($ingredientIds === []) {
            return;
        }

        $menus = Menu::query()
            ->where(function ($query) use ($ingredientIds) {
                $query
                    ->whereIn('stock_item_id', $ingredientIds)
                    ->orWhereHas('recipeIngredients', fn ($innerQuery) => $innerQuery->whereIn('ingredients.id', $ingredientIds));
            })
            ->get();

        foreach ($menus as $menu) {
            self::syncMenuStockAvailability($menu);
        }
    }

    public static function syncMenuStockAvailability(Menu $menu): void
    {
        if ($menu->stock_item_id !== null) {
            $stockItem = Ingredient::query()->find($menu->stock_item_id);
            $requiredQty = max((float) $menu->stock_deduction_qty, 0.01);
            $isStockAvailable = $stockItem !== null
                && $stockItem->is_active
                && (float) $stockItem->current_stock >= $requiredQty;

            $menu->forceFill([
                'is_stock_available' => $isStockAvailable,
            ])->saveQuietly();

            return;
        }

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

    private static function deductLinkedStockItem(
        Menu $menu,
        int $qty,
        ?int $userId,
        string $reason,
    ): void {
        /** @var Ingredient|null $stockItem */
        $stockItem = Ingredient::query()
            ->lockForUpdate()
            ->find($menu->stock_item_id);

        abort_if(! $stockItem || ! $stockItem->is_active, 422, "Stok barang untuk menu {$menu->name} belum aktif.");

        $requiredQty = round(max((float) $menu->stock_deduction_qty, 0.01) * $qty, 2);
        abort_if(
            (float) $stockItem->current_stock < $requiredQty,
            422,
            "Stok barang {$stockItem->name} tidak cukup untuk menu {$menu->name}.",
        );

        $stockBefore = (float) $stockItem->current_stock;
        $stockAfter = round($stockBefore - $requiredQty, 2);

        $stockItem->update([
            'current_stock' => $stockAfter,
        ]);

        $unitCost = (float) $stockItem->last_purchase_price > 0
            ? (float) $stockItem->last_purchase_price
            : (float) $stockItem->purchase_price;

        IngredientStockMovement::query()->create([
            'ingredient_id' => $stockItem->id,
            'movement_type' => 'ORDER_OUT',
            'qty_delta' => -$requiredQty,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'unit_cost' => $unitCost > 0 ? $unitCost : null,
            'total_cost' => $unitCost > 0 ? round($requiredQty * $unitCost, 2) : null,
            'reason' => $reason,
            'created_by' => $userId,
        ]);

        self::syncMenusByIngredientIds([$stockItem->id]);
        ShoppingNoteManager::syncByIngredientIds([$stockItem->id], $userId);
    }

    private static function restoreLinkedStockItem(
        Menu $menu,
        int $qty,
        ?int $userId,
        string $reason,
    ): void {
        /** @var Ingredient|null $stockItem */
        $stockItem = Ingredient::query()
            ->lockForUpdate()
            ->find($menu->stock_item_id);

        if (! $stockItem) {
            self::syncMenuStockAvailability($menu);

            return;
        }

        $restoreQty = round(max((float) $menu->stock_deduction_qty, 0.01) * $qty, 2);
        $stockBefore = (float) $stockItem->current_stock;
        $stockAfter = round($stockBefore + $restoreQty, 2);

        $stockItem->update([
            'current_stock' => $stockAfter,
        ]);

        $unitCost = (float) $stockItem->last_purchase_price > 0
            ? (float) $stockItem->last_purchase_price
            : (float) $stockItem->purchase_price;

        IngredientStockMovement::query()->create([
            'ingredient_id' => $stockItem->id,
            'movement_type' => 'ORDER_RESTORE',
            'qty_delta' => $restoreQty,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'unit_cost' => $unitCost > 0 ? $unitCost : null,
            'total_cost' => $unitCost > 0 ? round($restoreQty * $unitCost, 2) : null,
            'reason' => $reason,
            'created_by' => $userId,
        ]);

        self::syncMenusByIngredientIds([$stockItem->id]);
        ShoppingNoteManager::syncByIngredientIds([$stockItem->id], $userId);
    }
}
