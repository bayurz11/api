<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Menu;
use App\Models\MenuOption;
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
        ?MenuOption $menuOption = null,
    ): void {
        $touchedIngredientIds = [];

        if ($menu->stock_item_id !== null) {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::deductLinkedStockItem($menu->stock_item_id, max((float) $menu->stock_deduction_qty, 0.01) * $qty, $menu->name, $reason, $userId),
            );
        } else {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::deductRecipeItems($menu, $qty, $reason, $userId),
            );
        }

        if ($menuOption?->stock_item_id !== null) {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::deductLinkedStockItem(
                    $menuOption->stock_item_id,
                    max((float) $menuOption->stock_deduction_qty, 0.01) * $qty,
                    "{$menu->name} - {$menuOption->name}",
                    $reason,
                    $userId,
                ),
            );
        }

        if ($touchedIngredientIds !== []) {
            self::syncMenusByIngredientIds(array_values(array_unique($touchedIngredientIds)));
            ShoppingNoteManager::syncByIngredientIds(array_values(array_unique($touchedIngredientIds)), $userId);
            return;
        }

        self::syncMenuStockAvailability($menu);
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

        $orderItem->loadMissing('billItem.menuOption');
        $menuOption = $orderItem->billItem?->menuOption;
        $touchedIngredientIds = [];

        if ($menu->stock_item_id !== null) {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::restoreLinkedStockItem(
                    $menu->stock_item_id,
                    max((float) $menu->stock_deduction_qty, 0.01) * (int) $orderItem->qty,
                    $reason,
                    $userId,
                ),
            );
        } else {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::restoreRecipeItems($menu, (int) $orderItem->qty, $reason, $userId),
            );
        }

        if ($menuOption?->stock_item_id !== null) {
            $touchedIngredientIds = array_merge(
                $touchedIngredientIds,
                self::restoreLinkedStockItem(
                    $menuOption->stock_item_id,
                    max((float) $menuOption->stock_deduction_qty, 0.01) * (int) $orderItem->qty,
                    $reason,
                    $userId,
                ),
            );
        }

        $orderItem->update(['stock_deducted' => false]);
        if ($touchedIngredientIds !== []) {
            self::syncMenusByIngredientIds(array_values(array_unique($touchedIngredientIds)));
            ShoppingNoteManager::syncByIngredientIds(array_values(array_unique($touchedIngredientIds)), $userId);
            return;
        }
        self::syncMenuStockAvailability($menu);
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
                    ->orWhereHas('recipeIngredients', fn ($innerQuery) => $innerQuery->whereIn('ingredients.id', $ingredientIds))
                    ->orWhereHas('options', fn ($innerQuery) => $innerQuery->whereIn('stock_item_id', $ingredientIds));
            })
            ->get();

        foreach ($menus as $menu) {
            self::syncMenuStockAvailability($menu);
        }
    }

    public static function syncMenuStockAvailability(Menu $menu): void
    {
        $menu->loadMissing(['options', 'recipeIngredients']);
        $recipeItems = $menu->recipeIngredients;
        $options = $menu->options;
        $ingredientIds = collect([
            $menu->stock_item_id,
            ...$recipeItems->pluck('id')->all(),
            ...$options->pluck('stock_item_id')->filter()->all(),
        ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $ingredientStocks = Ingredient::query()
            ->when(
                $ingredientIds !== [],
                fn ($query) => $query->whereIn('id', $ingredientIds),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->get()
            ->keyBy('id');

        $baseStockAvailable = true;

        if ($menu->stock_item_id !== null) {
            $stockItem = $ingredientStocks->get((int) $menu->stock_item_id);
            $requiredQty = max((float) $menu->stock_deduction_qty, 0.01);
            $baseStockAvailable = $stockItem !== null
                && $stockItem->is_active
                && (float) $stockItem->current_stock >= $requiredQty;
        } else {
            foreach ($recipeItems as $recipeIngredient) {
                $ingredient = $ingredientStocks->get($recipeIngredient->id);
                if (! $ingredient || ! $ingredient->is_active || (float) $ingredient->current_stock < (float) $recipeIngredient->pivot->qty_per_portion) {
                    $baseStockAvailable = false;
                    break;
                }
            }
        }

        foreach ($options as $option) {
            $optionStockAvailable = true;
            if ($option->stock_item_id !== null) {
                $stockItem = $ingredientStocks->get((int) $option->stock_item_id);
                $requiredQty = max((float) $option->stock_deduction_qty, 0.01);
                $optionStockAvailable = $stockItem !== null
                    && $stockItem->is_active
                    && (float) $stockItem->current_stock >= $requiredQty;
            }

            $option->forceFill([
                'is_stock_available' => $optionStockAvailable,
            ])->saveQuietly();
        }

        $hasOrderableOption = $options->isEmpty()
            ? true
            : $options->contains(
                fn (MenuOption $option) => $option->is_active
                    && $option->is_available
                    && $option->is_stock_available,
            );

        $menu->forceFill([
            'is_stock_available' => $baseStockAvailable && $hasOrderableOption,
        ])->saveQuietly();
    }

    private static function deductLinkedStockItem(
        int $stockItemId,
        float $requiredQty,
        string $menuName,
        string $reason,
        ?int $userId,
    ): array {
        /** @var Ingredient|null $stockItem */
        $stockItem = Ingredient::query()
            ->lockForUpdate()
            ->find($stockItemId);

        abort_if(! $stockItem || ! $stockItem->is_active, 422, "Stok barang untuk menu {$menuName} belum aktif.");
        abort_if(
            (float) $stockItem->current_stock < $requiredQty,
            422,
            "Stok barang {$stockItem->name} tidak cukup untuk menu {$menuName}.",
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

        return [$stockItem->id];
    }

    private static function restoreLinkedStockItem(
        int $stockItemId,
        float $restoreQty,
        string $reason,
        ?int $userId,
    ): array {
        /** @var Ingredient|null $stockItem */
        $stockItem = Ingredient::query()
            ->lockForUpdate()
            ->find($stockItemId);

        if (! $stockItem) {
            return [];
        }

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

        return [$stockItem->id];
    }

    private static function deductRecipeItems(
        Menu $menu,
        int $qty,
        string $reason,
        ?int $userId,
    ): array {
        $recipeItems = $menu->recipeIngredients()->get();

        if ($recipeItems->isEmpty()) {
            return [];
        }

        $ingredientIds = $recipeItems->pluck('id')->all();
        /** @var Collection<int, Ingredient> $ingredients */
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($recipeItems as $recipeIngredient) {
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

        return $ingredientIds;
    }

    private static function restoreRecipeItems(
        Menu $menu,
        int $qty,
        string $reason,
        ?int $userId,
    ): array {
        $recipeItems = $menu->recipeIngredients()->get();
        if ($recipeItems->isEmpty()) {
            return [];
        }

        $ingredientIds = $recipeItems->pluck('id')->all();
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($recipeItems as $recipeIngredient) {
            $ingredient = $ingredients->get($recipeIngredient->id);
            if (! $ingredient) {
                continue;
            }

            $restoreQty = round((float) $recipeIngredient->pivot->qty_per_portion * $qty, 2);
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

        return $ingredientIds;
    }
}
