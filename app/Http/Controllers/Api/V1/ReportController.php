<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ReportController extends Controller
{
    public function salesSummary(Request $request): JsonResponse
    {
        return response()->json($this->buildSalesSummaryPayload($request));
    }

    public function exportSalesSummary(Request $request): StreamedResponse
    {
        $payload = $this->buildSalesSummaryPayload($request);
        $fileName = sprintf(
            'sales-summary-%s-to-%s.csv',
            $payload['filters']['date_from'],
            $payload['filters']['date_to'],
        );

        return response()->streamDownload(function () use ($payload) {
            $handle = fopen('php://output', 'w');
            $writeRow = function (array $row) use ($handle): void {
                fputcsv($handle, array_map([$this, 'sanitizeSpreadsheetCell'], $row));
            };

            $writeRow(['section', 'key', 'value', 'value_2', 'value_3', 'value_4', 'value_5']);

            foreach ($payload['summary'] as $key => $value) {
                $writeRow(['summary', $key, $value]);
            }

            foreach ($payload['payment_methods'] as $row) {
                $writeRow([
                    'payment_methods',
                    $row['payment_method'],
                    $row['gross_total'],
                    $row['refund_total'],
                    $row['void_total'],
                    $row['net_total'],
                    $row['payments_count'],
                ]);
            }

            foreach ($payload['bill_types'] as $row) {
                $writeRow([
                    'bill_types',
                    $row['bill_type'],
                    $row['gross_total'],
                    $row['bills_count'],
                ]);
            }

            foreach ($payload['top_items'] as $row) {
                $writeRow([
                    'top_items',
                    $row['menu_name'],
                    $row['menu_id'],
                    $row['total_qty'],
                    $row['gross_total'],
                ]);
            }

            foreach ($payload['category_sales'] as $row) {
                $writeRow([
                    'category_sales',
                    $row['category_name'],
                    $row['bills_count'],
                    $row['total_qty'],
                    $row['gross_total'],
                ]);
            }

            foreach ($payload['daily_trend'] as $row) {
                $writeRow([
                    'daily_trend',
                    $row['date'],
                    $row['gross_total'],
                    $row['refund_total'],
                    $row['net_total'],
                    $row['paid_bills_count'],
                ]);
            }

            foreach ($payload['top_tables'] as $row) {
                $writeRow([
                    'top_tables',
                    $row['table_code'] ?? '-',
                    $row['table_name'] ?? '-',
                    $row['gross_total'],
                    $row['bills_count'],
                ]);
            }

            foreach ($payload['hourly_trend'] as $row) {
                $writeRow([
                    'hourly_trend',
                    $row['hour_label'],
                    $row['gross_total'],
                    $row['paid_bills_count'],
                ]);
            }

            foreach ($payload['top_customers'] as $row) {
                $writeRow([
                    'top_customers',
                    $row['customer_name'],
                    $row['bills_count'],
                    $row['gross_total'],
                    $row['average_bill'],
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportSalesSummaryExcel(Request $request): BinaryFileResponse
    {
        $payload = $this->buildSalesSummaryPayload($request);
        $payload['transactions'] = $this->buildSalesTransactions($payload['filters']);
        $payload['restaurant'] = RestaurantProfileController::profilePayload();
        $payload['generated_at'] = now()->toDateTimeString();
        $fileName = sprintf(
            'sales-summary-%s-to-%s.xlsx',
            $payload['filters']['date_from'],
            $payload['filters']['date_to'],
        );
        $tempPath = tempnam(sys_get_temp_dir(), 'sales-summary-');
        abort_if($tempPath === false, 500, 'Gagal menyiapkan file export laporan.');

        $xlsxPath = $tempPath.'.xlsx';
        @unlink($tempPath);

        $this->buildSalesSummaryXlsx($payload, $xlsxPath);

        return response()->download(
            $xlsxPath,
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function buildSalesTransactions(array $filters): array
    {
        $rangeStart = Carbon::parse($filters['date_from'])->startOfDay();
        $rangeEnd = Carbon::parse($filters['date_to'])->addDay()->startOfDay();

        return Bill::query()
            ->with([
                'table:id,code,name',
                'customer:id,name',
                'payments' => fn ($query) => $query
                    ->where('paid_at', '>=', $rangeStart)
                    ->where('paid_at', '<', $rangeEnd)
                    ->orderBy('paid_at'),
            ])
            ->whereHas('payments', fn ($query) => $query
                ->where('paid_at', '>=', $rangeStart)
                ->where('paid_at', '<', $rangeEnd))
            ->orderBy('closed_at')
            ->orderBy('id')
            ->get()
            ->map(function (Bill $bill): array {
                $paidPayments = $bill->payments->where('status', 'PAID');
                $refundPayments = $bill->payments->where('status', 'REFUND');
                $voidPayments = $bill->payments->where('status', 'VOID');
                $grossPaid = (float) $paidPayments->sum('amount');
                $refundTotal = (float) $refundPayments->sum('amount');

                return [
                    'paid_at' => optional($bill->payments->max('paid_at'))->toDateTimeString(),
                    'bill_no' => $bill->bill_no,
                    'bill_type' => $bill->bill_type,
                    'table' => $bill->table
                        ? trim($bill->table->code.' - '.$bill->table->name)
                        : 'Tanpa meja',
                    'customer_name' => $bill->customer?->name
                        ?: $bill->customer_name
                        ?: 'Pelanggan umum',
                    'guest_count' => (int) $bill->guest_count,
                    'payment_methods' => $paidPayments
                        ->pluck('payment_method')
                        ->filter()
                        ->unique()
                        ->implode(', '),
                    'subtotal' => (float) $bill->subtotal,
                    'discount_total' => (float) $bill->discount_total,
                    'tax_total' => (float) $bill->tax_total,
                    'service_total' => (float) $bill->service_total,
                    'grand_total' => (float) $bill->grand_total,
                    'gross_paid' => $grossPaid,
                    'refund_total' => $refundTotal,
                    'void_total' => (float) $voidPayments->sum('amount'),
                    'net_sales' => $grossPaid - $refundTotal,
                    'status' => $bill->status,
                ];
            })
            ->values()
            ->all();
    }

    private function sanitizeSpreadsheetCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Prevent spreadsheet formulas from customer-controlled export values.
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function buildSalesSummaryPayload(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        abort_if($dateFrom > $dateTo, 422, 'Tanggal mulai tidak boleh melebihi tanggal akhir.');

        $rangeStart = Carbon::parse($dateFrom)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo)->addDay()->startOfDay();
        $periodDays = $rangeStart->diffInDays($rangeEnd);
        $previousRangeStart = (clone $rangeStart)->subDays($periodDays);
        $previousRangeEnd = clone $rangeStart;

        $paymentsBase = Payment::query()
            ->where('paid_at', '>=', $rangeStart)
            ->where('paid_at', '<', $rangeEnd);

        $previousPaymentsBase = Payment::query()
            ->where('paid_at', '>=', $previousRangeStart)
            ->where('paid_at', '<', $previousRangeEnd);

        $grossSales = (clone $paymentsBase)
            ->where('status', 'PAID')
            ->sum('amount');

        $refundTotal = (clone $paymentsBase)
            ->where('status', 'REFUND')
            ->sum('amount');

        $voidTotal = (clone $paymentsBase)
            ->where('status', 'VOID')
            ->sum('amount');

        $paidBillsCount = (clone $paymentsBase)
            ->where('status', 'PAID')
            ->distinct('bill_id')
            ->count('bill_id');

        $refundedBillsCount = (clone $paymentsBase)
            ->where('status', 'REFUND')
            ->distinct('bill_id')
            ->count('bill_id');

        $paymentMethods = (clone $paymentsBase)
            ->select('payment_method')
            ->selectRaw("SUM(CASE WHEN status = 'PAID' THEN amount ELSE 0 END) as gross_total")
            ->selectRaw("SUM(CASE WHEN status = 'REFUND' THEN amount ELSE 0 END) as refund_total")
            ->selectRaw("SUM(CASE WHEN status = 'VOID' THEN amount ELSE 0 END) as void_total")
            ->selectRaw("COUNT(CASE WHEN status = 'PAID' THEN 1 END) as payments_count")
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
                'refund_total' => number_format((float) $row->refund_total, 2, '.', ''),
                'void_total' => number_format((float) $row->void_total, 2, '.', ''),
                'net_total' => number_format((float) $row->gross_total - (float) $row->refund_total, 2, '.', ''),
                'payments_count' => (int) $row->payments_count,
            ])
            ->values();

        $billTypes = DB::table('payments')
            ->join('bills', 'bills.id', '=', 'payments.bill_id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->select('bills.bill_type')
            ->selectRaw('SUM(payments.amount) as gross_total')
            ->selectRaw('COUNT(DISTINCT payments.bill_id) as bills_count')
            ->groupBy('bills.bill_type')
            ->orderBy('gross_total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'bill_type' => $row->bill_type,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
                'bills_count' => (int) $row->bills_count,
            ])
            ->values();

        $topItems = DB::table('bill_items')
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->join('payments', 'payments.bill_id', '=', 'bills.id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->select('bill_items.menu_id', 'bill_items.menu_name')
            ->selectRaw('SUM(bill_items.qty) as total_qty')
            ->selectRaw('SUM(bill_items.line_total) as gross_total')
            ->groupBy('bill_items.menu_id', 'bill_items.menu_name')
            ->orderBy('total_qty', 'desc')
            ->orderBy('gross_total', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'menu_id' => $row->menu_id,
                'menu_name' => $row->menu_name,
                'total_qty' => (int) $row->total_qty,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
            ])
            ->values();

        $categorySales = DB::table('bill_items')
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->join('payments', 'payments.bill_id', '=', 'bills.id')
            ->leftJoin('menus', 'menus.id', '=', 'bill_items.menu_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menus.category_id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->selectRaw("COALESCE(menu_categories.name, 'Tanpa Kategori') as category_name")
            ->selectRaw('COUNT(DISTINCT payments.bill_id) as bills_count')
            ->selectRaw('SUM(bill_items.qty) as total_qty')
            ->selectRaw('SUM(bill_items.line_total) as gross_total')
            ->groupBy(DB::raw("COALESCE(menu_categories.name, 'Tanpa Kategori')"))
            ->orderBy('gross_total', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'category_name' => $row->category_name,
                'bills_count' => (int) $row->bills_count,
                'total_qty' => (int) $row->total_qty,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
            ])
            ->values();

        $dailyTrend = DB::table('payments')
            ->where('paid_at', '>=', $rangeStart)
            ->where('paid_at', '<', $rangeEnd)
            ->selectRaw('DATE(paid_at) as report_date')
            ->selectRaw("SUM(CASE WHEN status = 'PAID' THEN amount ELSE 0 END) as gross_total")
            ->selectRaw("SUM(CASE WHEN status = 'REFUND' THEN amount ELSE 0 END) as refund_total")
            ->selectRaw("COUNT(DISTINCT CASE WHEN status = 'PAID' THEN bill_id END) as paid_bills_count")
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->report_date,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
                'refund_total' => number_format((float) $row->refund_total, 2, '.', ''),
                'net_total' => number_format((float) $row->gross_total - (float) $row->refund_total, 2, '.', ''),
                'paid_bills_count' => (int) $row->paid_bills_count,
            ])
            ->values();

        $topTables = DB::table('payments')
            ->join('bills', 'bills.id', '=', 'payments.bill_id')
            ->leftJoin('tables', 'tables.id', '=', 'bills.table_id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->select('tables.id as table_id', 'tables.code as table_code', 'tables.name as table_name')
            ->selectRaw('SUM(payments.amount) as gross_total')
            ->selectRaw('COUNT(DISTINCT payments.bill_id) as bills_count')
            ->groupBy('tables.id', 'tables.code', 'tables.name')
            ->orderBy('gross_total', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'table_id' => $row->table_id,
                'table_code' => $row->table_code,
                'table_name' => $row->table_name,
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
                'bills_count' => (int) $row->bills_count,
            ])
            ->values();

        $hourlyExpression = $this->hourExpression();
        $hourlyTrend = DB::table('payments')
            ->where('paid_at', '>=', $rangeStart)
            ->where('paid_at', '<', $rangeEnd)
            ->where('status', 'PAID')
            ->selectRaw("{$hourlyExpression} as report_hour")
            ->selectRaw('SUM(amount) as gross_total')
            ->selectRaw('COUNT(DISTINCT bill_id) as paid_bills_count')
            ->groupBy(DB::raw($hourlyExpression))
            ->orderByDesc('gross_total')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'hour' => (int) $row->report_hour,
                'hour_label' => sprintf('%02d:00 - %02d:59', (int) $row->report_hour, (int) $row->report_hour),
                'gross_total' => number_format((float) $row->gross_total, 2, '.', ''),
                'paid_bills_count' => (int) $row->paid_bills_count,
            ])
            ->values();

        $topCustomers = DB::table('payments')
            ->join('bills', 'bills.id', '=', 'payments.bill_id')
            ->leftJoin('customers', 'customers.id', '=', 'bills.customer_id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->selectRaw("COALESCE(customers.name, bills.customer_name, 'Pelanggan umum') as customer_name")
            ->selectRaw('SUM(payments.amount) as gross_total')
            ->selectRaw('COUNT(DISTINCT payments.bill_id) as bills_count')
            ->groupBy(DB::raw("COALESCE(customers.name, bills.customer_name, 'Pelanggan umum')"))
            ->orderByDesc('gross_total')
            ->limit(6)
            ->get()
            ->map(function ($row) {
                $grossTotal = (float) $row->gross_total;
                $billsCount = (int) $row->bills_count;

                return [
                    'customer_name' => $row->customer_name,
                    'gross_total' => number_format($grossTotal, 2, '.', ''),
                    'bills_count' => $billsCount,
                    'average_bill' => number_format($billsCount > 0 ? $grossTotal / $billsCount : 0, 2, '.', ''),
                ];
            })
            ->values();

        $netSales = (float) $grossSales - (float) $refundTotal;
        $previousGrossSales = (float) (clone $previousPaymentsBase)
            ->where('status', 'PAID')
            ->sum('amount');
        $previousRefundTotal = (float) (clone $previousPaymentsBase)
            ->where('status', 'REFUND')
            ->sum('amount');
        $previousNetSales = $previousGrossSales - $previousRefundTotal;
        $previousPaidBillsCount = (clone $previousPaymentsBase)
            ->where('status', 'PAID')
            ->distinct('bill_id')
            ->count('bill_id');
        $purchaseTotal = (float) DB::table('ingredient_stock_movements')
            ->whereIn('movement_type', ['INITIAL', 'ADJUST_IN'])
            ->where('created_at', '>=', $rangeStart)
            ->where('created_at', '<', $rangeEnd)
            ->sum('total_cost');
        $previousPurchaseTotal = (float) DB::table('ingredient_stock_movements')
            ->whereIn('movement_type', ['INITIAL', 'ADJUST_IN'])
            ->where('created_at', '>=', $previousRangeStart)
            ->where('created_at', '<', $previousRangeEnd)
            ->sum('total_cost');

        $estimatedCogs = (float) DB::table('bill_items')
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->join('payments', 'payments.bill_id', '=', 'bills.id')
            ->join('menus', 'menus.id', '=', 'bill_items.menu_id')
            ->leftJoin('ingredients', 'ingredients.id', '=', 'menus.stock_item_id')
            ->where('payments.paid_at', '>=', $rangeStart)
            ->where('payments.paid_at', '<', $rangeEnd)
            ->where('payments.status', 'PAID')
            ->sum(DB::raw('bill_items.qty * COALESCE(menus.stock_deduction_qty, 0) * COALESCE(NULLIF(ingredients.last_purchase_price, 0), ingredients.purchase_price, 0)'));
        $previousEstimatedCogs = (float) DB::table('bill_items')
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->join('payments', 'payments.bill_id', '=', 'bills.id')
            ->join('menus', 'menus.id', '=', 'bill_items.menu_id')
            ->leftJoin('ingredients', 'ingredients.id', '=', 'menus.stock_item_id')
            ->where('payments.paid_at', '>=', $previousRangeStart)
            ->where('payments.paid_at', '<', $previousRangeEnd)
            ->where('payments.status', 'PAID')
            ->sum(DB::raw('bill_items.qty * COALESCE(menus.stock_deduction_qty, 0) * COALESCE(NULLIF(ingredients.last_purchase_price, 0), ingredients.purchase_price, 0)'));

        $previousAverageBill = $previousPaidBillsCount > 0
            ? $previousNetSales / $previousPaidBillsCount
            : 0.0;
        $currentAverageBill = $paidBillsCount > 0
            ? $netSales / $paidBillsCount
            : 0.0;
        $previousEstimatedProfit = $previousNetSales - $previousEstimatedCogs;
        $currentEstimatedProfit = $netSales - $estimatedCogs;

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'period_days' => $periodDays,
            ],
            'summary' => [
                'gross_sales' => number_format((float) $grossSales, 2, '.', ''),
                'refund_total' => number_format((float) $refundTotal, 2, '.', ''),
                'void_total' => number_format((float) $voidTotal, 2, '.', ''),
                'net_sales' => number_format($netSales, 2, '.', ''),
                'purchase_total' => number_format($purchaseTotal, 2, '.', ''),
                'estimated_cogs' => number_format($estimatedCogs, 2, '.', ''),
                'estimated_profit' => number_format($currentEstimatedProfit, 2, '.', ''),
                'paid_bills_count' => $paidBillsCount,
                'refunded_bills_count' => $refundedBillsCount,
                'average_bill' => number_format($currentAverageBill, 2, '.', ''),
            ],
            'comparison' => [
                'previous_period' => [
                    'date_from' => $previousRangeStart->toDateString(),
                    'date_to' => $previousRangeEnd->copy()->subDay()->toDateString(),
                    'gross_sales' => number_format($previousGrossSales, 2, '.', ''),
                    'refund_total' => number_format($previousRefundTotal, 2, '.', ''),
                    'net_sales' => number_format($previousNetSales, 2, '.', ''),
                    'purchase_total' => number_format($previousPurchaseTotal, 2, '.', ''),
                    'estimated_cogs' => number_format($previousEstimatedCogs, 2, '.', ''),
                    'estimated_profit' => number_format($previousEstimatedProfit, 2, '.', ''),
                    'paid_bills_count' => $previousPaidBillsCount,
                    'average_bill' => number_format($previousAverageBill, 2, '.', ''),
                ],
                'net_sales' => $this->buildMetricComparison($netSales, $previousNetSales),
                'paid_bills_count' => $this->buildMetricComparison((float) $paidBillsCount, (float) $previousPaidBillsCount, 0),
                'average_bill' => $this->buildMetricComparison($currentAverageBill, $previousAverageBill),
                'estimated_profit' => $this->buildMetricComparison($currentEstimatedProfit, $previousEstimatedProfit),
                'refund_total' => $this->buildMetricComparison($refundTotal, $previousRefundTotal),
            ],
            'payment_methods' => $paymentMethods,
            'bill_types' => $billTypes,
            'top_items' => $topItems,
            'category_sales' => $categorySales,
            'daily_trend' => $dailyTrend,
            'top_tables' => $topTables,
            'hourly_trend' => $hourlyTrend,
            'top_customers' => $topCustomers,
        ];
    }

    private function buildSalesSummaryRows(array $payload): array
    {
        $rows = [
            ['Bagian', 'Kunci', 'Nilai', 'Nilai 2', 'Nilai 3', 'Nilai 4', 'Nilai 5'],
        ];

        foreach ($payload['summary'] as $key => $value) {
            $rows[] = ['Ringkasan', (string) $key, (string) $value];
        }

        foreach ($payload['comparison']['previous_period'] as $key => $value) {
            $rows[] = ['Periode Sebelumnya', (string) $key, (string) $value];
        }

        foreach (['net_sales', 'paid_bills_count', 'average_bill', 'estimated_profit', 'refund_total'] as $metricKey) {
            $metric = $payload['comparison'][$metricKey] ?? [];
            $rows[] = [
                'Perbandingan',
                $metricKey,
                (string) ($metric['current'] ?? '0'),
                (string) ($metric['previous'] ?? '0'),
                (string) ($metric['delta'] ?? '0'),
                (string) ($metric['delta_percent'] ?? '0'),
                (string) ($metric['direction'] ?? 'flat'),
            ];
        }

        foreach ($payload['payment_methods'] as $row) {
            $rows[] = [
                'Metode Pembayaran',
                (string) $row['payment_method'],
                (string) $row['gross_total'],
                (string) $row['refund_total'],
                (string) $row['void_total'],
                (string) $row['net_total'],
                (string) $row['payments_count'],
            ];
        }

        foreach ($payload['bill_types'] as $row) {
            $rows[] = [
                'Tipe Pesanan',
                (string) $row['bill_type'],
                (string) $row['gross_total'],
                (string) $row['bills_count'],
            ];
        }

        foreach ($payload['top_items'] as $row) {
            $rows[] = [
                'Menu Terlaris',
                (string) $row['menu_name'],
                (string) $row['menu_id'],
                (string) $row['total_qty'],
                (string) $row['gross_total'],
            ];
        }

        foreach ($payload['category_sales'] as $row) {
            $rows[] = [
                'Kategori Penjualan',
                (string) $row['category_name'],
                (string) $row['bills_count'],
                (string) $row['total_qty'],
                (string) $row['gross_total'],
            ];
        }

        foreach ($payload['daily_trend'] as $row) {
            $rows[] = [
                'Tren Harian',
                (string) $row['date'],
                (string) $row['gross_total'],
                (string) $row['refund_total'],
                (string) $row['net_total'],
                (string) $row['paid_bills_count'],
            ];
        }

        foreach ($payload['top_tables'] as $row) {
            $rows[] = [
                'Performa Meja',
                (string) ($row['table_code'] ?? '-'),
                (string) ($row['table_name'] ?? '-'),
                (string) $row['gross_total'],
                (string) $row['bills_count'],
            ];
        }

        foreach ($payload['hourly_trend'] as $row) {
            $rows[] = [
                'Jam Ramai',
                (string) $row['hour_label'],
                (string) $row['gross_total'],
                (string) $row['paid_bills_count'],
            ];
        }

        foreach ($payload['top_customers'] as $row) {
            $rows[] = [
                'Pelanggan Utama',
                (string) $row['customer_name'],
                (string) $row['bills_count'],
                (string) $row['gross_total'],
                (string) $row['average_bill'],
            ];
        }

        return $rows;
    }

    private function hourExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', paid_at) AS INTEGER)"
            : 'HOUR(paid_at)';
    }

    private function buildMetricComparison(float $current, float $previous, int $decimals = 2): array
    {
        $delta = $current - $previous;
        $deltaPercent = $previous == 0.0
            ? ($current == 0.0 ? 0.0 : 100.0)
            : (($delta / $previous) * 100);

        return [
            'current' => number_format($current, $decimals, '.', ''),
            'previous' => number_format($previous, $decimals, '.', ''),
            'delta' => number_format($delta, $decimals, '.', ''),
            'delta_percent' => number_format($deltaPercent, 2, '.', ''),
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }

    private function buildSalesSummaryXlsx(array $payload, string $targetPath): void
    {
        $archive = new ZipArchive;
        $result = $archive->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_if($result !== true, 500, 'Gagal membuat file Excel laporan penjualan.');

        $sheets = $this->buildSalesSummarySheets($payload);

        $archive->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
        $archive->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $archive->addFromString('docProps/app.xml', $this->appPropertiesXml($sheets));
        $archive->addFromString('docProps/core.xml', $this->corePropertiesXml());
        $archive->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml($sheets));
        $archive->addFromString('xl/styles.xml', $this->stylesXml());

        foreach ($sheets as $index => $sheet) {
            $archive->addFromString(
                sprintf('xl/worksheets/sheet%d.xml', $index + 1),
                $this->worksheetXml($sheet),
            );
        }

        $archive->close();
    }

    private function buildSalesSummarySheets(array $payload): array
    {
        $restaurantName = trim((string) ($payload['restaurant']['restaurant_name'] ?? 'Warung Babeh'));
        $period = sprintf(
            'Periode %s s.d. %s',
            Carbon::parse($payload['filters']['date_from'])->format('d/m/Y'),
            Carbon::parse($payload['filters']['date_to'])->format('d/m/Y'),
        );
        $generatedAt = Carbon::parse($payload['generated_at'])->format('d/m/Y H:i');

        $summaryLabels = [
            'gross_sales' => 'Penjualan Kotor',
            'refund_total' => 'Pengembalian Dana',
            'void_total' => 'Pembayaran Dibatalkan',
            'net_sales' => 'Penjualan Bersih',
            'purchase_total' => 'Total Belanja Stok',
            'estimated_cogs' => 'Estimasi HPP',
            'estimated_profit' => 'Estimasi Laba Kotor',
            'paid_bills_count' => 'Jumlah Tagihan Lunas',
            'refunded_bills_count' => 'Jumlah Tagihan Refund',
            'average_bill' => 'Rata-rata Nilai Tagihan',
        ];
        $countMetrics = ['paid_bills_count', 'refunded_bills_count'];
        $previous = $payload['comparison']['previous_period'];
        $summaryRows = [
            [$this->xlsxCell('LAPORAN PENJUALAN', 1)],
            [$this->xlsxCell($restaurantName, 2)],
            [$this->xlsxCell($period, 13)],
            [$this->xlsxCell('Dibuat pada '.$generatedAt.' WIB', 13)],
            [],
            [$this->xlsxCell('RINGKASAN UTAMA', 3)],
            [
                $this->xlsxCell('Indikator', 4),
                $this->xlsxCell('Periode Ini', 4),
                $this->xlsxCell('Periode Sebelumnya', 4),
                $this->xlsxCell('Selisih', 4),
                $this->xlsxCell('Perubahan', 4),
            ],
        ];

        foreach ($summaryLabels as $key => $label) {
            $currentValue = (float) ($payload['summary'][$key] ?? 0);
            $previousValue = (float) ($previous[$key] ?? 0);
            $delta = $currentValue - $previousValue;
            $deltaPercent = $previousValue == 0.0
                ? ($currentValue == 0.0 ? 0.0 : 1.0)
                : $delta / $previousValue;
            $numberStyle = in_array($key, $countMetrics, true) ? 6 : 5;

            $summaryRows[] = [
                $this->xlsxCell($label, 8),
                $this->xlsxNumber($currentValue, $numberStyle),
                $this->xlsxNumber($previousValue, $numberStyle),
                $this->xlsxNumber($delta, $numberStyle),
                $this->xlsxNumber($deltaPercent, 7),
            ];
        }

        $summaryRows[] = [];
        $summaryRows[] = [$this->xlsxCell('CATATAN', 3)];
        $summaryRows[] = [
            $this->xlsxCell(
                'Estimasi HPP dan laba memakai harga modal stok yang tercatat. Pastikan transaksi stok diperbarui agar laporan akurat.',
                13,
            ),
        ];

        $transactionRows = $this->sheetIntroRows(
            'RINCIAN TRANSAKSI',
            $restaurantName,
            $period,
            $generatedAt,
        );
        $transactionRows[] = [
            $this->xlsxCell('No.', 4),
            $this->xlsxCell('Waktu Bayar', 4),
            $this->xlsxCell('Nomor Tagihan', 4),
            $this->xlsxCell('Tipe Pesanan', 4),
            $this->xlsxCell('Meja', 4),
            $this->xlsxCell('Pelanggan', 4),
            $this->xlsxCell('Tamu', 4),
            $this->xlsxCell('Metode Bayar', 4),
            $this->xlsxCell('Subtotal', 4),
            $this->xlsxCell('Diskon', 4),
            $this->xlsxCell('Pajak', 4),
            $this->xlsxCell('Layanan', 4),
            $this->xlsxCell('Total Tagihan', 4),
            $this->xlsxCell('Dibayar', 4),
            $this->xlsxCell('Refund', 4),
            $this->xlsxCell('Penjualan Bersih', 4),
            $this->xlsxCell('Status', 4),
        ];
        foreach ($payload['transactions'] as $index => $row) {
            $transactionRows[] = [
                $this->xlsxNumber($index + 1, 6),
                $this->xlsxCell($row['paid_at'] ? Carbon::parse($row['paid_at'])->format('d/m/Y H:i') : '-', 8),
                $this->xlsxCell($row['bill_no'], 8),
                $this->xlsxCell($this->billTypeLabel($row['bill_type']), 8),
                $this->xlsxCell($row['table'], 8),
                $this->xlsxCell($row['customer_name'], 8),
                $this->xlsxNumber($row['guest_count'], 6),
                $this->xlsxCell($this->paymentMethodListLabel($row['payment_methods']), 8),
                $this->xlsxNumber($row['subtotal'], 5),
                $this->xlsxNumber($row['discount_total'], 5),
                $this->xlsxNumber($row['tax_total'], 5),
                $this->xlsxNumber($row['service_total'], 5),
                $this->xlsxNumber($row['grand_total'], 5),
                $this->xlsxNumber($row['gross_paid'], 5),
                $this->xlsxNumber($row['refund_total'], 5),
                $this->xlsxNumber($row['net_sales'], 5),
                $this->xlsxCell($this->statusLabel($row['status']), 8),
            ];
        }

        $dailyRows = $this->sheetIntroRows('TREN HARIAN', $restaurantName, $period, $generatedAt);
        $dailyRows[] = [
            $this->xlsxCell('Tanggal', 4),
            $this->xlsxCell('Penjualan Kotor', 4),
            $this->xlsxCell('Refund', 4),
            $this->xlsxCell('Penjualan Bersih', 4),
            $this->xlsxCell('Tagihan Lunas', 4),
        ];
        foreach ($payload['daily_trend'] as $row) {
            $dailyRows[] = [
                $this->xlsxCell(Carbon::parse($row['date'])->format('d/m/Y'), 8),
                $this->xlsxNumber($row['gross_total'], 5),
                $this->xlsxNumber($row['refund_total'], 5),
                $this->xlsxNumber($row['net_total'], 5),
                $this->xlsxNumber($row['paid_bills_count'], 6),
            ];
        }

        $menuRows = $this->sheetIntroRows('MENU & KATEGORI', $restaurantName, $period, $generatedAt);
        $menuRows[] = [$this->xlsxCell('MENU TERLARIS', 3)];
        $menuRows[] = [
            $this->xlsxCell('Peringkat', 4),
            $this->xlsxCell('Nama Menu', 4),
            $this->xlsxCell('Jumlah Terjual', 4),
            $this->xlsxCell('Omzet', 4),
        ];
        foreach ($payload['top_items'] as $index => $row) {
            $menuRows[] = [
                $this->xlsxNumber($index + 1, 6),
                $this->xlsxCell($row['menu_name'], 8),
                $this->xlsxNumber($row['total_qty'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
            ];
        }
        $menuRows[] = [];
        $menuRows[] = [$this->xlsxCell('PENJUALAN PER KATEGORI', 3)];
        $menuRows[] = [
            $this->xlsxCell('Kategori', 4),
            $this->xlsxCell('Jumlah Tagihan', 4),
            $this->xlsxCell('Jumlah Item', 4),
            $this->xlsxCell('Omzet', 4),
        ];
        foreach ($payload['category_sales'] as $row) {
            $menuRows[] = [
                $this->xlsxCell($row['category_name'], 8),
                $this->xlsxNumber($row['bills_count'], 6),
                $this->xlsxNumber($row['total_qty'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
            ];
        }

        $analysisRows = $this->sheetIntroRows('ANALISIS OPERASIONAL', $restaurantName, $period, $generatedAt);
        $analysisRows[] = [$this->xlsxCell('METODE PEMBAYARAN', 3)];
        $analysisRows[] = [
            $this->xlsxCell('Metode', 4),
            $this->xlsxCell('Transaksi', 4),
            $this->xlsxCell('Penjualan Kotor', 4),
            $this->xlsxCell('Refund', 4),
            $this->xlsxCell('Void', 4),
            $this->xlsxCell('Penjualan Bersih', 4),
        ];
        foreach ($payload['payment_methods'] as $row) {
            $analysisRows[] = [
                $this->xlsxCell($this->paymentMethodLabel($row['payment_method']), 8),
                $this->xlsxNumber($row['payments_count'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
                $this->xlsxNumber($row['refund_total'], 5),
                $this->xlsxNumber($row['void_total'], 5),
                $this->xlsxNumber($row['net_total'], 5),
            ];
        }
        $analysisRows[] = [];
        $analysisRows[] = [$this->xlsxCell('TIPE PESANAN', 3)];
        $analysisRows[] = [
            $this->xlsxCell('Tipe', 4),
            $this->xlsxCell('Jumlah Tagihan', 4),
            $this->xlsxCell('Omzet', 4),
        ];
        foreach ($payload['bill_types'] as $row) {
            $analysisRows[] = [
                $this->xlsxCell($this->billTypeLabel($row['bill_type']), 8),
                $this->xlsxNumber($row['bills_count'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
            ];
        }
        $analysisRows[] = [];
        $analysisRows[] = [$this->xlsxCell('MEJA PALING AKTIF', 3)];
        $analysisRows[] = [
            $this->xlsxCell('Kode', 4),
            $this->xlsxCell('Nama Meja', 4),
            $this->xlsxCell('Jumlah Tagihan', 4),
            $this->xlsxCell('Omzet', 4),
        ];
        foreach ($payload['top_tables'] as $row) {
            $analysisRows[] = [
                $this->xlsxCell($row['table_code'] ?? '-', 8),
                $this->xlsxCell($row['table_name'] ?? 'Tanpa meja', 8),
                $this->xlsxNumber($row['bills_count'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
            ];
        }
        $analysisRows[] = [];
        $analysisRows[] = [$this->xlsxCell('JAM RAMAI', 3)];
        $analysisRows[] = [
            $this->xlsxCell('Rentang Jam', 4),
            $this->xlsxCell('Jumlah Tagihan', 4),
            $this->xlsxCell('Omzet', 4),
        ];
        foreach ($payload['hourly_trend'] as $row) {
            $analysisRows[] = [
                $this->xlsxCell($row['hour_label'], 8),
                $this->xlsxNumber($row['paid_bills_count'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
            ];
        }
        $analysisRows[] = [];
        $analysisRows[] = [$this->xlsxCell('PELANGGAN UTAMA', 3)];
        $analysisRows[] = [
            $this->xlsxCell('Pelanggan', 4),
            $this->xlsxCell('Jumlah Tagihan', 4),
            $this->xlsxCell('Omzet', 4),
            $this->xlsxCell('Rata-rata Tagihan', 4),
        ];
        foreach ($payload['top_customers'] as $row) {
            $analysisRows[] = [
                $this->xlsxCell($row['customer_name'], 8),
                $this->xlsxNumber($row['bills_count'], 6),
                $this->xlsxNumber($row['gross_total'], 5),
                $this->xlsxNumber($row['average_bill'], 5),
            ];
        }

        return [
            [
                'name' => 'Ringkasan',
                'rows' => $summaryRows,
                'widths' => [34, 20, 20, 20, 16],
                'merges' => ['A1:E1', 'A2:E2', 'A3:E3', 'A4:E4', 'A6:E6', 'A19:E19', 'A20:E20'],
                'freeze_row' => 7,
            ],
            [
                'name' => 'Transaksi',
                'rows' => $transactionRows,
                'widths' => [8, 20, 28, 18, 20, 26, 10, 22, 18, 16, 16, 16, 20, 18, 18, 20, 16],
                'merges' => ['A1:Q1', 'A2:Q2', 'A3:Q3', 'A4:Q4'],
                'freeze_row' => 6,
                'auto_filter' => 'A5:Q'.max(5, count($transactionRows)),
                'landscape' => true,
            ],
            [
                'name' => 'Tren Harian',
                'rows' => $dailyRows,
                'widths' => [18, 22, 18, 22, 18],
                'merges' => ['A1:E1', 'A2:E2', 'A3:E3', 'A4:E4'],
                'freeze_row' => 6,
                'auto_filter' => 'A5:E'.max(5, count($dailyRows)),
            ],
            [
                'name' => 'Menu & Kategori',
                'rows' => $menuRows,
                'widths' => [16, 36, 20, 22],
                'merges' => ['A1:D1', 'A2:D2', 'A3:D3', 'A4:D4'],
                'freeze_row' => 7,
            ],
            [
                'name' => 'Analisis Operasional',
                'rows' => $analysisRows,
                'widths' => [28, 20, 22, 22, 18, 22],
                'merges' => ['A1:F1', 'A2:F2', 'A3:F3', 'A4:F4'],
                'freeze_row' => 7,
            ],
        ];
    }

    private function sheetIntroRows(
        string $title,
        string $restaurantName,
        string $period,
        string $generatedAt,
    ): array {
        return [
            [$this->xlsxCell($title, 1)],
            [$this->xlsxCell($restaurantName, 2)],
            [$this->xlsxCell($period, 13)],
            [$this->xlsxCell('Dibuat pada '.$generatedAt.' WIB', 13)],
        ];
    }

    private function xlsxCell(mixed $value, int $style = 0): array
    {
        return ['value' => $this->sanitizeSpreadsheetCell((string) $value), 'style' => $style, 'type' => 'string'];
    }

    private function xlsxNumber(mixed $value, int $style): array
    {
        return ['value' => (float) $value, 'style' => $style, 'type' => 'number'];
    }

    private function billTypeLabel(string $value): string
    {
        return match (strtoupper($value)) {
            'DINE_IN' => 'Makan di Tempat',
            'TAKE_AWAY' => 'Bawa Pulang',
            'RESERVATION' => 'Reservasi',
            'CATERING', 'EVENT' => 'Katering / Event',
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function paymentMethodLabel(string $value): string
    {
        return match (strtoupper($value)) {
            'CASH' => 'Tunai',
            'DEBIT' => 'Kartu Debit',
            'CREDIT_CARD' => 'Kartu Kredit',
            'BANK_TRANSFER' => 'Transfer Bank',
            'QRIS' => 'QRIS',
            'EWALLET' => 'Dompet Digital',
            'DEPOSIT' => 'Uang Muka',
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function paymentMethodListLabel(string $value): string
    {
        if (trim($value) === '') {
            return '-';
        }

        return collect(explode(',', $value))
            ->map(fn (string $method) => $this->paymentMethodLabel(trim($method)))
            ->implode(', ');
    }

    private function statusLabel(string $value): string
    {
        return match (strtoupper($value)) {
            'PAID' => 'Lunas',
            'REFUND' => 'Dikembalikan',
            'VOID' => 'Dibatalkan',
            'CANCELLED' => 'Dibatalkan',
            'CLOSED' => 'Ditutup',
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $worksheetOverrides = collect(range(1, $sheetCount))
            ->map(fn (int $index) => sprintf(
                '  <Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index,
            ))
            ->implode("\n");

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
{$worksheetOverrides}
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function rootRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function appPropertiesXml(array $sheets): string
    {
        $sheetNames = collect($sheets)
            ->map(fn (array $sheet) => '<vt:lpstr>'.$this->escapeSpreadsheetValue($sheet['name']).'</vt:lpstr>')
            ->implode('');
        $sheetCount = count($sheets);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Warung Babeh POS</Application>
  <HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>{$sheetCount}</vt:i4></vt:variant></vt:vector></HeadingPairs>
  <TitlesOfParts><vt:vector size="{$sheetCount}" baseType="lpstr">{$sheetNames}</vt:vector></TitlesOfParts>
</Properties>
XML;
    }

    private function corePropertiesXml(): string
    {
        $createdAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:dcmitype="http://purl.org/dc/dcmitype/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Laporan Penjualan</dc:title>
  <dc:creator>RestoPOS</dc:creator>
  <cp:lastModifiedBy>RestoPOS</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$createdAt}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$createdAt}</dcterms:modified>
</cp:coreProperties>
XML;
    }

    private function workbookXml(array $sheets): string
    {
        $sheetNodes = collect($sheets)
            ->map(fn (array $sheet, int $index) => sprintf(
                '    <sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escapeSpreadsheetValue($sheet['name']),
                $index + 1,
                $index + 1,
            ))
            ->implode("\n");

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
{$sheetNodes}
  </sheets>
</workbook>
XML;
    }

    private function workbookRelationshipsXml(array $sheets): string
    {
        $worksheetRelationships = collect($sheets)
            ->map(fn (array $_sheet, int $index) => sprintf(
                '  <Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1,
                $index + 1,
            ))
            ->implode("\n");
        $styleRelationshipId = count($sheets) + 1;

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
{$worksheetRelationships}
  <Relationship Id="rId{$styleRelationshipId}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="3">
    <numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/>
    <numFmt numFmtId="165" formatCode="0.0%"/>
    <numFmt numFmtId="166" formatCode="#,##0"/>
  </numFmts>
  <fonts count="4">
    <font><sz val="11"/><name val="Aptos"/><family val="2"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="18"/><name val="Aptos Display"/></font>
    <font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font>
    <font><b/><color rgb="FF004B36"/><sz val="11"/><name val="Aptos"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF004B36"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0D6B3A"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF6D8"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE5F2EA"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left style="thin"><color rgb="FFD9E2DA"/></left><right style="thin"><color rgb="FFD9E2DA"/></right><top style="thin"><color rgb="FFD9E2DA"/></top><bottom style="thin"><color rgb="FFD9E2DA"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="14">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="166" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="165" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0"/>
    <xf numFmtId="164" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0"/>
    <xf numFmtId="164" fontId="3" fillId="5" borderId="1" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
    }

    private function worksheetXml(array $sheet): string
    {
        $rows = $sheet['rows'];
        $sheetRows = collect($rows)->map(function (array $row, int $rowIndex): string {
            $cells = collect(array_values($row))->map(
                fn (array $cell, int $columnIndex): string => $this->worksheetCellXml(
                    $rowIndex + 1,
                    $columnIndex + 1,
                    $cell,
                )
            )->implode('');

            $height = in_array($rowIndex + 1, [1, 6], true) ? ' ht="26" customHeight="1"' : '';

            return sprintf('<row r="%d"%s>%s</row>', $rowIndex + 1, $height, $cells);
        })->implode('');
        $lastColumn = $this->worksheetColumnName(max(array_map('count', $rows)));
        $lastRow = max(count($rows), 1);
        $columns = collect($sheet['widths'] ?? [20])
            ->map(fn (float|int $width, int $index) => sprintf(
                '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $index + 1,
                $index + 1,
                $width,
            ))
            ->implode('');
        $freezeRow = (int) ($sheet['freeze_row'] ?? 0);
        $pane = $freezeRow > 1
            ? sprintf('<pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/>', $freezeRow - 1, $freezeRow)
            : '';
        $merges = collect($sheet['merges'] ?? [])
            ->map(fn (string $range) => '<mergeCell ref="'.$range.'"/>')
            ->implode('');
        $mergeXml = $merges !== ''
            ? sprintf('<mergeCells count="%d">%s</mergeCells>', count($sheet['merges']), $merges)
            : '';
        $autoFilter = isset($sheet['auto_filter'])
            ? '<autoFilter ref="'.$sheet['auto_filter'].'"/>'
            : '';
        $orientation = ($sheet['landscape'] ?? false) ? 'landscape' : 'portrait';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="A1:{$lastColumn}{$lastRow}"/>
  <sheetViews><sheetView workbookViewId="0"><selection pane="topLeft" activeCell="A1" sqref="A1"/>{$pane}</sheetView></sheetViews>
  <sheetFormatPr defaultRowHeight="18"/>
  <cols>{$columns}</cols>
  <sheetData>
    {$sheetRows}
  </sheetData>
  {$autoFilter}
  {$mergeXml}
  <pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>
  <pageSetup orientation="{$orientation}" fitToWidth="1" fitToHeight="0"/>
</worksheet>
XML;
    }

    private function worksheetCellXml(int $rowNumber, int $columnNumber, array $cell): string
    {
        $cellReference = $this->worksheetColumnName($columnNumber).$rowNumber;
        $value = $cell['value'] ?? '';
        $style = (int) ($cell['style'] ?? 0);

        if (($cell['type'] ?? 'string') === 'number') {
            return sprintf(
                '<c r="%s" s="%d"><v>%s</v></c>',
                $cellReference,
                $style,
                $this->escapeSpreadsheetValue((string) $value),
            );
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $cellReference,
            $style,
            $this->escapeSpreadsheetValue((string) $value),
        );
    }

    private function worksheetColumnName(int $columnNumber): string
    {
        $columnName = '';

        while ($columnNumber > 0) {
            $remainder = ($columnNumber - 1) % 26;
            $columnName = chr(65 + $remainder).$columnName;
            $columnNumber = intdiv($columnNumber - 1, 26);
        }

        return $columnName;
    }

    private function escapeSpreadsheetValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
