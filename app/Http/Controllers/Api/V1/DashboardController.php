<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return lightweight dashboard data for the mobile app.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $todayStart = now()->startOfDay();
        $tomorrowStart = now()->addDay()->startOfDay();
        $sevenDaysStart = now()->subDays(6)->startOfDay();
        $previousSevenDaysStart = now()->subDays(13)->startOfDay();
        $previousWindowEnd = now()->subDays(6)->startOfDay();

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
            ->selectRaw("SUM(CASE WHEN status = 'SERVED' AND served_at >= ? AND served_at < ? THEN 1 ELSE 0 END) as served_today_count", [$todayStart, $tomorrowStart])
            ->first();

        $reservationRemindersEnabled = $this->boolSetting('reservation_reminders_enabled', true);
        $reservationReminderMinutesBefore = $this->intSetting('reservation_reminder_minutes_before', 120);
        $eventRemindersEnabled = $this->boolSetting('event_reminders_enabled', true);
        $dashboardReminderLimit = $this->intSetting('dashboard_reminder_limit', 4);

        $reservationItems = collect();
        if ($reservationRemindersEnabled) {
            $reservationWindowEnd = now()->copy()->addMinutes($reservationReminderMinutesBefore);
            $reservationItems = Reservation::query()
                ->with(['customer:id,name', 'table:id,code,name'])
                ->where('status', 'BOOKED')
                ->where('reserved_at', '>=', now())
                ->where('reserved_at', '<=', $reservationWindowEnd)
                ->orderBy('reserved_at')
                ->limit($dashboardReminderLimit)
                ->get()
                ->map(function (Reservation $reservation) {
                    $minutesRemaining = max(0, now()->diffInMinutes($reservation->reserved_at, false));

                    return [
                        'type' => 'reservation',
                        'id' => $reservation->id,
                        'code' => $reservation->reservation_code,
                        'title' => 'Reservasi akan datang',
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
                        'notes' => $reservation->notes,
                        'action_route' => '/settings/master-data/reservations',
                    ];
                })
                ->values();
        }

        $eventItems = collect();
        if ($eventRemindersEnabled) {
            $eventItems = Bill::query()
                ->with(['table:id,code,name'])
                ->where('bill_type', 'CATERING')
                ->whereIn('status', ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID'])
                ->orderBy('opened_at')
                ->limit($dashboardReminderLimit)
                ->get()
                ->map(function (Bill $bill) {
                    return [
                        'type' => 'event',
                        'id' => $bill->id,
                        'code' => $bill->bill_no,
                        'title' => 'Transaksi event aktif',
                        'subtitle' => trim(($bill->customer_name ?: 'Pelanggan event')
                            .' · '
                            .($bill->table?->code ?? 'Tanpa meja')),
                        'customer_name' => $bill->customer_name,
                        'table_code' => $bill->table?->code,
                        'table_name' => $bill->table?->name,
                        'guest_count' => (int) $bill->guest_count,
                        'status' => $bill->status,
                        'scheduled_at' => optional($bill->opened_at)->toIso8601String(),
                        'minutes_remaining' => null,
                        'notes' => null,
                        'action_route' => '/bills',
                    ];
                })
                ->values();
        }

        $reminderItems = $reservationItems
            ->concat($eventItems)
            ->sortBy('scheduled_at')
            ->take(max(1, $dashboardReminderLimit))
            ->values();

        return response()->json([
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
            ],
            'analytics' => [
                'sales_trend' => $trendPayload,
                'top_items' => $topItems,
                'payment_methods' => $paymentMethods,
                'bill_types' => $billTypes,
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
                    'dashboard_reminder_limit' => $dashboardReminderLimit,
                ],
                'summary' => [
                    'reservation_due_count' => $reservationItems->count(),
                    'event_due_count' => $eventItems->count(),
                    'total_due_count' => $reminderItems->count(),
                ],
                'items' => $reminderItems,
            ],
        ]);
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
