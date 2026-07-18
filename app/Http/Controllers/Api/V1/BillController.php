<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Table;
use App\Support\AuditLogger;
use App\Support\BillOrderState;
use App\Support\BillTableManager;
use App\Support\BillTotals;
use App\Support\InventoryManager;
use App\Support\SequenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillController extends Controller
{
    private const BILL_TYPES = [
        'DINE_IN',
        'TAKE_AWAY',
        'CATERING',
        'WALK_IN',
        'DELIVERY',
        'CUSTOMER',
        'RESERVATION',
        'SPLIT',
    ];

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $bills = Bill::query()
            ->with(['table:id,code,name,status', 'tables:id,code,name,status,capacity,area', 'customer:id,name,member_code,phone'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('table_id'), function ($query) use ($request) {
                $tableId = $request->integer('table_id');

                $query->where(function ($billQuery) use ($tableId) {
                    $billQuery
                        ->where('table_id', $tableId)
                        ->orWhereHas('tables', fn ($tableQuery) => $tableQuery->where('tables.id', $tableId));
                });
            })
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('bill_type'), fn ($query) => $query->where('bill_type', $request->string('bill_type')))
            ->latest('id')
            ->paginate($perPage);

        return response()->json($bills);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_type' => ['required', 'string', Rule::in(self::BILL_TYPES)],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'extra_table_ids' => ['nullable', 'array'],
            'extra_table_ids.*' => ['required', 'integer', 'distinct', 'exists:tables,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'event_scheduled_at' => ['nullable', 'date'],
        ]);

        $this->validateBillCreationRules($validated);

        $user = $request->user();

        $bill = DB::transaction(function () use ($validated, $user) {
            $linkedTableIds = BillTableManager::normalizeTableIds(
                $validated['table_id'] ?? null,
                $validated['extra_table_ids'] ?? [],
            );

            if ($linkedTableIds !== []) {
                $openBillExists = BillTableManager::activeBillExistsOnAnyTable($linkedTableIds);

                abort_if($openBillExists, 422, 'Salah satu meja sudah memiliki bill aktif.');
            }

            $bill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => $validated['bill_type'],
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'] ?? null,
                'opened_by' => $user->id,
                'cashier_id' => $user->id,
                'guest_count' => $validated['guest_count'] ?? 1,
                'status' => 'OPEN',
                'opened_at' => now(),
                'event_scheduled_at' => $validated['event_scheduled_at'] ?? null,
            ]);

            if ($linkedTableIds !== []) {
                BillTableManager::syncBillTables($bill, $linkedTableIds);
                BillTableManager::updateTablesStatus($linkedTableIds, 'OPEN_BILL');
            }

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.created',
                entityType: 'bill',
                entityId: $bill->id,
                after: $bill->toArray(),
            );

            return $bill;
        });

        return response()->json([
            'message' => 'Bill berhasil dibuat.',
            'data' => $bill->load(['table:id,code,name,status', 'tables:id,code,name,status,capacity,area', 'customer:id,name,member_code,phone']),
        ], 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->load([
            'table:id,code,name,status',
            'tables:id,code,name,status,capacity,area',
            'customer:id,name,member_code,phone,email',
            'items.menu.category:id,name,station_type',
            'orders.items',
            'payments',
        ]);

        $payload = $bill->toArray();
        $payload['items'] = $bill->items
            ->map(function (BillItem $item): array {
                $categoryName = $item->menu?->category?->name;
                $stationType = $item->menu?->station_type;

                if ($categoryName === null || $categoryName === '') {
                    $categoryName = match ($stationType) {
                        'KITCHEN' => 'Makanan',
                        'BAR' => 'Minuman',
                        default => 'Lainnya',
                    };
                }

                return array_merge($item->toArray(), [
                    'category_name' => $categoryName,
                    'station_type' => $stationType,
                ]);
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill tidak bisa diubah.');

        $validated = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'service_total' => ['nullable', 'numeric', 'min:0'],
            'event_scheduled_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $before = $bill->only([
            'guest_count',
            'customer_id',
            'customer_name',
            'discount_total',
            'tax_total',
            'service_total',
            'grand_total',
            'balance_due',
            'event_scheduled_at',
        ]);

        $bill = DB::transaction(function () use ($bill, $validated) {
            $bill->fill([
                'guest_count' => $validated['guest_count'] ?? $bill->guest_count,
                'customer_id' => array_key_exists('customer_id', $validated) ? $validated['customer_id'] : $bill->customer_id,
                'customer_name' => array_key_exists('customer_name', $validated) ? $validated['customer_name'] : $bill->customer_name,
                'discount_total' => $validated['discount_total'] ?? $bill->discount_total,
                'tax_total' => $validated['tax_total'] ?? $bill->tax_total,
                'service_total' => $validated['service_total'] ?? $bill->service_total,
                'event_scheduled_at' => array_key_exists('event_scheduled_at', $validated)
                    ? $validated['event_scheduled_at']
                    : $bill->event_scheduled_at,
            ]);
            $bill->save();

            return BillTotals::recalculate($bill);
        });

        AuditLogger::log(
            userId: $user->id,
            roleName: $user->getRoleNames()->first(),
            action: 'bill.updated',
            entityType: 'bill',
            entityId: $bill->id,
            before: $before,
            after: $bill->only([
                'guest_count',
                'customer_id',
                'customer_name',
                'discount_total',
                'tax_total',
                'service_total',
                'grand_total',
                'balance_due',
                'event_scheduled_at',
            ]),
        );

        return response()->json([
            'message' => 'Bill berhasil diperbarui.',
            'data' => $bill->load(['table:id,code,name,status', 'tables:id,code,name,status,capacity,area', 'customer:id,name,member_code']),
        ]);
    }

    public function transferTable(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['required', 'integer', 'exists:tables,id'],
        ]);

        abort_if(! $bill->table_id, 422, 'Bill ini tidak terhubung ke meja.');
        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill tidak bisa dipindahkan.');
        abort_if($bill->table_id === $validated['table_id'], 422, 'Meja tujuan sama dengan meja aktif.');

        $user = $request->user();

        DB::transaction(function () use ($bill, $validated, $user) {
            $targetHasOpenBill = BillTableManager::activeBillExistsOnAnyTable(
                [(int) $validated['table_id']],
                $bill->id,
            );

            abort_if($targetHasOpenBill, 422, 'Meja tujuan masih memiliki bill aktif.');

            $previousTableId = $bill->table_id;
            $currentTableIds = BillTableManager::tableIdsForBill($bill);
            $updatedTableIds = collect($currentTableIds)
                ->reject(fn ($tableId) => $tableId === (int) $previousTableId)
                ->push((int) $validated['table_id'])
                ->unique()
                ->values()
                ->all();

            $bill->update(['table_id' => $validated['table_id']]);
            BillTableManager::syncBillTables($bill, $updatedTableIds);

            Table::query()->whereKey($previousTableId)->update(['status' => 'AVAILABLE']);
            BillTableManager::updateTablesStatus($updatedTableIds, 'OPEN_BILL');

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.table_transferred',
                entityType: 'bill',
                entityId: $bill->id,
                before: ['table_id' => $previousTableId],
                after: ['table_id' => $validated['table_id']],
            );
        });

        return response()->json([
            'message' => 'Meja bill berhasil dipindahkan.',
            'data' => $bill->fresh(['table', 'tables']),
        ]);
    }

    public function merge(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'target_bill_id' => ['required', 'integer', 'exists:bills,id'],
        ]);

        abort_if($bill->id === (int) $validated['target_bill_id'], 422, 'Bill sumber dan tujuan tidak boleh sama.');
        abort_if(in_array($bill->status, ['CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill sumber tidak bisa digabung.');

        $targetBill = Bill::query()->findOrFail($validated['target_bill_id']);
        abort_if(in_array($targetBill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill tujuan tidak bisa menerima merge.');

        $user = $request->user();

        $targetBill = DB::transaction(function () use ($bill, $targetBill, $user) {
            $sourceTableIds = BillTableManager::tableIdsForBill($bill);
            $targetTableIds = BillTableManager::tableIdsForBill($targetBill);
            BillItem::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Order::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Payment::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Deposit::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);

            $targetBill->update([
                'customer_id' => $targetBill->customer_id ?? $bill->customer_id,
                'customer_name' => $targetBill->customer_name ?: $bill->customer_name,
                'guest_count' => max((int) $targetBill->guest_count, 1) + max((int) $bill->guest_count, 0),
                'discount_total' => (float) $targetBill->discount_total + (float) $bill->discount_total,
                'tax_total' => (float) $targetBill->tax_total + (float) $bill->tax_total,
                'service_total' => (float) $targetBill->service_total + (float) $bill->service_total,
            ]);

            $targetBill = BillTotals::recalculate($targetBill);

            $bill->update([
                'status' => 'CANCELLED',
                'subtotal' => 0,
                'discount_total' => 0,
                'tax_total' => 0,
                'service_total' => 0,
                'grand_total' => 0,
                'paid_total' => 0,
                'balance_due' => 0,
                'closed_at' => now(),
            ]);

            $mergedTableIds = collect([...$targetTableIds, ...$sourceTableIds])
                ->unique()
                ->values()
                ->all();
            BillTableManager::syncBillTables($targetBill, $mergedTableIds);
            BillTableManager::syncBillTables($bill, []);
            BillTableManager::updateTablesStatus($mergedTableIds, 'OPEN_BILL');

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.merged',
                entityType: 'bill',
                entityId: $bill->id,
                before: ['target_bill_id' => null],
                after: ['target_bill_id' => $targetBill->id],
            );

            return $targetBill->fresh(['table', 'tables', 'customer']);
        });

        return response()->json([
            'message' => 'Bill berhasil digabung.',
            'data' => $targetBill,
        ]);
    }

    public function split(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['nullable', 'array', 'min:1', 'max:100'],
            'items.*.bill_item_id' => ['required', 'integer', 'exists:bill_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100'],
            'bill_item_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'bill_item_ids.*' => ['required', 'integer', 'exists:bill_items,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
        ]);

        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill ini tidak bisa di-split.');
        abort_if((float) $bill->paid_total > 0 || $bill->payments()->where('status', 'PAID')->exists(), 422, 'Bill yang sudah memiliki payment tidak bisa di-split.');

        $splitItems = collect($validated['items'] ?? [])
            ->map(fn (array $row) => [
                'bill_item_id' => (int) $row['bill_item_id'],
                'qty' => (int) $row['qty'],
            ]);

        if ($splitItems->isEmpty()) {
            $splitItems = collect($validated['bill_item_ids'] ?? [])
                ->unique()
                ->values()
                ->map(fn (int $billItemId) => [
                    'bill_item_id' => $billItemId,
                    'qty' => null,
                ]);
        }

        abort_if($splitItems->isEmpty(), 422, 'Pilih minimal satu item untuk split bill.');

        $selectedItemIds = $splitItems->pluck('bill_item_id')->unique()->values();
        abort_if($selectedItemIds->count() !== $splitItems->count(), 422, 'Item split tidak boleh duplikat.');

        $sourceItems = BillItem::query()
            ->where('bill_id', $bill->id)
            ->whereIn('id', $selectedItemIds)
            ->get();

        abort_if($sourceItems->count() !== $selectedItemIds->count(), 422, 'Sebagian item tidak berasal dari bill ini.');

        $sourceItemsById = $sourceItems->keyBy('id');
        $normalizedSplitItems = $splitItems->map(function (array $row) use ($sourceItemsById): array {
            /** @var BillItem|null $billItem */
            $billItem = $sourceItemsById->get($row['bill_item_id']);
            abort_if(! $billItem, 422, 'Sebagian item tidak berasal dari bill ini.');

            $requestedQty = $row['qty'] ?? (int) $billItem->qty;
            abort_if($requestedQty < 1, 422, 'Jumlah split item minimal 1.');
            abort_if($requestedQty > (int) $billItem->qty, 422, "Jumlah split untuk {$billItem->menu_name} melebihi qty yang tersedia.");

            return [
                'bill_item_id' => (int) $billItem->id,
                'qty' => (int) $requestedQty,
            ];
        })->values();

        $movesAllSourceItems = $sourceItems->count() === BillItem::query()->where('bill_id', $bill->id)->count()
            && $normalizedSplitItems->every(function (array $row) use ($sourceItemsById): bool {
                /** @var BillItem $billItem */
                $billItem = $sourceItemsById->get($row['bill_item_id']);

                return (int) $row['qty'] === (int) $billItem->qty;
            });

        abort_if($movesAllSourceItems, 422, 'Tidak dapat memindahkan semua item dengan split bill.');

        $user = $request->user();

        $newBill = DB::transaction(function () use ($bill, $validated, $normalizedSplitItems, $sourceItemsById, $user) {
            $resolvedCustomerName = null;

            if (array_key_exists('customer_name', $validated)) {
                $resolvedCustomerName = filled($validated['customer_name'])
                    ? trim((string) $validated['customer_name'])
                    : null;
            } elseif (! empty($validated['customer_id'])) {
                $resolvedCustomerName = null;
            } else {
                $resolvedCustomerName = $bill->customer_name;
            }

            $newBill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => $validated['customer_id'] ? 'CUSTOMER' : 'SPLIT',
                'table_id' => null,
                'customer_id' => $validated['customer_id'] ?? $bill->customer_id,
                'customer_name' => $resolvedCustomerName,
                'opened_by' => $user->id,
                'cashier_id' => $user->id,
                'guest_count' => $validated['guest_count'] ?? $bill->guest_count,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);
            BillTableManager::syncBillTables($newBill, []);

            $newOrdersBySource = collect();
            $movedBillItemIds = collect();

            foreach ($normalizedSplitItems as $splitRow) {
                /** @var BillItem $sourceBillItem */
                $sourceBillItem = $sourceItemsById->get($splitRow['bill_item_id']);
                $splitQty = (int) $splitRow['qty'];
                $sourceQty = (int) $sourceBillItem->qty;

                $movedBillItem = $sourceBillItem;

                if ($splitQty === $sourceQty) {
                    $sourceBillItem->update([
                        'bill_id' => $newBill->id,
                    ]);
                } else {
                    $perUnitDiscount = $sourceQty > 0 ? round((float) $sourceBillItem->discount_amount / $sourceQty, 2) : 0.0;
                    $perUnitLineTotal = $sourceQty > 0 ? round((float) $sourceBillItem->line_total / $sourceQty, 2) : 0.0;

                    $movedBillItem = BillItem::query()->create([
                        'bill_id' => $newBill->id,
                        'menu_id' => $sourceBillItem->menu_id,
                        'menu_name' => $sourceBillItem->menu_name,
                        'qty' => $splitQty,
                        'unit_price' => $sourceBillItem->unit_price,
                        'discount_amount' => round($perUnitDiscount * $splitQty, 2),
                        'line_total' => round($perUnitLineTotal * $splitQty, 2),
                        'notes' => $sourceBillItem->notes,
                    ]);

                    $remainingQty = $sourceQty - $splitQty;
                    $sourceBillItem->update([
                        'qty' => $remainingQty,
                        'discount_amount' => round((float) $sourceBillItem->discount_amount - (float) $movedBillItem->discount_amount, 2),
                        'line_total' => round((float) $sourceBillItem->line_total - (float) $movedBillItem->line_total, 2),
                    ]);
                }

                $movedBillItemIds->push($movedBillItem->id);

                $relatedOrderItems = OrderItem::query()
                    ->where('bill_item_id', $sourceBillItem->id)
                    ->orderBy('id')
                    ->get();

                $remainingSplitQty = $splitQty;

                foreach ($relatedOrderItems as $sourceOrderItem) {
                    if ($remainingSplitQty <= 0) {
                        break;
                    }

                    $sourceOrder = Order::query()->findOrFail($sourceOrderItem->order_id);

                    /** @var Order|null $newOrder */
                    $newOrder = $newOrdersBySource->get($sourceOrder->id);
                    if (! $newOrder) {
                        $newOrder = Order::query()->create([
                            'order_no' => SequenceNumber::generate('ORD', Order::class, 'order_no'),
                            'bill_id' => $newBill->id,
                            'created_by' => $sourceOrder->created_by,
                            'source' => $sourceOrder->source,
                            'status' => $sourceOrder->status,
                            'sent_at' => $sourceOrder->sent_at,
                            'ready_at' => $sourceOrder->ready_at,
                            'served_at' => $sourceOrder->served_at,
                        ]);
                        $newOrdersBySource->put($sourceOrder->id, $newOrder);
                    }

                    $sourceOrderQty = (int) $sourceOrderItem->qty;
                    $moveQty = min($remainingSplitQty, $sourceOrderQty);
                    abort_if($moveQty < 1, 422, "Jumlah split order untuk {$sourceBillItem->menu_name} tidak valid.");

                    if ($moveQty === $sourceOrderQty) {
                        $sourceOrderItem->update([
                            'order_id' => $newOrder->id,
                            'bill_item_id' => $movedBillItem->id,
                        ]);
                    } else {
                        OrderItem::query()->create([
                            'order_id' => $newOrder->id,
                            'bill_item_id' => $movedBillItem->id,
                            'menu_id' => $sourceOrderItem->menu_id,
                            'category_id' => $sourceOrderItem->category_id,
                            'station_type' => $sourceOrderItem->station_type,
                            'qty' => $moveQty,
                            'notes' => $sourceOrderItem->notes,
                            'status' => $sourceOrderItem->status,
                            'accepted_at' => $sourceOrderItem->accepted_at,
                            'started_at' => $sourceOrderItem->started_at,
                            'ready_at' => $sourceOrderItem->ready_at,
                            'served_at' => $sourceOrderItem->served_at,
                        ]);

                        $sourceOrderItem->update([
                            'qty' => $sourceOrderQty - $moveQty,
                        ]);
                    }

                    $remainingSplitQty -= $moveQty;

                    $this->syncOrderStatus($newOrder->fresh());
                    $this->syncOrDeleteOrder($sourceOrder->fresh());
                }

                abort_if($remainingSplitQty > 0, 422, "Jumlah split order untuk {$sourceBillItem->menu_name} melebihi qty order yang tersedia.");
            }

            $newBill = BillTotals::recalculate($newBill);
            BillTotals::recalculate($bill->fresh());

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.split',
                entityType: 'bill',
                entityId: $bill->id,
                after: [
                    'new_bill_id' => $newBill->id,
                    'moved_item_ids' => $movedBillItemIds->values(),
                    'split_items' => $normalizedSplitItems,
                ],
            );

            return $newBill->fresh(['customer', 'tables']);
        });

        return response()->json([
            'message' => 'Bill berhasil di-split.',
            'data' => $newBill,
        ], 201);
    }

    public function reopen(Request $request, Bill $bill): JsonResponse
    {
        abort_if(! in_array($bill->status, ['PAID', 'VOID', 'REFUND'], true), 422, 'Bill ini tidak dapat dibuka kembali.');

        $user = $request->user();

        DB::transaction(function () use ($bill, $user) {
            $paidTotal = (float) $bill->payments()->where('status', 'PAID')->sum('amount');
            $grandTotal = (float) $bill->grand_total;
            $nextStatus = match (true) {
                $paidTotal <= 0 => 'OPEN',
                $paidTotal < $grandTotal => 'PARTIALLY_PAID',
                default => 'SERVED',
            };

            $bill->update([
                'status' => $nextStatus,
                'closed_at' => null,
            ]);

            BillTableManager::updateBillTablesStatus($bill, 'OPEN_BILL');

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.reopened',
                entityType: 'bill',
                entityId: $bill->id,
                after: ['status' => $nextStatus],
            );
        });

        return response()->json([
            'message' => 'Bill berhasil dibuka kembali.',
            'data' => $bill->fresh(['table', 'tables']),
        ]);
    }

    public function void(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        abort_if(in_array($bill->status, ['VOID', 'REFUND'], true), 422, 'Bill sudah dibatalkan.');
        abort_if((float) $bill->paid_total > 0, 422, 'Bill yang sudah memiliki pembayaran tidak bisa di-void langsung.');

        $user = $request->user();

        DB::transaction(function () use ($bill, $validated, $user) {
            $bill->loadMissing('orders.items.menu');

            foreach ($bill->orders as $order) {
                foreach ($order->items as $orderItem) {
                    if (in_array($orderItem->status, ['SERVED', 'CANCELLED'], true)) {
                        continue;
                    }

                    $orderItem->update([
                        'status' => 'CANCELLED',
                    ]);

                    InventoryManager::restoreForOrderItem(
                        orderItem: $orderItem->fresh(['menu']),
                        userId: $user->id,
                        reason: "Bill {$bill->bill_no} di-void",
                    );
                }

                $this->syncOrderStatus($order->fresh('items'));
            }

            $bill->update([
                'status' => 'VOID',
                'closed_at' => now(),
            ]);

            BillTableManager::updateBillTablesStatus($bill, 'AVAILABLE');
            BillTableManager::syncBillTables($bill, []);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.voided',
                entityType: 'bill',
                entityId: $bill->id,
                after: ['status' => 'VOID'],
                reason: $validated['reason'],
            );
        });

        return response()->json([
            'message' => 'Bill berhasil di-void.',
            'data' => $bill->fresh(['table', 'tables']),
        ]);
    }

    private function validateBillCreationRules(array $validated): void
    {
        $billType = $validated['bill_type'];
        $tableId = $validated['table_id'] ?? null;
        $extraTableIds = $validated['extra_table_ids'] ?? [];
        $customerId = $validated['customer_id'] ?? null;
        $guestCount = (int) ($validated['guest_count'] ?? 1);
        $linkedTableIds = BillTableManager::normalizeTableIds($tableId, $extraTableIds);

        if ($billType === 'DINE_IN') {
            abort_if(blank($tableId), 422, 'Bill DINE_IN wajib memiliki meja.');
            abort_if($linkedTableIds === [], 422, 'Bill DINE_IN wajib memiliki meja.');

            $totalCapacity = BillTableManager::totalCapacity($linkedTableIds);
            abort_if($guestCount > $totalCapacity, 422, 'Kapasitas meja gabungan belum cukup untuk jumlah tamu.');
        }

        if (in_array($billType, ['TAKE_AWAY', 'CATERING', 'WALK_IN', 'DELIVERY'], true)) {
            abort_if(
                filled($tableId) || ! empty($extraTableIds),
                422,
                'Bill non-meja tidak boleh terhubung ke meja.',
            );
        }

        if ($billType === 'CATERING') {
            abort_if(
                blank($validated['event_scheduled_at'] ?? null),
                422,
                'Tanggal dan jam acara wajib diisi untuk pesanan katering/event.',
            );
        }

        if ($billType === 'CUSTOMER') {
            abort_if(blank($customerId), 422, 'Bill CUSTOMER wajib memiliki customer.');
        }
    }

    private function syncOrDeleteOrder(Order $order): void
    {
        if (! $order->items()->exists()) {
            $order->delete();

            return;
        }

        $this->syncOrderStatus($order);
    }

    private function syncOrderStatus(Order $order): void
    {
        $statuses = $order->items()->pluck('status');
        $orderStatus = BillOrderState::resolveOrderStatus($statuses);

        $order->update([
            'status' => $orderStatus,
            'ready_at' => $orderStatus === 'READY' ? ($order->ready_at ?? now()) : $order->ready_at,
            'served_at' => $orderStatus === 'SERVED' ? ($order->served_at ?? now()) : null,
        ]);
    }
}
