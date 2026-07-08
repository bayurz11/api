<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AuditLogger;
use App\Support\BillTotals;
use App\Support\SequenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Bill $bill): JsonResponse
    {
        return response()->json([
            'data' => $bill->orders()->with('items')->latest('id')->get(),
        ]);
    }

    public function store(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_id' => ['required', 'integer', 'exists:menus,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        abort_if(! in_array($bill->status, ['OPEN', 'ORDERING'], true), 422, 'Bill tidak bisa menerima order baru.');

        $user = $request->user();

        $order = DB::transaction(function () use ($bill, $validated, $user) {
            $menus = Menu::query()
                ->with('category:id,name,station_type')
                ->whereIn('id', collect($validated['items'])->pluck('menu_id'))
                ->get()
                ->keyBy('id');

            $order = Order::query()->create([
                'order_no' => SequenceNumber::generate('ORD', Order::class, 'order_no'),
                'bill_id' => $bill->id,
                'created_by' => $user->id,
                'source' => 'POS',
                'status' => 'WAITING',
                'sent_at' => now(),
            ]);

            $subtotalIncrease = 0;

            foreach ($validated['items'] as $item) {
                $menu = $menus[$item['menu_id']];
                abort_if(! $menu->is_active || ! $menu->is_available, 422, "Menu {$menu->name} sedang tidak tersedia.");
                $lineTotal = (float) $menu->price * $item['qty'];

                $billItem = BillItem::query()->create([
                    'bill_id' => $bill->id,
                    'menu_id' => $menu->id,
                    'menu_name' => $menu->name,
                    'qty' => $item['qty'],
                    'unit_price' => $menu->price,
                    'discount_amount' => 0,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'bill_item_id' => $billItem->id,
                    'menu_id' => $menu->id,
                    'category_id' => $menu->category_id,
                    'station_type' => $menu->station_type,
                    'qty' => $item['qty'],
                    'notes' => $item['notes'] ?? null,
                    'status' => 'WAITING',
                ]);

                $subtotalIncrease += $lineTotal;
            }

            $bill->update([
                'status' => 'ORDERING',
            ]);

            BillTotals::recalculate($bill);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'order.sent',
                entityType: 'order',
                entityId: $order->id,
                after: $order->toArray(),
            );

            return $order;
        });

        return response()->json([
            'message' => 'Order berhasil dikirim.',
            'data' => $order->load('items'),
        ], 201);
    }
}
