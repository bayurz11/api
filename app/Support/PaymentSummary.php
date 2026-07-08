<?php

namespace App\Support;

use App\Models\Bill;

class PaymentSummary
{
    public static function forBill(Bill $bill): array
    {
        $bill->loadMissing('payments');

        $paidPayments = $bill->payments->where('status', 'PAID');
        $refundPayments = $bill->payments->where('status', 'REFUND');
        $voidPayments = $bill->payments->where('status', 'VOID');

        $paidCount = $paidPayments->count();
        $refundCount = $refundPayments->count();
        $voidCount = $voidPayments->count();

        $paidGross = (float) $paidPayments->sum('amount');
        $refundTotal = (float) $refundPayments->sum('amount');
        $voidTotal = (float) $voidPayments->sum('amount');
        $netPaid = max($paidGross - $refundTotal, 0);
        $remaining = max((float) $bill->balance_due, 0);

        return [
            'status' => $bill->status,
            'grand_total' => $bill->grand_total,
            'paid_total' => $bill->paid_total,
            'balance_due' => $bill->balance_due,
            'paid_payments_count' => $paidCount,
            'refund_payments_count' => $refundCount,
            'void_payments_count' => $voidCount,
            'paid_gross_total' => number_format($paidGross, 2, '.', ''),
            'refund_total' => number_format($refundTotal, 2, '.', ''),
            'void_total' => number_format($voidTotal, 2, '.', ''),
            'net_paid_total' => number_format($netPaid, 2, '.', ''),
            'remaining_payment_total' => number_format($remaining, 2, '.', ''),
            'payment_progress' => [
                'is_unpaid' => $netPaid <= 0,
                'is_partial' => $netPaid > 0 && $remaining > 0,
                'is_paid' => $remaining <= 0 && (float) $bill->grand_total > 0,
            ],
        ];
    }
}
