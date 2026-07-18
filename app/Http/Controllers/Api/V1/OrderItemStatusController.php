<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Support\AuditLogger;
use App\Support\BillOrderState;
use App\Support\BillTotals;
use App\Support\InventoryManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderItemStatusController extends Controller
{
    private const VALID_TRANSITIONS = [
        'WAITING' => ['ACCEPTED', 'COOKING', 'PREPARING', 'READY', 'CANCELLED'],
        'ACCEPTED' => ['COOKING', 'PREPARING', 'READY', 'CANCELLED'],
        'COOKING' => ['READY', 'CANCELLED'],
        'PREPARING' => ['READY', 'CANCELLED'],
        'READY' => ['SERVED', 'CANCELLED'],
        'SERVED' => [],
        'CANCELLED' => [],
    ];

    public function update(Request $request, OrderItem $orderItem): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(['WAITING', 'ACCEPTED', 'COOKING', 'PREPARING', 'READY', 'SERVED', 'CANCELLED']),
            ],
        ]);

        $isServeStatus = $validated['status'] === 'SERVED';

        if ($isServeStatus) {
            $this->requirePermission($request, 'orders.serve');
        } else {
            $this->requirePermission($request, 'orders.update-status');
        }

        if ($user->hasRole('Kitchen')) {
            abort_if($orderItem->station_type !== 'KITCHEN', 403, 'Kitchen hanya boleh mengubah item kitchen.');
            abort_if(in_array($validated['status'], ['PREPARING', 'SERVED'], true), 422, 'Status tidak valid untuk kitchen.');
        }

        if ($user->hasRole('Bar')) {
            abort_if($orderItem->station_type !== 'BAR', 403, 'Bar hanya boleh mengubah item bar.');
            abort_if(in_array($validated['status'], ['COOKING', 'SERVED'], true), 422, 'Status tidak valid untuk bar.');
        }

        if ($user->hasRole('Waiter')) {
            abort_if(! $isServeStatus, 403, 'Waiter hanya boleh menandai item sebagai served.');
        }

        $orderItem = DB::transaction(function () use ($orderItem, $validated, $user) {
            $orderItem = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);
            $previousStatus = $orderItem->status;

            if ($validated['status'] !== $previousStatus) {
                $allowedTransitions = self::VALID_TRANSITIONS[$previousStatus] ?? [];
                abort_if(
                    ! in_array($validated['status'], $allowedTransitions, true),
                    422,
                    'Transisi status item order tidak valid.',
                );
            }

            $payload = ['status' => $validated['status']];

            if ($validated['status'] === 'ACCEPTED') {
                $payload['accepted_at'] = now();
            }

            if (in_array($validated['status'], ['COOKING', 'PREPARING'], true)) {
                $payload['started_at'] = now();
            }

            if ($validated['status'] === 'READY') {
                $payload['ready_at'] = now();
            }

            if ($validated['status'] === 'SERVED') {
                $payload['served_at'] = now();
            }

            $orderItem->update($payload);

            if ($validated['status'] === 'CANCELLED' && $previousStatus !== 'CANCELLED') {
                InventoryManager::restoreForOrderItem(
                    orderItem: $orderItem->fresh(['menu']),
                    userId: $user->id,
                    reason: "Order item {$orderItem->id} dibatalkan",
                );
            }

            $order = $orderItem->order()->with(['items', 'bill.orders.items'])->firstOrFail();
            $statuses = $order->items->pluck('status');
            $orderStatus = BillOrderState::resolveOrderStatus($statuses);

            $order->update([
                'status' => $orderStatus,
                'ready_at' => $orderStatus === 'READY' ? ($order->ready_at ?? now()) : $order->ready_at,
                'served_at' => $orderStatus === 'SERVED' ? ($order->served_at ?? now()) : null,
            ]);

            BillTotals::recalculate($order->bill);

            AuditLogger::log(
                userId: $user->id,
                roleName: $user->getRoleNames()->first(),
                action: 'order_item.status_updated',
                entityType: 'order_item',
                entityId: $orderItem->id,
                before: ['status' => $previousStatus],
                after: ['status' => $validated['status']],
            );

            return $orderItem;
        });

        return response()->json([
            'message' => 'Status order item berhasil diperbarui.',
            'data' => $orderItem->fresh(['order.bill.table', 'menu']),
        ]);
    }
}
