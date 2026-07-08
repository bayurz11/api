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
use App\Support\BillTotals;
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
        'WALK_IN',
        'DELIVERY',
        'CUSTOMER',
        'RESERVATION',
        'SPLIT',
    ];

    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->with(['table:id,code,name,status', 'customer:id,name,member_code,phone'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('table_id'), fn ($query) => $query->where('table_id', $request->integer('table_id')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('bill_type'), fn ($query) => $query->where('bill_type', $request->string('bill_type')))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($bills);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_type' => ['required', 'string', Rule::in(self::BILL_TYPES)],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->validateBillCreationRules($validated);

        $user = $request->user();

        $bill = DB::transaction(function () use ($validated, $user) {
            if (! empty($validated['table_id'])) {
                $openBillExists = Bill::query()
                    ->where('table_id', $validated['table_id'])
                    ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID'])
                    ->exists();

                abort_if($openBillExists, 422, 'Meja sudah memiliki open bill aktif.');
            }

            $bill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => $validated['bill_type'],
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'opened_by' => $user->id,
                'cashier_id' => $user->id,
                'guest_count' => $validated['guest_count'] ?? 1,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);

            if ($bill->table_id) {
                Table::query()->whereKey($bill->table_id)->update([
                    'status' => 'OPEN_BILL',
                ]);
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
            'data' => $bill->load(['table:id,code,name,status', 'customer:id,name,member_code,phone']),
        ], 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->load([
            'table:id,code,name,status',
            'customer:id,name,member_code,phone,email',
            'items',
            'orders.items',
            'payments',
        ]);

        return response()->json([
            'data' => $bill,
        ]);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill tidak bisa diubah.');

        $validated = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'service_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $before = $bill->only([
            'guest_count',
            'customer_id',
            'discount_total',
            'tax_total',
            'service_total',
            'grand_total',
            'balance_due',
        ]);

        $bill = DB::transaction(function () use ($bill, $validated) {
            $bill->fill([
                'guest_count' => $validated['guest_count'] ?? $bill->guest_count,
                'customer_id' => array_key_exists('customer_id', $validated) ? $validated['customer_id'] : $bill->customer_id,
                'discount_total' => $validated['discount_total'] ?? $bill->discount_total,
                'tax_total' => $validated['tax_total'] ?? $bill->tax_total,
                'service_total' => $validated['service_total'] ?? $bill->service_total,
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
                'discount_total',
                'tax_total',
                'service_total',
                'grand_total',
                'balance_due',
            ]),
        );

        return response()->json([
            'message' => 'Bill berhasil diperbarui.',
            'data' => $bill->load(['table:id,code,name,status', 'customer:id,name,member_code']),
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
            $targetHasOpenBill = Bill::query()
                ->where('table_id', $validated['table_id'])
                ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID', 'SERVED'])
                ->exists();

            abort_if($targetHasOpenBill, 422, 'Meja tujuan masih memiliki bill aktif.');

            $previousTableId = $bill->table_id;
            $bill->update(['table_id' => $validated['table_id']]);

            Table::query()->whereKey($previousTableId)->update(['status' => 'AVAILABLE']);
            Table::query()->whereKey($validated['table_id'])->update(['status' => 'OPEN_BILL']);

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
            'data' => $bill->fresh('table'),
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
            BillItem::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Order::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Payment::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);
            Deposit::query()->where('bill_id', $bill->id)->update(['bill_id' => $targetBill->id]);

            $targetBill->update([
                'customer_id' => $targetBill->customer_id ?? $bill->customer_id,
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

            if ($bill->table_id && $bill->table_id !== $targetBill->table_id) {
                $bill->table()->update(['status' => 'AVAILABLE']);
            }

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'bill.merged',
                entityType: 'bill',
                entityId: $bill->id,
                before: ['target_bill_id' => null],
                after: ['target_bill_id' => $targetBill->id],
            );

            return $targetBill->fresh(['table', 'customer']);
        });

        return response()->json([
            'message' => 'Bill berhasil digabung.',
            'data' => $targetBill,
        ]);
    }

    public function split(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'bill_item_ids' => ['required', 'array', 'min:1'],
            'bill_item_ids.*' => ['required', 'integer', 'exists:bill_items,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
        ]);

        abort_if(in_array($bill->status, ['PAID', 'CANCELLED', 'VOID', 'REFUND'], true), 422, 'Bill ini tidak bisa di-split.');
        abort_if((float) $bill->paid_total > 0 || $bill->payments()->where('status', 'PAID')->exists(), 422, 'Bill yang sudah memiliki payment tidak bisa di-split.');

        $selectedItemIds = collect($validated['bill_item_ids'])->unique()->values();
        $sourceItemsCount = BillItem::query()->where('bill_id', $bill->id)->count();
        $selectedItems = BillItem::query()
            ->where('bill_id', $bill->id)
            ->whereIn('id', $selectedItemIds)
            ->get();

        abort_if($selectedItems->count() !== $selectedItemIds->count(), 422, 'Sebagian item tidak berasal dari bill ini.');
        abort_if($selectedItems->count() === $sourceItemsCount, 422, 'Tidak dapat memindahkan semua item dengan split bill.');

        $user = $request->user();

        $newBill = DB::transaction(function () use ($bill, $validated, $selectedItems, $user) {
            $newBill = Bill::query()->create([
                'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                'bill_type' => $validated['customer_id'] ? 'CUSTOMER' : 'SPLIT',
                'table_id' => null,
                'customer_id' => $validated['customer_id'] ?? $bill->customer_id,
                'opened_by' => $user->id,
                'cashier_id' => $user->id,
                'guest_count' => $validated['guest_count'] ?? $bill->guest_count,
                'status' => 'OPEN',
                'opened_at' => now(),
            ]);

            BillItem::query()
                ->whereIn('id', $selectedItems->pluck('id'))
                ->update(['bill_id' => $newBill->id]);

            $selectedOrderItems = OrderItem::query()
                ->whereIn('bill_item_id', $selectedItems->pluck('id'))
                ->get()
                ->groupBy('order_id');

            foreach ($selectedOrderItems as $orderId => $orderItems) {
                $sourceOrder = Order::query()->findOrFail($orderId);

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

                OrderItem::query()
                    ->whereIn('id', $orderItems->pluck('id'))
                    ->update(['order_id' => $newOrder->id]);

                $this->syncOrderStatus($newOrder->fresh());
                $this->syncOrDeleteOrder($sourceOrder->fresh());
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
                    'moved_item_ids' => $selectedItems->pluck('id')->values(),
                ],
            );

            return $newBill->fresh(['customer']);
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

            if ($bill->table_id) {
                $bill->table()->update(['status' => 'OPEN_BILL']);
            }

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
            'data' => $bill->fresh('table'),
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
            $bill->update([
                'status' => 'VOID',
                'closed_at' => now(),
            ]);

            if ($bill->table_id) {
                $bill->table()->update(['status' => 'AVAILABLE']);
            }

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
            'data' => $bill->fresh('table'),
        ]);
    }

    private function validateBillCreationRules(array $validated): void
    {
        $billType = $validated['bill_type'];
        $tableId = $validated['table_id'] ?? null;
        $customerId = $validated['customer_id'] ?? null;

        if ($billType === 'DINE_IN') {
            abort_if(blank($tableId), 422, 'Bill DINE_IN wajib memiliki meja.');
        }

        if (in_array($billType, ['TAKE_AWAY', 'WALK_IN', 'DELIVERY'], true)) {
            abort_if(filled($tableId), 422, 'Bill non-meja tidak boleh terhubung ke meja.');
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
