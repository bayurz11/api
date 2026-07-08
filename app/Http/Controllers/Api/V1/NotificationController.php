<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\OrderItem;
use App\Models\QrOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'dashboard.view');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'channel' => ['nullable', 'string'],
        ]);

        $limit = $validated['limit'] ?? 30;
        $channel = $validated['channel'] ?? null;

        $readyItems = collect();
        $kitchenItems = collect();
        $barItems = collect();
        $pendingQrOrders = collect();
        $cashierBills = collect();

        if ($channel === null || $channel === 'kitchen_new') {
            $kitchenItems = OrderItem::query()
                ->with([
                    'menu:id,name',
                    'order:id,order_no,bill_id,sent_at',
                    'order.bill:id,bill_no,table_id',
                    'order.bill.table:id,code,name',
                ])
                ->where('station_type', 'KITCHEN')
                ->whereIn('status', ['WAITING', 'ACCEPTED', 'COOKING'])
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (OrderItem $item) => [
                    'type' => 'KITCHEN_NEW_ITEM',
                    'channel' => 'kitchen_new',
                    'sort_at' => optional($item->order?->sent_at)->toIso8601String() ?? optional($item->created_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                    'title' => 'New kitchen order',
                    'message' => trim(($item->menu?->name ?? 'Kitchen item') . ' for ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                    'entity_type' => 'order_item',
                    'entity_id' => $item->id,
                    'meta' => [
                        'order_item_id' => $item->id,
                        'station_type' => $item->station_type,
                        'menu_name' => $item->menu?->name,
                        'qty' => $item->qty,
                        'status' => $item->status,
                        'order_id' => $item->order?->id,
                        'order_no' => $item->order?->order_no,
                        'bill_id' => $item->order?->bill?->id,
                        'bill_no' => $item->order?->bill?->bill_no,
                        'table_id' => $item->order?->bill?->table?->id,
                        'table_name' => $item->order?->bill?->table?->name,
                        'sent_at' => optional($item->order?->sent_at)->toIso8601String(),
                    ],
                ]);
        }

        if ($channel === null || $channel === 'bar_new') {
            $barItems = OrderItem::query()
                ->with([
                    'menu:id,name',
                    'order:id,order_no,bill_id,sent_at',
                    'order.bill:id,bill_no,table_id',
                    'order.bill.table:id,code,name',
                ])
                ->where('station_type', 'BAR')
                ->whereIn('status', ['WAITING', 'ACCEPTED', 'PREPARING'])
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (OrderItem $item) => [
                    'type' => 'BAR_NEW_ITEM',
                    'channel' => 'bar_new',
                    'sort_at' => optional($item->order?->sent_at)->toIso8601String() ?? optional($item->created_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                    'title' => 'New bar order',
                    'message' => trim(($item->menu?->name ?? 'Bar item') . ' for ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                    'entity_type' => 'order_item',
                    'entity_id' => $item->id,
                    'meta' => [
                        'order_item_id' => $item->id,
                        'station_type' => $item->station_type,
                        'menu_name' => $item->menu?->name,
                        'qty' => $item->qty,
                        'status' => $item->status,
                        'order_id' => $item->order?->id,
                        'order_no' => $item->order?->order_no,
                        'bill_id' => $item->order?->bill?->id,
                        'bill_no' => $item->order?->bill?->bill_no,
                        'table_id' => $item->order?->bill?->table?->id,
                        'table_name' => $item->order?->bill?->table?->name,
                        'sent_at' => optional($item->order?->sent_at)->toIso8601String(),
                    ],
                ]);
        }

        if ($channel === null || $channel === 'waiter_ready') {
            $readyItems = OrderItem::query()
                ->with([
                    'menu:id,name',
                    'order:id,order_no,bill_id',
                    'order.bill:id,bill_no,table_id,status',
                    'order.bill.table:id,code,name',
                ])
                ->where('status', 'READY')
                ->latest('ready_at')
                ->limit($limit)
                ->get()
                ->map(fn (OrderItem $item) => [
                    'type' => 'WAITER_READY_ITEM',
                    'channel' => 'waiter_ready',
                    'sort_at' => optional($item->ready_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                    'title' => 'Pesanan siap diantar',
                    'message' => trim(($item->menu?->name ?? $item->station_type) . ' untuk ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                    'entity_type' => 'order_item',
                    'entity_id' => $item->id,
                    'meta' => [
                        'order_item_id' => $item->id,
                        'station_type' => $item->station_type,
                        'menu_name' => $item->menu?->name,
                        'qty' => $item->qty,
                        'order_id' => $item->order?->id,
                        'order_no' => $item->order?->order_no,
                        'bill_id' => $item->order?->bill?->id,
                        'bill_no' => $item->order?->bill?->bill_no,
                        'table_id' => $item->order?->bill?->table?->id,
                        'table_name' => $item->order?->bill?->table?->name,
                        'ready_at' => optional($item->ready_at)->toIso8601String(),
                    ],
                ]);
        }

        if ($channel === null || $channel === 'qr_pending') {
            $pendingQrOrders = QrOrder::query()
                ->with(['table:id,code,name'])
                ->where('status', 'PENDING')
                ->latest('submitted_at')
                ->limit($limit)
                ->get()
                ->map(fn (QrOrder $qrOrder) => [
                    'type' => 'QR_ORDER_PENDING',
                    'channel' => 'qr_pending',
                    'sort_at' => optional($qrOrder->submitted_at)->toIso8601String() ?? optional($qrOrder->updated_at)->toIso8601String(),
                    'title' => 'QR order menunggu approval',
                    'message' => trim(($qrOrder->customer_name ?: 'Guest QR') . ' di ' . ($qrOrder->table?->name ?? $qrOrder->table_id)),
                    'entity_type' => 'qr_order',
                    'entity_id' => $qrOrder->id,
                    'meta' => [
                        'qr_order_id' => $qrOrder->id,
                        'order_no' => $qrOrder->order_no,
                        'table_id' => $qrOrder->table?->id,
                        'table_name' => $qrOrder->table?->name,
                        'customer_name' => $qrOrder->customer_name,
                        'guest_count' => $qrOrder->guest_count,
                        'grand_total' => number_format((float) $qrOrder->grand_total, 2, '.', ''),
                        'submitted_at' => optional($qrOrder->submitted_at)->toIso8601String(),
                    ],
                ]);
        }

        if ($channel === null || $channel === 'cashier_bill') {
            $cashierBills = Bill::query()
                ->with(['table:id,code,name'])
                ->whereIn('status', ['READY_TO_PAY', 'PARTIALLY_PAID'])
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->map(fn (Bill $bill) => [
                    'type' => 'CASHIER_BILL_ACTION',
                    'channel' => 'cashier_bill',
                    'sort_at' => optional($bill->updated_at)->toIso8601String(),
                    'title' => $bill->status === 'PARTIALLY_PAID' ? 'Bill menunggu pelunasan' : 'Bill siap dibayar',
                    'message' => trim(($bill->table?->name ?? $bill->bill_no) . ' - sisa ' . number_format((float) $bill->balance_due, 0, ',', '.')),
                    'entity_type' => 'bill',
                    'entity_id' => $bill->id,
                    'meta' => [
                        'bill_id' => $bill->id,
                        'bill_no' => $bill->bill_no,
                        'status' => $bill->status,
                        'table_id' => $bill->table?->id,
                        'table_name' => $bill->table?->name,
                        'grand_total' => number_format((float) $bill->grand_total, 2, '.', ''),
                        'paid_total' => number_format((float) $bill->paid_total, 2, '.', ''),
                        'balance_due' => number_format((float) $bill->balance_due, 2, '.', ''),
                    ],
                ]);
        }

        $items = $readyItems
            ->concat($kitchenItems)
            ->concat($barItems)
            ->concat($pendingQrOrders)
            ->concat($cashierBills)
            ->sortByDesc('sort_at')
            ->take($limit)
            ->values()
            ->map(function (array $item) {
                $item['occurred_at'] = $item['sort_at'] ?? null;
                unset($item['sort_at']);

                return $item;
            });

        return response()->json([
            'summary' => [
                'kitchen_queue_count' => OrderItem::query()
                    ->where('station_type', 'KITCHEN')
                    ->whereIn('status', ['WAITING', 'ACCEPTED', 'COOKING'])
                    ->count(),
                'bar_queue_count' => OrderItem::query()
                    ->where('station_type', 'BAR')
                    ->whereIn('status', ['WAITING', 'ACCEPTED', 'PREPARING'])
                    ->count(),
                'ready_items_count' => OrderItem::query()->where('status', 'READY')->count(),
                'pending_qr_orders_count' => QrOrder::query()->where('status', 'PENDING')->count(),
                'cashier_action_bills_count' => Bill::query()->whereIn('status', ['READY_TO_PAY', 'PARTIALLY_PAID'])->count(),
            ],
            'data' => $items,
        ]);
    }
}
