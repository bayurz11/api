<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
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

            fputcsv($handle, ['section', 'key', 'value', 'value_2', 'value_3', 'value_4', 'value_5']);

            foreach ($payload['summary'] as $key => $value) {
                fputcsv($handle, ['summary', $key, $value]);
            }

            foreach ($payload['payment_methods'] as $row) {
                fputcsv($handle, [
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
                fputcsv($handle, [
                    'bill_types',
                    $row['bill_type'],
                    $row['gross_total'],
                    $row['bills_count'],
                ]);
            }

            foreach ($payload['top_items'] as $row) {
                fputcsv($handle, [
                    'top_items',
                    $row['menu_name'],
                    $row['menu_id'],
                    $row['total_qty'],
                    $row['gross_total'],
                ]);
            }

            foreach ($payload['category_sales'] as $row) {
                fputcsv($handle, [
                    'category_sales',
                    $row['category_name'],
                    $row['bills_count'],
                    $row['total_qty'],
                    $row['gross_total'],
                ]);
            }

            foreach ($payload['daily_trend'] as $row) {
                fputcsv($handle, [
                    'daily_trend',
                    $row['date'],
                    $row['gross_total'],
                    $row['refund_total'],
                    $row['net_total'],
                    $row['paid_bills_count'],
                ]);
            }

            foreach ($payload['top_tables'] as $row) {
                fputcsv($handle, [
                    'top_tables',
                    $row['table_code'] ?? '-',
                    $row['table_name'] ?? '-',
                    $row['gross_total'],
                    $row['bills_count'],
                ]);
            }

            foreach ($payload['hourly_trend'] as $row) {
                fputcsv($handle, [
                    'hourly_trend',
                    $row['hour_label'],
                    $row['gross_total'],
                    $row['paid_bills_count'],
                ]);
            }

            foreach ($payload['top_customers'] as $row) {
                fputcsv($handle, [
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
        $archive = new ZipArchive();
        $result = $archive->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        abort_if($result !== true, 500, 'Gagal membuat file Excel laporan penjualan.');

        $rows = $this->buildSalesSummaryRows($payload);

        $archive->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $archive->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $archive->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $archive->addFromString('docProps/core.xml', $this->corePropertiesXml());
        $archive->addFromString('xl/workbook.xml', $this->workbookXml());
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $archive->addFromString('xl/styles.xml', $this->stylesXml());
        $archive->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($rows));
        $archive->close();
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
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

    private function appPropertiesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Laravel</Application>
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

    private function workbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Laporan Penjualan" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1">
    <font>
      <sz val="11"/>
      <name val="Calibri"/>
      <family val="2"/>
    </font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
    }

    private function worksheetXml(array $rows): string
    {
        $sheetRows = collect($rows)->map(function (array $row, int $rowIndex): string {
            $cells = collect(array_values($row))->map(
                fn (string $value, int $columnIndex): string => $this->worksheetCellXml(
                    $rowIndex + 1,
                    $columnIndex + 1,
                    $value,
                )
            )->implode('');

            return sprintf('<row r="%d">%s</row>', $rowIndex + 1, $cells);
        })->implode('');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    {$sheetRows}
  </sheetData>
</worksheet>
XML;
    }

    private function worksheetCellXml(int $rowNumber, int $columnNumber, string $value): string
    {
        $cellReference = $this->worksheetColumnName($columnNumber).$rowNumber;

        if (is_numeric($value) && ! preg_match('/^0\d+$/', $value)) {
            return sprintf(
                '<c r="%s"><v>%s</v></c>',
                $cellReference,
                $this->escapeSpreadsheetValue($value),
            );
        }

        return sprintf(
            '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>',
            $cellReference,
            $this->escapeSpreadsheetValue($value),
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
