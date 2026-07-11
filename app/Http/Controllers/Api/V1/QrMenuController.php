<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrder;
use App\Models\QrOrderItem;
use App\Models\Table;
use App\Support\AuditLogger;
use App\Support\BillTotals;
use App\Support\SequenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QrMenuController extends Controller
{
    public function menu(string $tableCode): JsonResponse
    {
        $table = $this->resolveActiveTable($tableCode);

        $categories = DB::table('menu_categories')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($category) {
                $menus = Menu::query()
                    ->where('category_id', $category->id)
                    ->where('is_active', true)
                    ->where('is_available', true)
                    ->orderBy('name')
                    ->get(['id', 'sku', 'name', 'description', 'price', 'station_type']);

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'station_type' => $category->station_type,
                    'menus' => $menus,
                ];
            })
            ->filter(fn ($category) => collect($category['menus'])->isNotEmpty())
            ->values();

        return response()->json([
            'data' => [
                'table' => [
                    'id' => $table->id,
                    'code' => $table->code,
                    'name' => $table->name,
                    'area' => $table->area,
                    'status' => $table->status,
                ],
                'categories' => $categories,
            ],
        ]);
    }

    public function checkout(Request $request, string $tableCode): JsonResponse
    {
        $table = $this->resolveActiveTable($tableCode);

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_id' => ['required', 'integer', 'exists:menus,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $qrOrder = DB::transaction(function () use ($validated, $table) {
            $menus = Menu::query()
                ->with('category:id,name,station_type')
                ->whereIn('id', collect($validated['items'])->pluck('menu_id'))
                ->get()
                ->keyBy('id');

            $subtotal = 0;

            $qrOrder = QrOrder::query()->create([
                'order_no' => SequenceNumber::generate('QRO', QrOrder::class, 'order_no'),
                'guest_token' => (string) Str::uuid(),
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'guest_count' => $validated['guest_count'] ?? 1,
                'notes' => $validated['notes'] ?? null,
                'status' => 'PENDING',
                'submitted_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                $menu = $menus[$item['menu_id']];
                abort_if(! $menu->is_active || ! $menu->is_available, 422, "Menu {$menu->name} sedang tidak tersedia.");

                $lineTotal = (float) $menu->price * (int) $item['qty'];
                $subtotal += $lineTotal;

                QrOrderItem::query()->create([
                    'qr_order_id' => $qrOrder->id,
                    'menu_id' => $menu->id,
                    'menu_name' => $menu->name,
                    'station_type' => $menu->station_type,
                    'qty' => $item['qty'],
                    'unit_price' => $menu->price,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                    'status' => 'PENDING',
                ]);
            }

            $qrOrder->update([
                'subtotal' => $subtotal,
                'grand_total' => $subtotal,
            ]);

            AuditLogger::log(
                userId: null,
                roleName: 'Customer',
                action: 'qr_order.created',
                entityType: 'qr_order',
                entityId: $qrOrder->id,
                after: $qrOrder->fresh()->toArray(),
            );

            return $qrOrder->fresh(['table', 'items']);
        });

        return response()->json([
            'message' => 'Order QR berhasil dikirim dan menunggu approval waiter.',
            'data' => $qrOrder,
        ], 201);
    }

    public function status(string $guestToken): JsonResponse
    {
        $qrOrder = QrOrder::query()
            ->with([
                'table:id,code,name',
                'bill:id,bill_no,status,table_id',
                'approvedOrder:id,order_no,status,bill_id',
                'items',
            ])
            ->where('guest_token', $guestToken)
            ->firstOrFail();

        return response()->json([
            'data' => $qrOrder,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $orders = QrOrder::query()
            ->with(['table:id,code,name,area', 'items'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate($perPage);

        return response()->json($orders);
    }

    public function approve(Request $request, QrOrder $qrOrder): JsonResponse
    {
        abort_if($qrOrder->status !== 'PENDING', 422, 'QR order sudah diproses.');

        $user = $request->user();

        [$qrOrder, $bill, $order] = DB::transaction(function () use ($qrOrder, $user) {
            $table = Table::query()->findOrFail($qrOrder->table_id);

            $bill = Bill::query()
                ->where('table_id', $table->id)
                ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID', 'SERVED'])
                ->latest('id')
                ->first();

            if (! $bill) {
                $bill = Bill::query()->create([
                    'bill_no' => SequenceNumber::generate('BILL', Bill::class, 'bill_no'),
                    'bill_type' => 'DINE_IN',
                    'table_id' => $table->id,
                    'opened_by' => $user->id,
                    'cashier_id' => $user->id,
                    'guest_count' => max((int) $qrOrder->guest_count, 1),
                    'status' => 'OPEN',
                    'opened_at' => now(),
                ]);

                $table->update(['status' => 'OPEN_BILL']);
            } else {
                $bill->update([
                    'guest_count' => max((int) $bill->guest_count, (int) $qrOrder->guest_count),
                ]);
            }

            $order = Order::query()->create([
                'order_no' => SequenceNumber::generate('ORD', Order::class, 'order_no'),
                'bill_id' => $bill->id,
                'created_by' => $user->id,
                'source' => 'QR',
                'status' => 'WAITING',
                'sent_at' => now(),
            ]);

            $qrOrder->loadMissing('items');

            foreach ($qrOrder->items as $qrItem) {
                $billItem = BillItem::query()->create([
                    'bill_id' => $bill->id,
                    'menu_id' => $qrItem->menu_id,
                    'menu_name' => $qrItem->menu_name,
                    'qty' => $qrItem->qty,
                    'unit_price' => $qrItem->unit_price,
                    'discount_amount' => 0,
                    'line_total' => $qrItem->line_total,
                    'notes' => $qrItem->notes,
                ]);

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'bill_item_id' => $billItem->id,
                    'menu_id' => $qrItem->menu_id,
                    'category_id' => Menu::query()->whereKey($qrItem->menu_id)->value('category_id'),
                    'station_type' => $qrItem->station_type,
                    'qty' => $qrItem->qty,
                    'notes' => $qrItem->notes,
                    'status' => 'WAITING',
                ]);

                $qrItem->update(['status' => 'APPROVED']);
            }

            $bill->update(['status' => 'ORDERING']);
            $bill = BillTotals::recalculate($bill);

            $qrOrder->update([
                'linked_bill_id' => $bill->id,
                'approved_order_id' => $order->id,
                'approved_by' => $user->id,
                'status' => 'APPROVED',
                'approved_at' => now(),
            ]);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'qr_order.approved',
                entityType: 'qr_order',
                entityId: $qrOrder->id,
                after: [
                    'linked_bill_id' => $bill->id,
                    'approved_order_id' => $order->id,
                    'status' => 'APPROVED',
                ],
            );

            return [$qrOrder->fresh(['table', 'items', 'bill', 'approvedOrder']), $bill->fresh(), $order->fresh('items')];
        });

        return response()->json([
            'message' => 'QR order berhasil di-approve.',
            'data' => $qrOrder,
            'bill' => $bill,
            'order' => $order,
        ]);
    }

    public function reject(Request $request, QrOrder $qrOrder): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        abort_if($qrOrder->status !== 'PENDING', 422, 'QR order sudah diproses.');

        $qrOrder->items()->update(['status' => 'REJECTED']);
        $qrOrder->update([
            'status' => 'REJECTED',
            'rejected_at' => now(),
            'notes' => trim(($qrOrder->notes ? $qrOrder->notes . PHP_EOL : '') . 'Reject: ' . $validated['reason']),
        ]);

        AuditLogger::log(
            userId: $request->user()->id,
            roleName: $request->user()->getRoleNames()->first(),
            action: 'qr_order.rejected',
            entityType: 'qr_order',
            entityId: $qrOrder->id,
            after: ['status' => 'REJECTED'],
            reason: $validated['reason'],
        );

        return response()->json([
            'message' => 'QR order berhasil ditolak.',
            'data' => $qrOrder->fresh(['table', 'items']),
        ]);
    }

    private function resolveActiveTable(string $tableCode): Table
    {
        $table = Table::query()
            ->where('code', $tableCode)
            ->where('is_active', true)
            ->firstOrFail();

        abort_if($table->status === 'OUT_OF_SERVICE', 422, 'Meja ini sedang tidak dapat digunakan.');

        return $table;
    }
}
