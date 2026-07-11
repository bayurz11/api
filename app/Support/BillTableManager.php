<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Table;

class BillTableManager
{
    public const ACTIVE_BILL_STATUSES = ['OPEN', 'ORDERING', 'READY_TO_PAY', 'PARTIALLY_PAID', 'SERVED'];

    public static function normalizeTableIds(?int $primaryTableId, array $extraTableIds = []): array
    {
        return collect([$primaryTableId, ...$extraTableIds])
            ->filter(fn ($tableId) => filled($tableId))
            ->map(fn ($tableId) => (int) $tableId)
            ->unique()
            ->values()
            ->all();
    }

    public static function syncBillTables(Bill $bill, array $tableIds): void
    {
        $bill->tables()->sync($tableIds);
        $bill->unsetRelation('tables');
    }

    public static function tableIdsForBill(Bill $bill): array
    {
        if ($bill->relationLoaded('tables')) {
            return $bill->tables
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $linkedIds = $bill->tables()->pluck('tables.id')->map(fn ($id) => (int) $id)->all();

        if ($bill->table_id && ! in_array((int) $bill->table_id, $linkedIds, true)) {
            $linkedIds[] = (int) $bill->table_id;
        }

        return collect($linkedIds)->unique()->values()->all();
    }

    public static function activeBillExistsOnAnyTable(array $tableIds, ?int $ignoreBillId = null): bool
    {
        if ($tableIds === []) {
            return false;
        }

        return Bill::query()
            ->when($ignoreBillId, fn ($query) => $query->whereKeyNot($ignoreBillId))
            ->whereIn('status', self::ACTIVE_BILL_STATUSES)
            ->where(function ($query) use ($tableIds) {
                $query
                    ->whereIn('table_id', $tableIds)
                    ->orWhereHas('tables', fn ($tableQuery) => $tableQuery->whereIn('tables.id', $tableIds));
            })
            ->exists();
    }

    public static function totalCapacity(array $tableIds): int
    {
        if ($tableIds === []) {
            return 0;
        }

        return (int) Table::query()
            ->whereIn('id', $tableIds)
            ->sum('capacity');
    }

    public static function updateTablesStatus(array $tableIds, string $status): void
    {
        if ($tableIds === []) {
            return;
        }

        Table::query()->whereIn('id', $tableIds)->update(['status' => $status]);
    }

    public static function updateBillTablesStatus(Bill $bill, string $status): void
    {
        self::updateTablesStatus(self::tableIdsForBill($bill), $status);
    }
}
