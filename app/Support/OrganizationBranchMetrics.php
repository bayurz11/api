<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrganizationBranchMetrics
{
    public function build(int $organizationId, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = DB::table('branches')
            ->leftJoin('payments', function ($join) use ($rangeStart, $rangeEnd): void {
                $join->on('payments.branch_id', '=', 'branches.id')
                    ->where('payments.paid_at', '>=', $rangeStart)
                    ->where('payments.paid_at', '<', $rangeEnd);
            })
            ->leftJoin('bills', 'bills.id', '=', 'payments.bill_id')
            ->where('branches.organization_id', $organizationId)
            ->where('branches.is_active', true)
            ->select([
                'branches.id as branch_id',
                'branches.code as branch_code',
                'branches.name as branch_name',
            ])
            ->selectRaw("SUM(CASE WHEN payments.status = 'PAID' THEN payments.amount ELSE 0 END) as gross_sales")
            ->selectRaw("SUM(CASE WHEN payments.status = 'REFUND' THEN payments.amount ELSE 0 END) as refund_total")
            ->selectRaw("COUNT(DISTINCT CASE WHEN payments.status = 'PAID' THEN payments.bill_id END) as paid_bills_count")
            ->groupBy('branches.id', 'branches.code', 'branches.name')
            ->orderByDesc('gross_sales')
            ->orderBy('branches.name')
            ->get()
            ->map(function (object $row): array {
                $grossSales = (float) $row->gross_sales;
                $refundTotal = (float) $row->refund_total;
                $paidBillsCount = (int) $row->paid_bills_count;

                return [
                    'branch_id' => (int) $row->branch_id,
                    'branch_code' => $row->branch_code,
                    'branch_name' => $row->branch_name,
                    'gross_sales' => round($grossSales, 2),
                    'refund_total' => round($refundTotal, 2),
                    'net_sales' => round($grossSales - $refundTotal, 2),
                    'paid_bills_count' => $paidBillsCount,
                    'average_bill' => round($paidBillsCount > 0 ? ($grossSales - $refundTotal) / $paidBillsCount : 0, 2),
                ];
            })
            ->values();

        $netSales = (float) $rows->sum('net_sales');
        $paidBillsCount = (int) $rows->sum('paid_bills_count');

        return [
            'summary' => [
                'branches_count' => $rows->count(),
                'gross_sales' => round((float) $rows->sum('gross_sales'), 2),
                'refund_total' => round((float) $rows->sum('refund_total'), 2),
                'net_sales' => round($netSales, 2),
                'paid_bills_count' => $paidBillsCount,
                'average_bill' => round($paidBillsCount > 0 ? $netSales / $paidBillsCount : 0, 2),
            ],
            'branches' => $rows->all(),
        ];
    }
}
