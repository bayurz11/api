<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Support\AuditLogger;
use App\Support\InventoryManager;
use App\Support\ShoppingNoteManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngredientController extends Controller
{
    private const ADJUSTMENT_TYPES = [
        'RESTOCK',
        'OPNAME_ADD',
        'OPNAME_SUBTRACT',
        'DAMAGED',
        'LOST',
        'MANUAL_ADD',
        'MANUAL_SUBTRACT',
    ];

    public function index(Request $request): JsonResponse
    {
        $ingredients = Ingredient::query()
            ->withCount(['menus', 'linkedMenus'])
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(function ($innerQuery) use ($request) {
                    $term = $request->string('search')->toString();
                    $innerQuery
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%")
                        ->orWhere('unit', 'like', "%{$term}%");
                }),
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $ingredients,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $ingredient = DB::transaction(function () use ($validated, $user) {
            $ingredient = Ingredient::query()->create([
                'code' => $this->generateNextStockCode(),
                'name' => $validated['name'],
                'unit' => $validated['unit'],
                'current_stock' => $validated['current_stock'] ?? 0,
                'minimum_stock' => $validated['minimum_stock'] ?? 0,
                'purchase_price' => $validated['purchase_price'] ?? 0,
                'last_purchase_price' => $validated['purchase_price'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if ((float) ($validated['current_stock'] ?? 0) > 0) {
                IngredientStockMovement::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'movement_type' => 'INITIAL',
                    'qty_delta' => $validated['current_stock'],
                    'stock_before' => 0,
                    'stock_after' => $validated['current_stock'],
                    'unit_cost' => (float) ($validated['purchase_price'] ?? 0) > 0 ? $validated['purchase_price'] : null,
                    'total_cost' => (float) ($validated['purchase_price'] ?? 0) > 0
                        ? round((float) $validated['current_stock'] * (float) $validated['purchase_price'], 2)
                        : null,
                    'reason' => 'Stok awal barang belanja.',
                    'created_by' => $user?->id,
                ]);
            }

            return $ingredient;
        });

        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);
        ShoppingNoteManager::syncSingle($ingredient->fresh(), $user?->id);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.created',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            after: $ingredient->toArray(),
        );

        return response()->json([
            'message' => 'Stok barang berhasil dibuat.',
            'data' => $ingredient->fresh()->loadCount(['menus', 'linkedMenus']),
        ], 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $before = $ingredient->only(['code', 'name', 'unit', 'current_stock', 'minimum_stock', 'purchase_price', 'last_purchase_price', 'notes', 'is_active']);

        if (array_key_exists('purchase_price', $validated)) {
            $validated['last_purchase_price'] = $validated['purchase_price'];
        }

        $ingredient->fill($validated);
        $ingredient->save();
        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);
        ShoppingNoteManager::syncSingle($ingredient->fresh(), $user?->id);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.updated',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            before: $before,
            after: $ingredient->only(['code', 'name', 'unit', 'current_stock', 'minimum_stock', 'purchase_price', 'last_purchase_price', 'notes', 'is_active']),
        );

        return response()->json([
            'message' => 'Stok barang berhasil diperbarui.',
            'data' => $ingredient->fresh()->loadCount(['menus', 'linkedMenus']),
        ]);
    }

    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        abort_if(
            $ingredient->menus()->exists() || $ingredient->linkedMenus()->exists(),
            422,
            'Stok barang yang sudah dipakai di menu tidak dapat dihapus.',
        );

        $before = $ingredient->toArray();
        $ingredient->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'ingredient.deleted',
            entityType: 'ingredient',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Stok barang berhasil dihapus.',
        ]);
    }

    public function adjustStock(Request $request, Ingredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'qty_delta' => ['required', 'numeric', 'not_in:0'],
            'adjustment_type' => ['required', 'string', 'in:'.implode(',', self::ADJUSTMENT_TYPES)],
            'reason' => ['nullable', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        $ingredient = DB::transaction(function () use ($ingredient, $validated, $user) {
            $freshIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $stockBefore = (float) $freshIngredient->current_stock;
            $delta = (float) $validated['qty_delta'];
            $stockAfter = round($stockBefore + $delta, 2);
            $adjustmentType = $validated['adjustment_type'];
            $resolvedReason = $validated['reason'] ?? $this->defaultReasonForAdjustmentType($adjustmentType);

            abort_if($stockAfter < 0, 422, 'Stok akhir tidak boleh kurang dari 0.');

            $freshIngredient->update([
                'current_stock' => $stockAfter,
                'last_purchase_price' => $delta > 0 && array_key_exists('unit_cost', $validated)
                    ? (float) $validated['unit_cost']
                    : $freshIngredient->last_purchase_price,
                'purchase_price' => $adjustmentType === 'RESTOCK' && array_key_exists('unit_cost', $validated)
                    ? (float) $validated['unit_cost']
                    : $freshIngredient->purchase_price,
            ]);

            $unitCost = $delta > 0
                ? (array_key_exists('unit_cost', $validated)
                    ? (float) $validated['unit_cost']
                    : ((float) $freshIngredient->purchase_price > 0
                        ? (float) $freshIngredient->purchase_price
                        : null))
                : (((float) $freshIngredient->last_purchase_price > 0
                    ? (float) $freshIngredient->last_purchase_price
                    : ((float) $freshIngredient->purchase_price > 0
                        ? (float) $freshIngredient->purchase_price
                        : null)));

            IngredientStockMovement::query()->create([
                'ingredient_id' => $freshIngredient->id,
                'movement_type' => $this->resolveMovementType($adjustmentType),
                'qty_delta' => $delta,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost === null ? null : round(abs($delta) * $unitCost, 2),
                'reason' => $resolvedReason,
                'created_by' => $user?->id,
            ]);

            return $freshIngredient;
        });

        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);
        ShoppingNoteManager::syncSingle($ingredient->fresh(), $user?->id);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.stock_adjusted',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            after: [
                'qty_delta' => $validated['qty_delta'],
                'adjustment_type' => $validated['adjustment_type'],
                'unit_cost' => $validated['unit_cost'] ?? null,
                'reason' => $validated['reason'] ?? $this->defaultReasonForAdjustmentType($validated['adjustment_type']),
                'current_stock' => $ingredient->current_stock,
            ],
        );

        return response()->json([
            'message' => 'Stok barang berhasil diperbarui.',
            'data' => $ingredient->fresh()->loadCount(['menus', 'linkedMenus']),
        ]);
    }

    public function movements(Ingredient $ingredient): JsonResponse
    {
        $movements = $ingredient->stockMovements()
            ->with('user:id,name,username')
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'data' => $movements,
        ]);
    }

    private function generateNextStockCode(): string
    {
        $maxSequence = 0;

        foreach (Ingredient::query()->pluck('code') as $code) {
            if (preg_match('/^BRG-(\d+)$/', (string) $code, $matches) === 1) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('BRG-%03d', $maxSequence + 1);
    }

    private function resolveMovementType(string $adjustmentType): string
    {
        return match ($adjustmentType) {
            'RESTOCK' => 'RESTOCK_IN',
            'OPNAME_ADD' => 'OPNAME_IN',
            'OPNAME_SUBTRACT' => 'OPNAME_OUT',
            'DAMAGED' => 'DAMAGED_OUT',
            'LOST' => 'LOST_OUT',
            'MANUAL_ADD' => 'ADJUST_IN',
            'MANUAL_SUBTRACT' => 'ADJUST_OUT',
            default => 'ADJUST_OUT',
        };
    }

    private function defaultReasonForAdjustmentType(string $adjustmentType): string
    {
        return match ($adjustmentType) {
            'RESTOCK' => 'Belanja stok masuk.',
            'OPNAME_ADD' => 'Koreksi stok hasil opname (lebih).',
            'OPNAME_SUBTRACT' => 'Koreksi stok hasil opname (kurang).',
            'DAMAGED' => 'Barang rusak.',
            'LOST' => 'Barang hilang / susut.',
            'MANUAL_ADD' => 'Penyesuaian manual tambah stok.',
            'MANUAL_SUBTRACT' => 'Penyesuaian manual kurangi stok.',
            default => 'Penyesuaian stok.',
        };
    }
}
