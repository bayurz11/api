<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportSalesSummaryExcel(Request $request): StreamedResponse
    {
        $payload = $this->buildSalesSummaryPayload($request);
        $fileName = sprintf(
            'sales-summary-%s-to-%s.xls',
            $payload['filters']['date_from'],
            $payload['filters']['date_to'],
        );

        return response()->streamDownload(function () use ($payload) {
            echo $this->buildSalesSummarySpreadsheetXml($payload);
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
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

        $paymentsBase = Payment::query()
            ->where('paid_at', '>=', $rangeStart)
            ->where('paid_at', '<', $rangeEnd);

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

        $netSales = (float) $grossSales - (float) $refundTotal;

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'summary' => [
                'gross_sales' => number_format((float) $grossSales, 2, '.', ''),
                'refund_total' => number_format((float) $refundTotal, 2, '.', ''),
                'void_total' => number_format((float) $voidTotal, 2, '.', ''),
                'net_sales' => number_format($netSales, 2, '.', ''),
                'paid_bills_count' => $paidBillsCount,
                'refunded_bills_count' => $refundedBillsCount,
                'average_bill' => number_format($paidBillsCount > 0 ? $netSales / $paidBillsCount : 0, 2, '.', ''),
            ],
            'payment_methods' => $paymentMethods,
            'bill_types' => $billTypes,
            'top_items' => $topItems,
            'daily_trend' => $dailyTrend,
            'top_tables' => $topTables,
        ];
    }

    private function buildSalesSummarySpreadsheetXml(array $payload): string
    {
        $rows = [
            ['Bagian', 'Kunci', 'Nilai', 'Nilai 2', 'Nilai 3', 'Nilai 4', 'Nilai 5'],
        ];

        foreach ($payload['summary'] as $key => $value) {
            $rows[] = ['Ringkasan', (string) $key, (string) $value];
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

        $worksheetRows = collect($rows)->map(function (array $row): string {
            $cells = collect($row)->map(function (string $value): string {
                return sprintf(
                    '<Cell><Data ss:Type="String">%s</Data></Cell>',
                    $this->escapeSpreadsheetValue($value),
                );
            })->implode('');

            return "<Row>{$cells}</Row>";
        })->implode('');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Worksheet ss:Name="Laporan Penjualan">
  <Table>
   {$worksheetRows}
  </Table>
 </Worksheet>
</Workbook>
XML;
    }

    private function escapeSpreadsheetValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
