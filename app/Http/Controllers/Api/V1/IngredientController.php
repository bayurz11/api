<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Support\AuditLogger;
use App\Support\InventoryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ingredients = Ingredient::query()
            ->withCount('menus')
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
            'code' => ['required', 'string', 'max:50', 'unique:ingredients,code'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $ingredient = DB::transaction(function () use ($validated, $user) {
            $ingredient = Ingredient::query()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'unit' => $validated['unit'],
                'current_stock' => $validated['current_stock'] ?? 0,
                'minimum_stock' => $validated['minimum_stock'] ?? 0,
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
                    'reason' => 'Stok awal bahan baku.',
                    'created_by' => $user?->id,
                ]);
            }

            return $ingredient;
        });

        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.created',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            after: $ingredient->toArray(),
        );

        return response()->json([
            'message' => 'Bahan baku berhasil dibuat.',
            'data' => $ingredient->fresh()->loadCount('menus'),
        ], 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('ingredients', 'code')->ignore($ingredient->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $before = $ingredient->only(['code', 'name', 'unit', 'current_stock', 'minimum_stock', 'notes', 'is_active']);

        $ingredient->fill($validated);
        $ingredient->save();
        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.updated',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            before: $before,
            after: $ingredient->only(['code', 'name', 'unit', 'current_stock', 'minimum_stock', 'notes', 'is_active']),
        );

        return response()->json([
            'message' => 'Bahan baku berhasil diperbarui.',
            'data' => $ingredient->fresh()->loadCount('menus'),
        ]);
    }

    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        abort_if($ingredient->menus()->exists(), 422, 'Bahan baku yang sudah dipakai di resep menu tidak dapat dihapus.');

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
            'message' => 'Bahan baku berhasil dihapus.',
        ]);
    }

    public function adjustStock(Request $request, Ingredient $ingredient): JsonResponse
    {
        $validated = $request->validate([
            'qty_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $ingredient = DB::transaction(function () use ($ingredient, $validated, $user) {
            $freshIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $stockBefore = (float) $freshIngredient->current_stock;
            $delta = (float) $validated['qty_delta'];
            $stockAfter = round($stockBefore + $delta, 2);

            abort_if($stockAfter < 0, 422, 'Stok akhir tidak boleh kurang dari 0.');

            $freshIngredient->update([
                'current_stock' => $stockAfter,
            ]);

            IngredientStockMovement::query()->create([
                'ingredient_id' => $freshIngredient->id,
                'movement_type' => $delta > 0 ? 'ADJUST_IN' : 'ADJUST_OUT',
                'qty_delta' => $delta,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $validated['reason'],
                'created_by' => $user?->id,
            ]);

            return $freshIngredient;
        });

        InventoryManager::syncMenusByIngredientIds([$ingredient->id]);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'ingredient.stock_adjusted',
            entityType: 'ingredient',
            entityId: $ingredient->id,
            after: [
                'qty_delta' => $validated['qty_delta'],
                'reason' => $validated['reason'],
                'current_stock' => $ingredient->current_stock,
            ],
        );

        return response()->json([
            'message' => 'Stok bahan baku berhasil diperbarui.',
            'data' => $ingredient->fresh()->loadCount('menus'),
        ]);
    }
}
