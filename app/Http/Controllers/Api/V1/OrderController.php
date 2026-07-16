<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Menu;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\AuditLogger;
use App\Support\BillTotals;
use App\Support\InventoryManager;
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
            'items.*.menu_option_id' => ['nullable', 'integer', 'exists:menu_options,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        abort_if(! in_array($bill->status, ['OPEN', 'ORDERING', 'READY_TO_PAY', 'SERVED'], true), 422, 'Bill tidak bisa menerima order baru.');

        $user = $request->user();

        $order = DB::transaction(function () use ($bill, $validated, $user) {
            $menus = Menu::query()
                ->with('category:id,name,station_type')
                ->with(['recipeIngredients', 'options'])
                ->whereIn('id', collect($validated['items'])->pluck('menu_id'))
                ->get()
                ->keyBy('id');
            $optionIds = collect($validated['items'])
                ->pluck('menu_option_id')
                ->filter()
                ->map(fn ($value) => (int) $value)
                ->values();
            $options = MenuOption::query()
                ->whereIn('id', $optionIds)
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
                abort_if(! $menu->is_active || ! $menu->is_available || ! $menu->is_stock_available, 422, "Menu {$menu->name} sedang tidak tersedia.");
                $menuOption = $this->resolveMenuOption(
                    menu: $menu,
                    options: $options,
                    optionId: isset($item['menu_option_id']) ? (int) $item['menu_option_id'] : null,
                );
                $unitPrice = (float) $menu->price + (float) ($menuOption?->price_delta ?? 0);
                $menuName = $menuOption ? "{$menu->name} - {$menuOption->name}" : $menu->name;
                $lineTotal = $unitPrice * $item['qty'];

                InventoryManager::deductForMenu(
                    menu: $menu,
                    qty: (int) $item['qty'],
                    userId: $user->id,
                    reason: "Order POS {$order->order_no} untuk {$menuName}",
                    menuOption: $menuOption,
                );

                $billItem = BillItem::query()->create([
                    'bill_id' => $bill->id,
                    'menu_id' => $menu->id,
                    'menu_option_id' => $menuOption?->id,
                    'menu_name' => $menuName,
                    'qty' => $item['qty'],
                    'unit_price' => $unitPrice,
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
                    'stock_deducted' => true,
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

    private function resolveMenuOption(Menu $menu, \Illuminate\Support\Collection $options, ?int $optionId): ?MenuOption
    {
        $configuredOptions = $menu->options->where('is_active', true)->values();

        if ($configuredOptions->isEmpty()) {
            return null;
        }

        abort_if($optionId === null, 422, "Pilih varian untuk menu {$menu->name}.");

        /** @var MenuOption|null $option */
        $option = $options->get($optionId);
        abort_if($option === null || $option->menu_id !== $menu->id, 422, "Varian menu {$menu->name} tidak valid.");
        abort_if(
            ! $option->is_active || ! $option->is_available || ! $option->is_stock_available,
            422,
            "Varian {$option->name} untuk menu {$menu->name} sedang tidak tersedia.",
        );

        return $option;
    }
}
