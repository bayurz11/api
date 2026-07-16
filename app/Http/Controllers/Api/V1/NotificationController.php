<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\NotificationRead;
use App\Models\OrderItem;
use App\Models\QrOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    private const CHANNEL_KITCHEN = 'kitchen_new';
    private const CHANNEL_BAR = 'bar_new';
    private const CHANNEL_WAITER = 'waiter_ready';
    private const CHANNEL_QR = 'qr_pending';
    private const CHANNEL_CASHIER = 'cashier_bill';
    private ?bool $notificationReadsTableExists = null;

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'dashboard.view');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'channel' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $limit = $validated['limit'] ?? 30;
        $allowedChannels = $this->allowedChannelsForUser($user);
        $requestedChannel = $validated['channel'] ?? null;

        if ($requestedChannel !== null && ! in_array($requestedChannel, $allowedChannels, true)) {
            return response()->json([
                'summary' => $this->emptySummary(),
                'data' => [],
            ]);
        }

        $channels = $requestedChannel !== null ? [$requestedChannel] : $allowedChannels;
        $notifications = $this->cachedNotifications($channels, $limit);
        $notifications = $this->attachReadState($user, $notifications);

        return response()->json([
            'summary' => $this->buildSummary($channels, $notifications),
            'data' => $notifications->values(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'dashboard.view');

        $validated = $request->validate([
            'channel' => ['required', 'string'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        abort_unless(in_array($validated['channel'], $this->allowedChannelsForUser($user), true), 403);

        if (! $this->hasNotificationReadsTable()) {
            return response()->json([
                'message' => 'Fitur baca notifikasi belum aktif di server.',
                'data' => [
                    'channel' => $validated['channel'],
                    'entity_type' => $validated['entity_type'],
                    'entity_id' => $validated['entity_id'],
                    'read_at' => null,
                ],
            ]);
        }

        $read = NotificationRead::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'channel' => $validated['channel'],
                'entity_type' => $validated['entity_type'],
                'entity_id' => $validated['entity_id'],
            ],
            [
                'read_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Notifikasi ditandai sudah dibaca.',
            'data' => [
                'channel' => $read->channel,
                'entity_type' => $read->entity_type,
                'entity_id' => $read->entity_id,
                'read_at' => optional($read->read_at)->toIso8601String(),
            ],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'dashboard.view');

        $validated = $request->validate([
            'channel' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $allowedChannels = $this->allowedChannelsForUser($user);
        $requestedChannel = $validated['channel'] ?? null;

        if ($requestedChannel !== null && ! in_array($requestedChannel, $allowedChannels, true)) {
            abort(403);
        }

        $channels = $requestedChannel !== null ? [$requestedChannel] : $allowedChannels;
        $notifications = $this->cachedNotifications($channels, 500);

        if (! $this->hasNotificationReadsTable()) {
            return response()->json([
                'message' => 'Fitur baca notifikasi belum aktif di server.',
                'data' => [
                    'marked_count' => 0,
                    'channel' => $requestedChannel,
                ],
            ]);
        }

        if ($notifications->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada notifikasi yang perlu ditandai.',
                'data' => ['marked_count' => 0],
            ]);
        }

        $now = now();
        $payload = $notifications->map(fn (array $item) => [
            'user_id' => $user->id,
            'channel' => $item['channel'],
            'entity_type' => $item['entity_type'],
            'entity_id' => $item['entity_id'],
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        NotificationRead::query()->upsert(
            $payload,
            ['user_id', 'channel', 'entity_type', 'entity_id'],
            ['read_at', 'updated_at'],
        );

        return response()->json([
            'message' => 'Semua notifikasi berhasil ditandai sudah dibaca.',
            'data' => [
                'marked_count' => count($payload),
                'channel' => $requestedChannel,
            ],
        ]);
    }

    private function buildNotifications(array $channels, int $limit): Collection
    {
        $notifications = collect();

        foreach ($channels as $channel) {
            $channelItems = match ($channel) {
                self::CHANNEL_KITCHEN => $this->kitchenNotifications($limit),
                self::CHANNEL_BAR => $this->barNotifications($limit),
                self::CHANNEL_WAITER => $this->waiterNotifications($limit),
                self::CHANNEL_QR => $this->qrNotifications($limit),
                self::CHANNEL_CASHIER => $this->cashierNotifications($limit),
                default => collect(),
            };

            $notifications = $notifications->concat($channelItems);
        }

        return $notifications
            ->sortByDesc('sort_at')
            ->take($limit)
            ->values()
            ->map(function (array $item) {
                $item['occurred_at'] = $item['sort_at'] ?? null;
                unset($item['sort_at']);

                return $item;
            });
    }

    private function attachReadState(User $user, Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        if (! $this->hasNotificationReadsTable()) {
            return $items->map(function (array $item) {
                $item['is_read'] = false;
                $item['read_at'] = null;

                return $item;
            });
        }

        $reads = NotificationRead::query()
            ->where('user_id', $user->id)
            ->whereIn('channel', $items->pluck('channel')->unique()->values())
            ->whereIn('entity_type', $items->pluck('entity_type')->unique()->values())
            ->get()
            ->keyBy(fn (NotificationRead $read) => $this->notificationKey(
                $read->channel,
                $read->entity_type,
                $read->entity_id,
            ));

        return $items->map(function (array $item) use ($reads) {
            $key = $this->notificationKey($item['channel'], $item['entity_type'], $item['entity_id']);
            $read = $reads->get($key);

            $item['is_read'] = $read !== null;
            $item['read_at'] = $read?->read_at?->toIso8601String();

            return $item;
        });
    }

    private function buildSummary(array $channels, Collection $items): array
    {
        $active = $this->cachedActiveSummary($channels);

        $unread = [
            'kitchen_queue_unread_count' => 0,
            'bar_queue_unread_count' => 0,
            'ready_items_unread_count' => 0,
            'pending_qr_orders_unread_count' => 0,
            'cashier_action_unread_count' => 0,
        ];

        foreach ($items as $item) {
            if (($item['is_read'] ?? false) === true) {
                continue;
            }

            switch ($item['channel']) {
                case self::CHANNEL_KITCHEN:
                    $unread['kitchen_queue_unread_count']++;
                    break;
                case self::CHANNEL_BAR:
                    $unread['bar_queue_unread_count']++;
                    break;
                case self::CHANNEL_WAITER:
                    $unread['ready_items_unread_count']++;
                    break;
                case self::CHANNEL_QR:
                    $unread['pending_qr_orders_unread_count']++;
                    break;
                case self::CHANNEL_CASHIER:
                    $unread['cashier_action_unread_count']++;
                    break;
            }
        }

        $unread['total_unread_count'] = array_sum($unread);

        return array_merge($active, $unread);
    }

    private function cachedNotifications(array $channels, int $limit): Collection
    {
        $payload = Cache::remember(
            $this->notificationsCacheKey($channels, $limit),
            now()->addSeconds(8),
            fn (): array => $this->buildNotifications($channels, $limit)->values()->all(),
        );

        return collect($payload);
    }

    private function cachedActiveSummary(array $channels): array
    {
        return Cache::remember(
            $this->activeSummaryCacheKey($channels),
            now()->addSeconds(8),
            fn (): array => $this->buildActiveSummary($channels),
        );
    }

    private function buildActiveSummary(array $channels): array
    {
        return [
            'kitchen_queue_count' => in_array(self::CHANNEL_KITCHEN, $channels, true)
                ? OrderItem::query()->where('station_type', 'KITCHEN')->whereIn('status', ['WAITING', 'ACCEPTED', 'COOKING'])->count()
                : 0,
            'bar_queue_count' => in_array(self::CHANNEL_BAR, $channels, true)
                ? OrderItem::query()->where('station_type', 'BAR')->whereIn('status', ['WAITING', 'ACCEPTED', 'PREPARING'])->count()
                : 0,
            'ready_items_count' => in_array(self::CHANNEL_WAITER, $channels, true)
                ? OrderItem::query()->where('status', 'READY')->count()
                : 0,
            'pending_qr_orders_count' => in_array(self::CHANNEL_QR, $channels, true)
                ? QrOrder::query()->where('status', 'PENDING')->count()
                : 0,
            'cashier_action_bills_count' => in_array(self::CHANNEL_CASHIER, $channels, true)
                ? Bill::query()->whereIn('status', ['READY_TO_PAY', 'PARTIALLY_PAID'])->count()
                : 0,
        ];
    }

    private function notificationsCacheKey(array $channels, int $limit): string
    {
        $channelKey = md5(implode('|', $channels));

        return "notifications:v1:channels:{$channelKey}:limit:{$limit}";
    }

    private function activeSummaryCacheKey(array $channels): string
    {
        $channelKey = md5(implode('|', $channels));

        return "notifications-summary:v1:channels:{$channelKey}";
    }

    private function allowedChannelsForUser(User $user): array
    {
        $roles = $user->getRoleNames()->map(fn (string $role) => strtolower($role))->values();
        $channels = [];

        if ($roles->contains('owner') || $roles->contains('admin')) {
            return [
                self::CHANNEL_KITCHEN,
                self::CHANNEL_BAR,
                self::CHANNEL_WAITER,
                self::CHANNEL_QR,
                self::CHANNEL_CASHIER,
            ];
        }

        if ($roles->contains('kitchen')) {
            $channels[] = self::CHANNEL_KITCHEN;
        }

        if ($roles->contains('bar')) {
            $channels[] = self::CHANNEL_BAR;
        }

        if ($user->can('orders.serve')) {
            $channels[] = self::CHANNEL_WAITER;
        }

        if ($user->can('orders.create')) {
            $channels[] = self::CHANNEL_QR;
        }

        if ($user->can('payments.create')) {
            $channels[] = self::CHANNEL_CASHIER;
        }

        return array_values(array_unique($channels));
    }

    private function kitchenNotifications(int $limit): Collection
    {
        return OrderItem::query()
            ->with([
                'menu:id,name',
                'billItem:id,menu_name',
                'order:id,order_no,bill_id,sent_at',
                'order.bill:id,bill_no,table_id',
                'order.bill.table:id,code,name',
            ])
            ->where('station_type', 'KITCHEN')
            ->whereIn('status', ['WAITING', 'ACCEPTED', 'COOKING'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $item) => [
                'type' => 'KITCHEN_NEW_ITEM',
                'channel' => self::CHANNEL_KITCHEN,
                'sort_at' => optional($item->order?->sent_at)->toIso8601String() ?? optional($item->created_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                'title' => 'Pesanan baru masuk ke dapur',
                'message' => trim(($item->billItem?->menu_name ?? $item->menu?->name ?? 'Item dapur') . ' untuk ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                'entity_type' => 'order_item',
                'entity_id' => $item->id,
                'meta' => [
                    'order_item_id' => $item->id,
                    'station_type' => $item->station_type,
                    'menu_name' => $item->billItem?->menu_name ?? $item->menu?->name,
                    'qty' => $item->qty,
                    'status' => $item->status,
                    'order_id' => $item->order?->id,
                    'order_no' => $item->order?->order_no,
                    'bill_id' => $item->order?->bill?->id,
                    'bill_no' => $item->order?->bill?->bill_no,
                    'table_id' => $item->order?->bill?->table?->id,
                    'table_name' => $item->order?->bill?->table?->name,
                    'sent_at' => optional($item->order?->sent_at)->toIso8601String(),
                ],
            ]);
    }

    private function barNotifications(int $limit): Collection
    {
        return OrderItem::query()
            ->with([
                'menu:id,name',
                'billItem:id,menu_name',
                'order:id,order_no,bill_id,sent_at',
                'order.bill:id,bill_no,table_id',
                'order.bill.table:id,code,name',
            ])
            ->where('station_type', 'BAR')
            ->whereIn('status', ['WAITING', 'ACCEPTED', 'PREPARING'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $item) => [
                'type' => 'BAR_NEW_ITEM',
                'channel' => self::CHANNEL_BAR,
                'sort_at' => optional($item->order?->sent_at)->toIso8601String() ?? optional($item->created_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                'title' => 'Pesanan baru masuk ke bar',
                'message' => trim(($item->billItem?->menu_name ?? $item->menu?->name ?? 'Item bar') . ' untuk ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                'entity_type' => 'order_item',
                'entity_id' => $item->id,
                'meta' => [
                    'order_item_id' => $item->id,
                    'station_type' => $item->station_type,
                    'menu_name' => $item->billItem?->menu_name ?? $item->menu?->name,
                    'qty' => $item->qty,
                    'status' => $item->status,
                    'order_id' => $item->order?->id,
                    'order_no' => $item->order?->order_no,
                    'bill_id' => $item->order?->bill?->id,
                    'bill_no' => $item->order?->bill?->bill_no,
                    'table_id' => $item->order?->bill?->table?->id,
                    'table_name' => $item->order?->bill?->table?->name,
                    'sent_at' => optional($item->order?->sent_at)->toIso8601String(),
                ],
            ]);
    }

    private function waiterNotifications(int $limit): Collection
    {
        return OrderItem::query()
            ->with([
                'menu:id,name',
                'billItem:id,menu_name',
                'order:id,order_no,bill_id',
                'order.bill:id,bill_no,table_id,status',
                'order.bill.table:id,code,name',
            ])
            ->where('status', 'READY')
            ->latest('ready_at')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $item) => [
                'type' => 'WAITER_READY_ITEM',
                'channel' => self::CHANNEL_WAITER,
                'sort_at' => optional($item->ready_at)->toIso8601String() ?? optional($item->updated_at)->toIso8601String(),
                'title' => 'Pesanan siap diantar',
                'message' => trim(($item->billItem?->menu_name ?? $item->menu?->name ?? $item->station_type) . ' untuk ' . ($item->order?->bill?->table?->name ?? $item->order?->bill?->bill_no)),
                'entity_type' => 'order_item',
                'entity_id' => $item->id,
                'meta' => [
                    'order_item_id' => $item->id,
                    'station_type' => $item->station_type,
                    'menu_name' => $item->billItem?->menu_name ?? $item->menu?->name,
                    'qty' => $item->qty,
                    'order_id' => $item->order?->id,
                    'order_no' => $item->order?->order_no,
                    'bill_id' => $item->order?->bill?->id,
                    'bill_no' => $item->order?->bill?->bill_no,
                    'table_id' => $item->order?->bill?->table?->id,
                    'table_name' => $item->order?->bill?->table?->name,
                    'ready_at' => optional($item->ready_at)->toIso8601String(),
                ],
            ]);
    }

    private function qrNotifications(int $limit): Collection
    {
        return QrOrder::query()
            ->with(['table:id,code,name'])
            ->where('status', 'PENDING')
            ->latest('submitted_at')
            ->limit($limit)
            ->get()
            ->map(fn (QrOrder $qrOrder) => [
                'type' => 'QR_ORDER_PENDING',
                'channel' => self::CHANNEL_QR,
                'sort_at' => optional($qrOrder->submitted_at)->toIso8601String() ?? optional($qrOrder->updated_at)->toIso8601String(),
                'title' => 'QR order menunggu persetujuan',
                'message' => trim(($qrOrder->customer_name ?: 'Guest QR') . ' di ' . ($qrOrder->table?->name ?? $qrOrder->table_id)),
                'entity_type' => 'qr_order',
                'entity_id' => $qrOrder->id,
                'meta' => [
                    'qr_order_id' => $qrOrder->id,
                    'order_no' => $qrOrder->order_no,
                    'table_id' => $qrOrder->table?->id,
                    'table_name' => $qrOrder->table?->name,
                    'customer_name' => $qrOrder->customer_name,
                    'guest_count' => $qrOrder->guest_count,
                    'grand_total' => number_format((float) $qrOrder->grand_total, 2, '.', ''),
                    'submitted_at' => optional($qrOrder->submitted_at)->toIso8601String(),
                ],
            ]);
    }

    private function cashierNotifications(int $limit): Collection
    {
        return Bill::query()
            ->with(['table:id,code,name'])
            ->whereIn('status', ['READY_TO_PAY', 'PARTIALLY_PAID'])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Bill $bill) => [
                'type' => 'CASHIER_BILL_ACTION',
                'channel' => self::CHANNEL_CASHIER,
                'sort_at' => optional($bill->updated_at)->toIso8601String(),
                'title' => $bill->status === 'PARTIALLY_PAID' ? 'Bill menunggu pelunasan' : 'Bill siap dibayar',
                'message' => trim(($bill->table?->name ?? $bill->bill_no) . ' - sisa ' . number_format((float) $bill->balance_due, 0, ',', '.')),
                'entity_type' => 'bill',
                'entity_id' => $bill->id,
                'meta' => [
                    'bill_id' => $bill->id,
                    'bill_no' => $bill->bill_no,
                    'status' => $bill->status,
                    'table_id' => $bill->table?->id,
                    'table_name' => $bill->table?->name,
                    'grand_total' => number_format((float) $bill->grand_total, 2, '.', ''),
                    'paid_total' => number_format((float) $bill->paid_total, 2, '.', ''),
                    'balance_due' => number_format((float) $bill->balance_due, 2, '.', ''),
                ],
            ]);
    }

    private function notificationKey(string $channel, string $entityType, int $entityId): string
    {
        return implode('|', [$channel, $entityType, $entityId]);
    }

    private function emptySummary(): array
    {
        return [
            'kitchen_queue_count' => 0,
            'bar_queue_count' => 0,
            'ready_items_count' => 0,
            'pending_qr_orders_count' => 0,
            'cashier_action_bills_count' => 0,
            'kitchen_queue_unread_count' => 0,
            'bar_queue_unread_count' => 0,
            'ready_items_unread_count' => 0,
            'pending_qr_orders_unread_count' => 0,
            'cashier_action_unread_count' => 0,
            'total_unread_count' => 0,
        ];
    }

    private function hasNotificationReadsTable(): bool
    {
        if ($this->notificationReadsTableExists !== null) {
            return $this->notificationReadsTableExists;
        }

        try {
            return $this->notificationReadsTableExists = Schema::hasTable('notification_reads');
        } catch (\Throwable) {
            return $this->notificationReadsTableExists = false;
        }
    }
}
