<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\ShoppingNote;

class ShoppingNoteManager
{
    public static function syncByIngredientIds(array $ingredientIds, ?int $userId = null): void
    {
        if ($ingredientIds === []) {
            return;
        }

        $stockItems = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->get();

        foreach ($stockItems as $stockItem) {
            self::syncSingle($stockItem, $userId);
        }
    }

    public static function syncSingle(Ingredient $stockItem, ?int $userId = null): void
    {
        $openAutoNote = ShoppingNote::query()
            ->where('ingredient_id', $stockItem->id)
            ->where('source', 'AUTO')
            ->where('status', 'OPEN')
            ->latest('id')
            ->first();

        $isLowStock = $stockItem->is_active
            && (float) $stockItem->current_stock <= (float) $stockItem->minimum_stock;

        if ($isLowStock) {
            $requestedQty = max(
                round(((float) $stockItem->minimum_stock * 2) - (float) $stockItem->current_stock, 2),
                1,
            );

            $payload = [
                'ingredient_id' => $stockItem->id,
                'item_name' => $stockItem->name,
                'item_unit' => $stockItem->unit,
                'requested_qty' => $requestedQty,
                'current_stock' => $stockItem->current_stock,
                'minimum_stock' => $stockItem->minimum_stock,
                'estimated_unit_price' => (float) $stockItem->last_purchase_price > 0
                    ? $stockItem->last_purchase_price
                    : $stockItem->purchase_price,
                'notes' => (float) $stockItem->current_stock <= 0
                    ? 'Stok habis, perlu dibeli kembali.'
                    : 'Stok menipis, perlu restock.',
                'updated_by' => $userId,
            ];

            if ($openAutoNote) {
                $openAutoNote->fill($payload);
                $openAutoNote->save();

                return;
            }

            ShoppingNote::query()->create([
                ...$payload,
                'status' => 'OPEN',
                'source' => 'AUTO',
                'created_by' => $userId,
            ]);

            return;
        }

        if ($openAutoNote) {
            $openAutoNote->update([
                'current_stock' => $stockItem->current_stock,
                'minimum_stock' => $stockItem->minimum_stock,
                'status' => 'CANCELLED',
                'notes' => 'Stok kembali aman.',
                'updated_by' => $userId,
                'completed_at' => now(),
            ]);
        }
    }
}
