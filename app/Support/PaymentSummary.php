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
        $isAdvanceFlow = in_array($bill->bill_type, ['CATERING', 'RESERVATION'], true);
        $depositPercent = $isAdvanceFlow ? (int) ($bill->deposit_required_percent ?? 30) : 0;
        $depositTarget = $isAdvanceFlow ? round((float) $bill->grand_total * $depositPercent / 100, 2) : 0;
        $depositTotal = (float) $paidPayments->where('payment_type', 'DEPOSIT')->sum('amount');
        $depositStatus = match (true) {
            ! $isAdvanceFlow => 'NOT_APPLICABLE',
            $depositTarget > 0 && $depositTotal >= $depositTarget => 'COMPLETE',
            $depositTotal > 0 => 'PARTIAL',
            default => 'WAITING',
        };

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
            'advance_payment' => [
                'is_applicable' => $isAdvanceFlow,
                'deposit_required_percent' => $depositPercent,
                'deposit_target' => number_format($depositTarget, 2, '.', ''),
                'deposit_total' => number_format($depositTotal, 2, '.', ''),
                'deposit_status' => $depositStatus,
                'payment_due_at' => optional($bill->payment_due_at)->toIso8601String(),
                'is_payment_overdue' => $remaining > 0 && $bill->payment_due_at?->isPast(),
                'cancellation_policy' => $bill->cancellation_policy,
            ],
            'payment_progress' => [
                'is_unpaid' => $netPaid <= 0,
                'is_partial' => $netPaid > 0 && $remaining > 0,
                'is_paid' => $remaining <= 0 && (float) $bill->grand_total > 0,
            ],
        ];
    }
}
