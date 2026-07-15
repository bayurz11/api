<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiterChecklistController extends Controller
{
    public function readyItems(Request $request): JsonResponse
    {
        $items = OrderItem::query()
            ->with([
                'menu:id,name',
                'billItem:id,menu_name',
                'order:id,order_no,bill_id,status',
                'order.bill:id,bill_no,table_id,customer_id,status',
                'order.bill.table:id,code,name,area',
                'order.bill.customer:id,name,member_code',
            ])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
                fn ($query) => $query->whereIn('status', ['READY', 'SERVED']),
            )
            ->when($request->filled('table_id'), fn ($query) => $query->whereHas('order.bill', fn ($billQuery) => $billQuery->where('table_id', $request->integer('table_id'))))
            ->when($request->filled('station_type'), fn ($query) => $query->where('station_type', $request->string('station_type')))
            ->orderByRaw('COALESCE(served_at, ready_at, updated_at) DESC')
            ->get()
            ->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'qty' => $item->qty,
                'notes' => $item->notes,
                'status' => $item->status,
                'station_type' => $item->station_type,
                'ready_at' => $item->ready_at,
                'served_at' => $item->served_at,
                'menu_name' => $item->billItem?->menu_name ?? $item->menu?->name,
                'menu' => $item->menu ? [
                    'id' => $item->menu->id,
                    'name' => $item->menu->name,
                ] : null,
                'order' => $item->order ? [
                    'id' => $item->order->id,
                    'order_no' => $item->order->order_no,
                    'status' => $item->order->status,
                ] : null,
                'bill' => $item->order?->bill ? [
                    'id' => $item->order->bill->id,
                    'bill_no' => $item->order->bill->bill_no,
                    'status' => $item->order->bill->status,
                ] : null,
                'table' => $item->order?->bill?->table ? [
                    'id' => $item->order->bill->table->id,
                    'code' => $item->order->bill->table->code,
                    'name' => $item->order->bill->table->name,
                    'area' => $item->order->bill->table->area,
                ] : null,
                'customer' => $item->order?->bill?->customer ? [
                    'id' => $item->order->bill->customer->id,
                    'name' => $item->order->bill->customer->name,
                    'member_code' => $item->order->bill->customer->member_code,
                ] : null,
            ])
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function showBillChecklist(Request $request, Bill $bill): JsonResponse
    {
        $bill->load([
            'table:id,code,name,area',
            'customer:id,name,member_code,phone',
            'orders' => fn ($query) => $query->select('id', 'bill_id', 'order_no', 'status', 'sent_at', 'ready_at', 'served_at')->latest('id'),
            'orders.items' => fn ($query) => $query->with('menu:id,name')->orderBy('id'),
            'orders.items.billItem:id,menu_name',
        ]);

        $items = $bill->orders
            ->flatMap(fn ($order) => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'menu_name' => $item->billItem?->menu_name ?? $item->menu?->name,
                'qty' => $item->qty,
                'notes' => $item->notes,
                'station_type' => $item->station_type,
                'status' => $item->status,
                'ready_at' => $item->ready_at,
                'served_at' => $item->served_at,
            ]))
            ->values();

        $summary = [
            'total_items' => $items->count(),
            'waiting' => $items->where('status', 'WAITING')->count(),
            'accepted' => $items->where('status', 'ACCEPTED')->count(),
            'preparing' => $items->filter(fn ($item) => in_array($item['status'], ['COOKING', 'PREPARING'], true))->count(),
            'ready' => $items->where('status', 'READY')->count(),
            'served' => $items->where('status', 'SERVED')->count(),
            'cancelled' => $items->where('status', 'CANCELLED')->count(),
        ];

        return response()->json([
            'data' => [
                'bill' => [
                    'id' => $bill->id,
                    'bill_no' => $bill->bill_no,
                    'status' => $bill->status,
                    'bill_type' => $bill->bill_type,
                    'guest_count' => $bill->guest_count,
                ],
                'table' => $bill->table,
                'customer' => $bill->customer,
                'summary' => $summary,
                'items' => $items,
            ],
        ]);
    }
}
