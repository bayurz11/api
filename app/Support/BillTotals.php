<?php

namespace App\Support;

use App\Models\Bill;

class BillTotals
{
    public static function recalculate(Bill $bill): Bill
    {
        $bill->loadMissing('orders.items');

        $subtotal = (float) $bill->items()->sum('line_total');
        $discountTotal = (float) $bill->discount_total;
        $taxTotal = (float) $bill->tax_total;
        $serviceTotal = (float) $bill->service_total;
        $paidIncomingTotal = (float) $bill->payments()->where('status', 'PAID')->sum('amount');
        $refundedTotal = (float) $bill->payments()->where('status', 'REFUND')->sum('amount');
        $paidTotal = max($paidIncomingTotal - $refundedTotal, 0);

        $grandTotal = max($subtotal - $discountTotal, 0) + $taxTotal + $serviceTotal;
        $balanceDue = max($grandTotal - $paidTotal, 0);
        $orderItemStatuses = $bill->orders
            ->flatMap(fn ($order) => $order->items->pluck('status'))
            ->values();

        $status = BillOrderState::resolveBillStatus(
            grandTotal: $grandTotal,
            paidTotal: $paidTotal,
            refundTotal: $refundedTotal,
            hasOrders: $bill->orders->isNotEmpty(),
            orderItemStatuses: $orderItemStatuses,
            currentStatus: $bill->status,
        );

        $bill->update([
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'paid_total' => $paidTotal,
            'balance_due' => $balanceDue,
            'status' => $status,
            'closed_at' => $status === 'PAID' ? ($bill->closed_at ?? now()) : null,
        ]);

        return $bill->fresh();
    }
}
