<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\ShoppingNote;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShoppingNoteController extends Controller
{
    private const STATUSES = [
        'OPEN',
        'BOUGHT',
        'CANCELLED',
    ];

    private const SOURCES = [
        'AUTO',
        'MANUAL',
    ];

    public function index(Request $request): JsonResponse
    {
        $notes = ShoppingNote::query()
            ->with('ingredient:id,code,name,unit,current_stock,minimum_stock,purchase_price,is_active')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByRaw("case when status = 'OPEN' then 0 when status = 'BOUGHT' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $notes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_unit' => ['nullable', 'string', 'max:30'],
            'requested_qty' => ['nullable', 'numeric', 'gt:0'],
            'estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', Rule::in(self::SOURCES)],
        ]);

        $user = $request->user();
        $ingredient = null;

        if (! empty($validated['ingredient_id'])) {
            $ingredient = Ingredient::query()->find($validated['ingredient_id']);
        }

        $note = ShoppingNote::query()->create([
            'ingredient_id' => $ingredient?->id,
            'item_name' => $ingredient?->name ?? $validated['item_name'],
            'item_unit' => $ingredient?->unit ?? ($validated['item_unit'] ?? null),
            'requested_qty' => $validated['requested_qty'] ?? null,
            'current_stock' => $ingredient?->current_stock,
            'minimum_stock' => $ingredient?->minimum_stock,
            'estimated_unit_price' => $validated['estimated_unit_price'] ?? $ingredient?->purchase_price,
            'status' => 'OPEN',
            'source' => $validated['source'] ?? ($ingredient ? 'AUTO' : 'MANUAL'),
            'notes' => $validated['notes'] ?? null,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'shopping_note.created',
            entityType: 'shopping_note',
            entityId: $note->id,
            after: $note->toArray(),
        );

        return response()->json([
            'message' => 'Catatan belanja berhasil ditambahkan.',
            'data' => $note->fresh('ingredient:id,code,name,unit,current_stock,minimum_stock,purchase_price,is_active'),
        ], 201);
    }

    public function update(Request $request, ShoppingNote $shoppingNote): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => ['sometimes', 'required', 'string', 'max:255'],
            'item_unit' => ['nullable', 'string', 'max:30'],
            'requested_qty' => ['nullable', 'numeric', 'gt:0'],
            'estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(self::STATUSES)],
        ]);

        $before = $shoppingNote->toArray();

        $shoppingNote->fill($validated);
        $shoppingNote->updated_by = $request->user()->id;
        $shoppingNote->completed_at = ($validated['status'] ?? null) === 'BOUGHT'
            ? now()
            : (($validated['status'] ?? null) === 'OPEN' ? null : $shoppingNote->completed_at);
        $shoppingNote->save();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'shopping_note.updated',
            entityType: 'shopping_note',
            entityId: $shoppingNote->id,
            before: $before,
            after: $shoppingNote->toArray(),
        );

        return response()->json([
            'message' => 'Catatan belanja berhasil diperbarui.',
            'data' => $shoppingNote->fresh('ingredient:id,code,name,unit,current_stock,minimum_stock,purchase_price,is_active'),
        ]);
    }

    public function destroy(Request $request, ShoppingNote $shoppingNote): JsonResponse
    {
        $before = $shoppingNote->toArray();
        $shoppingNote->delete();

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'shopping_note.deleted',
            entityType: 'shopping_note',
            entityId: $before['id'],
            before: $before,
        );

        return response()->json([
            'message' => 'Catatan belanja berhasil dihapus.',
        ]);
    }

    public function syncLowStock(Request $request): JsonResponse
    {
        $user = $request->user();

        $createdCount = DB::transaction(function () use ($user) {
            $lowStockItems = Ingredient::query()
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->orderBy('name')
                ->get();

            $created = 0;

            foreach ($lowStockItems as $ingredient) {
                $existingOpenNote = ShoppingNote::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('status', 'OPEN')
                    ->exists();

                if ($existingOpenNote) {
                    continue;
                }

                $requestedQty = max(
                    round(((float) $ingredient->minimum_stock * 2) - (float) $ingredient->current_stock, 2),
                    1
                );

                ShoppingNote::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'item_name' => $ingredient->name,
                    'item_unit' => $ingredient->unit,
                    'requested_qty' => $requestedQty,
                    'current_stock' => $ingredient->current_stock,
                    'minimum_stock' => $ingredient->minimum_stock,
                    'estimated_unit_price' => (float) $ingredient->last_purchase_price > 0
                        ? $ingredient->last_purchase_price
                        : $ingredient->purchase_price,
                    'status' => 'OPEN',
                    'source' => 'AUTO',
                    'notes' => (float) $ingredient->current_stock <= 0
                        ? 'Stok habis, perlu dibeli kembali.'
                        : 'Stok menipis, perlu restock.',
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                $created++;
            }

            return $created;
        });

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'shopping_note.synced_low_stock',
            entityType: 'shopping_note',
            entityId: 0,
            after: ['created_count' => $createdCount],
        );

        return response()->json([
            'message' => $createdCount > 0
                ? "Berhasil menambahkan {$createdCount} catatan belanja dari stok menipis."
                : 'Tidak ada catatan belanja baru dari stok menipis.',
            'created_count' => $createdCount,
        ]);
    }
}
