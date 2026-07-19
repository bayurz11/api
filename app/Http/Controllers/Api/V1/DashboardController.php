<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Ingredient;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\ShoppingNote;
use App\Support\TableCleaningManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return lightweight dashboard data for the mobile app.
     */
    public function __invoke(Request $request): JsonResponse
    {
        TableCleaningManager::releaseExpiredTables();

        $todayStart = now()->startOfDay();
        $tomorrowStart = now()->addDay()->startOfDay();
        $sevenDaysStart = now()->subDays(6)->startOfDay();
        $previousSevenDaysStart = now()->subDays(13)->startOfDay();
        $previousWindowEnd = now()->subDays(6)->startOfDay();
        $user = $request->user();

        $payload = Cache::remember(
            $this->dashboardCacheKey((int) $user->id),
            now()->addSeconds(10),
            function () use (
                $todayStart,
                $tomorrowStart,
                $sevenDaysStart,
                $previousSevenDaysStart,
                $previousWindowEnd
            ): array {
                $todaySales = (float) DB::table('payments')
                    ->where('paid_at', '>=', $todayStart)
                    ->where('paid_at', '<', $tomorrowStart)
                    ->where('status', 'PAID')
                    ->sum('amount');

                $todayBills = (int) DB::table('payments')
                    ->where('paid_at', '>=', $todayStart)
                    ->where('paid_at', '<', $tomorrowStart)
                    ->where('status', 'PAID')
                    ->distinct('bill_id')
                    ->count('bill_id');

                $todayPurchaseTotal = (float) DB::table('ingredient_stock_movements')
                    ->whereIn('movement_type', ['INITIAL', 'ADJUST_IN'])
                    ->where('created_at', '>=', $todayStart)
                    ->where('created_at', '<', $tomorrowStart)
                    ->sum('total_cost');

                $todayEstimatedCogs = (float) DB::table('bill_items')
                    ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
                    ->join('payments', 'payments.bill_id', '=', 'bills.id')
                    ->join('menus', 'menus.id', '=', 'bill_items.menu_id')
                    ->leftJoin('ingredients', 'ingredients.id', '=', 'menus.stock_item_id')
                    ->where('payments.paid_at', '>=', $todayStart)
                    ->where('payments.paid_at', '<', $tomorrowStart)
                    ->where('payments.status', 'PAID')
                    ->sum(DB::raw(
                        'bill_items.qty * COALESCE(menus.stock_deduction_qty, 0) * COALESCE(NULLIF(ingredients.last_purchase_price, 0), ingredients.purchase_price, 0)'
                    ));

                $inventoryItemsCount = Ingredient::query()->count();
                $lowStockItemsCount = Ingredient::query()
                    ->whereColumn('current_stock', '<=', 'minimum_stock')
                    ->count();
                $outOfStockItemsCount = Ingredient::query()
                    ->where('current_stock', '<=', 0)
                    ->count();
                $inventoryAssetValue = (float) Ingredient::query()
                    ->selectRaw('SUM(current_stock * COALESCE(NULLIF(last_purchase_price, 0), purchase_price, 0)) as total_value')
                    ->value('total_value');
                $openShoppingNotesCount = ShoppingNote::query()
                    ->where('status', 'OPEN')
                    ->count();
                $shoppingEstimateTotal = (float) ShoppingNote::query()
                    ->where('status', 'OPEN')
                    ->selectRaw('SUM(COALESCE(requested_qty, 0) * COALESCE(estimated_unit_price, 0)) as total_value')
                    ->value('total_value');

                $previousSales = (float) DB::table('payments')
                    ->where('paid_at', '>=', $previousSevenDaysStart)
                    ->where('paid_at', '<', $previousWindowEnd)
                    ->where('status', 'PAID')
                    ->sum('amount');

                $currentSevenDaySales = (float) DB::table('payments')
                    ->where('paid_at', '>=', $sevenDaysStart)
                    ->where('paid_at', '<', $tomorrowStart)
                    ->where('status', 'PAID')
                    ->sum('amount');

                $salesGrowth = $previousSales <= 0
                    ? ($currentSevenDaySales > 0 ? 100.0 : 0.0)
                    : (($currentSevenDaySales - $previousSales) / $previousSales) * 100;

                $dailyTrend = DB::table('payments')
                    ->where('paid_at', '>=', $sevenDaysStart)
                    ->where('paid_at', '<', $tomorrowStart)
                    ->where('status', 'PAID')
                    ->selectRaw('DATE(paid_at) as date')
                    ->selectRaw('SUM(amount) as gross_total')
                    ->selectRaw('COUNT(DISTINCT bill_id) as paid_bills_count')
                    ->groupBy(DB::raw('DATE(paid_at)'))
                    ->orderBy('date')
                    ->get()
                    ->keyBy('date');

                $trendPayload = collect(range(0, 6))
                    ->map(function (int $offset) use ($dailyTrend) {
                        $date = now()->copy()->subDays(6 - $offset)->toDateString();
                        $row = $dailyTrend->get($date);

                        return [
                            'date' => $date,
                            'label' => now()->parse($date)->format('d M'),
                            'net_sales' => (float) ($row->gross_total ?? 0),
                            'paid_bills_count' => (int) ($row->paid_bills_count ?? 0),
                        ];
                    })
                    ->values();

                $topItems = DB::table('bill_items')
                    ->leftJoin('menus', 'menus.id', '=', 'bill_items.menu_id')
                    ->where('bill_items.created_at', '>=', $todayStart)
                    ->where('bill_items.created_at', '<', $tomorrowStart)
                    ->select('bill_items.menu_name')
                    ->selectRaw('COALESCE(menus.station_type, \'GENERAL\') as station_type')
                    ->selectRaw('SUM(bill_items.qty) as qty_sold')
                    ->selectRaw('SUM(bill_items.line_total) as gross_total')
                    ->groupBy('bill_items.menu_name', 'menus.station_type')
                    ->orderByDesc('qty_sold')
                    ->limit(8)
                    ->get()
                    ->map(fn ($row) => [
                        'menu_name' => $row->menu_name,
                        'station_type' => $row->station_type,
                        'qty_sold' => (int) $row->qty_sold,
                        'gross_total' => (float) $row->gross_total,
                    ])
                    ->values();

                $paymentMethods = DB::table('payments')
                    ->where('paid_at', '>=', $todayStart)
                    ->where('paid_at', '<', $tomorrowStart)
                    ->where('status', 'PAID')
                    ->select('payment_method')
                    ->selectRaw('SUM(amount) as total')
                    ->groupBy('payment_method')
                    ->orderByDesc('total')
                    ->get()
                    ->map(fn ($row) => [
                        'payment_method' => $row->payment_method,
                        'total' => (float) $row->total,
                    ])
                    ->values();

                $billTypes = DB::table('bills')
                    ->where('created_at', '>=', $todayStart)
                    ->where('created_at', '<', $tomorrowStart)
                    ->select('bill_type')
                    ->selectRaw('COUNT(*) as bills_count')
                    ->selectRaw('SUM(grand_total) as gross_total')
                    ->groupBy('bill_type')
                    ->orderByDesc('bills_count')
                    ->get()
                    ->map(fn ($row) => [
                        'bill_type' => $row->bill_type,
                        'bills_count' => (int) $row->bills_count,
                        'gross_total' => (float) $row->gross_total,
                    ])
                    ->values();

                $kitchenStatusCounts = OrderItem::query()
                    ->where('station_type', 'KITCHEN')
                    ->selectRaw("SUM(CASE WHEN status = 'WAITING' THEN 1 ELSE 0 END) as waiting_count")
                    ->selectRaw("SUM(CASE WHEN status IN ('ACCEPTED', 'COOKING') THEN 1 ELSE 0 END) as processing_count")
                    ->selectRaw("SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) as ready_count")
                    ->first();

                $barStatusCounts = OrderItem::query()
                    ->where('station_type', 'BAR')
                    ->selectRaw("SUM(CASE WHEN status = 'WAITING' THEN 1 ELSE 0 END) as waiting_count")
                    ->selectRaw("SUM(CASE WHEN status IN ('ACCEPTED', 'PREPARING') THEN 1 ELSE 0 END) as processing_count")
                    ->selectRaw("SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) as ready_count")
                    ->first();

                $waiterStatusCounts = OrderItem::query()
                    ->selectRaw("SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) as ready_to_serve_count")
                    ->selectRaw(
                        "SUM(CASE WHEN status = 'SERVED' AND served_at >= ? AND served_at < ? THEN 1 ELSE 0 END) as served_today_count",
                        [$todayStart, $tomorrowStart],
                    )
                    ->first();

                $reservationRemindersEnabled = $this->boolSetting('reservation_reminders_enabled', true);
                $reservationReminderMinutesBefore = $this->intSetting('reservation_reminder_minutes_before', 120);
                $eventRemindersEnabled = $this->boolSetting('event_reminders_enabled', true);
                $eventReminderMinutesBefore = $this->intSetting('event_reminder_minutes_before', 1440);
                $dashboardReminderLimit = $this->intSetting('dashboard_reminder_limit', 4);

                $reservationItems = collect();
                $reservationOverdueCount = 0;
                if ($reservationRemindersEnabled) {
                    $reservationWindowEnd = now()->copy()->addMinutes($reservationReminderMinutesBefore);
                    $reservationItems = Reservation::query()
                        ->with(['customer:id,name', 'table:id,code,name'])
                        ->where('status', 'BOOKED')
                        ->where('reserved_at', '>=', now()->copy()->subHours(6))
                        ->where('reserved_at', '<=', $reservationWindowEnd)
                        ->orderBy('reserved_at')
                        ->get()
                        ->map(function (Reservation $reservation) {
                            $minutesRemaining = now()->diffInMinutes($reservation->reserved_at, false);
                            $priority = $minutesRemaining < 0
                                ? 'overdue'
                                : ($minutesRemaining <= 30 ? 'soon' : 'upcoming');

                            return [
                                'type' => 'reservation',
                                'id' => $reservation->id,
                                'code' => $reservation->reservation_code,
                                'title' => $minutesRemaining < 0
                                    ? 'Reservasi belum ditindak'
                                    : 'Reservasi akan datang',
                                'subtitle' => trim(($reservation->customer?->name ?? 'Pelanggan reservasi')
                                    .' · '
                                    .($reservation->table?->code ?? 'Tanpa meja')),
                                'customer_name' => $reservation->customer?->name,
                                'table_code' => $reservation->table?->code,
                                'table_name' => $reservation->table?->name,
                                'guest_count' => (int) $reservation->guest_count,
                                'status' => $reservation->status,
                                'scheduled_at' => optional($reservation->reserved_at)->toIso8601String(),
                                'minutes_remaining' => $minutesRemaining,
                                'priority' => $priority,
                                'notes' => $reservation->notes,
                                'action_route' => '/settings/master-data/reservations',
                            ];
                        })
                        ->sortBy([
                            ['priority', 'asc'],
                            ['scheduled_at', 'asc'],
                        ])
                        ->values();

                    $reservationOverdueCount = $reservationItems
                        ->where('priority', 'overdue')
                        ->count();

                    $reservationItems = $reservationItems
                        ->take($dashboardReminderLimit)
                        ->values();
                }

                $eventItems = collect();
                if ($eventRemindersEnabled) {
                    $eventWindowEnd = now()->copy()->addMinutes($eventReminderMinutesBefore);
                    $eventItems = Bill::query()
                        ->with(['table:id,code,name', 'customer:id,name'])
                        ->where('bill_type', 'CATERING')
                        ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID'])
                        ->whereNotNull('event_scheduled_at')
                        ->where('event_scheduled_at', '>=', now()->copy()->subDays(7))
                        ->where('event_scheduled_at', '<=', $eventWindowEnd)
                        ->orderBy('event_scheduled_at')
                        ->get()
                        ->map(function (Bill $bill) {
                            $minutesRemaining = now()->diffInMinutes($bill->event_scheduled_at, false);
                            $priority = $minutesRemaining < 0
                                ? 'overdue'
                                : ($minutesRemaining <= 120 ? 'soon' : 'upcoming');

                            return [
                                'type' => 'event',
                                'id' => $bill->id,
                                'code' => $bill->bill_no,
                                'title' => $minutesRemaining < 0
                                    ? 'Event belum ditindak'
                                    : 'Event akan datang',
                                'subtitle' => trim((($bill->customer?->name ?: $bill->customer_name) ?: 'Pelanggan event')
                                    .' · '
                                    .($bill->table?->code ?? 'Tanpa meja')),
                                'customer_name' => $bill->customer?->name ?: $bill->customer_name,
                                'table_code' => $bill->table?->code,
                                'table_name' => $bill->table?->name,
                                'guest_count' => (int) $bill->guest_count,
                                'status' => $bill->status,
                                'scheduled_at' => optional($bill->event_scheduled_at)->toIso8601String(),
                                'minutes_remaining' => $minutesRemaining,
                                'priority' => $priority,
                                'notes' => null,
                                'action_route' => '/bills',
                            ];
                        })
                        ->sortBy([
                            ['priority', 'asc'],
                            ['scheduled_at', 'asc'],
                        ])
                        ->take($dashboardReminderLimit)
                        ->values();
                }

                $reminderItems = $reservationItems
                    ->concat($eventItems)
                    ->sortBy('scheduled_at')
                    ->take(max(1, $dashboardReminderLimit))
                    ->values();

                return [
                    'summary' => [
                        'total_tables' => DB::table('tables')->count(),
                        'available_tables' => DB::table('tables')->where('status', 'AVAILABLE')->count(),
                        'occupied_tables' => DB::table('tables')->where('status', 'OPEN_BILL')->count(),
                        'open_bills' => DB::table('bills')->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID'])->count(),
                        'ready_to_pay_bills' => DB::table('bills')->whereIn('status', ['READY_TO_PAY', 'PARTIALLY_PAID'])->count(),
                        'today_sales' => $todaySales,
                        'today_bills' => $todayBills,
                        'average_bill' => $todayBills > 0 ? round($todaySales / $todayBills, 2) : 0,
                        'sales_growth_percent' => round($salesGrowth, 2),
                        'today_purchase_total' => $todayPurchaseTotal,
                        'today_estimated_cogs' => $todayEstimatedCogs,
                        'today_estimated_profit' => $todaySales - $todayEstimatedCogs,
                    ],
                    'analytics' => [
                        // Cache plain arrays so every cache driver keeps the JSON
                        // contract as [] instead of serializing keyed collections as {}.
                        'sales_trend' => $trendPayload->all(),
                        'top_items' => $topItems->all(),
                        'payment_methods' => $paymentMethods->all(),
                        'bill_types' => $billTypes->all(),
                        'inventory' => [
                            'stock_items_count' => $inventoryItemsCount,
                            'low_stock_items_count' => $lowStockItemsCount,
                            'out_of_stock_items_count' => $outOfStockItemsCount,
                            'inventory_asset_value' => round($inventoryAssetValue, 2),
                            'open_shopping_notes_count' => $openShoppingNotesCount,
                            'shopping_estimate_total' => round($shoppingEstimateTotal, 2),
                        ],
                        'station_load' => [
                            'kitchen' => [
                                'waiting_count' => (int) ($kitchenStatusCounts->waiting_count ?? 0),
                                'processing_count' => (int) ($kitchenStatusCounts->processing_count ?? 0),
                                'ready_count' => (int) ($kitchenStatusCounts->ready_count ?? 0),
                            ],
                            'bar' => [
                                'waiting_count' => (int) ($barStatusCounts->waiting_count ?? 0),
                                'processing_count' => (int) ($barStatusCounts->processing_count ?? 0),
                                'ready_count' => (int) ($barStatusCounts->ready_count ?? 0),
                            ],
                            'waiter' => [
                                'ready_to_serve_count' => (int) ($waiterStatusCounts->ready_to_serve_count ?? 0),
                                'served_today_count' => (int) ($waiterStatusCounts->served_today_count ?? 0),
                            ],
                        ],
                    ],
                    'reminders' => [
                        'settings' => [
                            'reservation_reminders_enabled' => $reservationRemindersEnabled,
                            'reservation_reminder_minutes_before' => $reservationReminderMinutesBefore,
                            'event_reminders_enabled' => $eventRemindersEnabled,
                            'event_reminder_minutes_before' => $eventReminderMinutesBefore,
                            'dashboard_reminder_limit' => $dashboardReminderLimit,
                        ],
                        'summary' => [
                            'reservation_due_count' => $reservationItems->count(),
                            'reservation_overdue_count' => $reservationOverdueCount,
                            'event_due_count' => $eventItems->count(),
                            'total_due_count' => $reminderItems->count(),
                        ],
                        'items' => $reminderItems->all(),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }

    private function dashboardCacheKey(int $userId): string
    {
        return "dashboard:v1:user:{$userId}";
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = Setting::getValue($key, $default ? '1' : '0');

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'TRUE'], true);
    }

    private function intSetting(string $key, int $default): int
    {
        return (int) Setting::getValue($key, (string) $default);
    }
}
