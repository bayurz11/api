<?php

namespace App\Support;

use Illuminate\Support\Collection;

class BillOrderState
{
    public static function resolveOrderStatus(Collection $itemStatuses): string
    {
        if ($itemStatuses->isEmpty()) {
            return 'WAITING';
        }

        if ($itemStatuses->every(fn ($status) => $status === 'CANCELLED')) {
            return 'CANCELLED';
        }

        if ($itemStatuses->every(fn ($status) => in_array($status, ['SERVED', 'CANCELLED'], true))) {
            return 'SERVED';
        }

        if (
            $itemStatuses->contains('READY') &&
            $itemStatuses->every(fn ($status) => in_array($status, ['READY', 'SERVED', 'CANCELLED'], true))
        ) {
            return 'READY';
        }

        if ($itemStatuses->contains(fn ($status) => in_array($status, ['ACCEPTED', 'COOKING', 'PREPARING'], true))) {
            return 'PREPARING';
        }

        return 'WAITING';
    }

    public static function resolveBillStatus(
        float $grandTotal,
        float $paidTotal,
        float $refundTotal,
        bool $hasOrders,
        Collection $orderItemStatuses,
        string $currentStatus,
    ): string {
        if ($refundTotal > 0 && $paidTotal <= 0) {
            return 'REFUND';
        }

        if ($grandTotal > 0 && $paidTotal >= $grandTotal) {
            return 'PAID';
        }

        if ($paidTotal > 0 && $paidTotal < $grandTotal) {
            return 'PARTIALLY_PAID';
        }

        if (! $hasOrders || $orderItemStatuses->isEmpty()) {
            return $grandTotal > 0 ? 'ORDERING' : 'OPEN';
        }

        $activeStatuses = $orderItemStatuses
            ->reject(fn ($status) => $status === 'CANCELLED')
            ->values();

        if ($activeStatuses->isEmpty()) {
            return $grandTotal > 0 ? 'ORDERING' : 'OPEN';
        }

        if ($activeStatuses->every(fn ($status) => $status === 'SERVED')) {
            return 'SERVED';
        }

        if (
            $activeStatuses->contains('READY') &&
            $activeStatuses->every(fn ($status) => in_array($status, ['READY', 'SERVED'], true))
        ) {
            return 'READY_TO_PAY';
        }

        if ($activeStatuses->contains(fn ($status) => in_array($status, ['WAITING', 'ACCEPTED', 'COOKING', 'PREPARING'], true))) {
            return 'ORDERING';
        }

        return $currentStatus;
    }
}
