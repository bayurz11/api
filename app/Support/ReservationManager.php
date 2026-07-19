<?php

namespace App\Support;

use App\Models\Reservation;
use App\Models\Table;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ReservationManager
{
    public const ACTIVE_SCHEDULE_STATUSES = ['PENDING', 'CONFIRMED', 'ARRIVED'];

    public static function normalizeTableIds(?int $primaryTableId, array $extraTableIds = []): array
    {
        return collect([$primaryTableId, ...$extraTableIds])
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function validateAssignment(
        array $tableIds,
        int $guestCount,
        CarbonInterface $reservedAt,
        int $durationMinutes,
        ?int $ignoreReservationId = null,
    ): void {
        if ($tableIds === []) {
            throw ValidationException::withMessages([
                'table_ids' => 'Pilih minimal satu meja untuk reservasi.',
            ]);
        }

        $tables = Table::query()->whereIn('id', $tableIds)->get();
        if ($tables->count() !== count($tableIds) || $tables->contains(fn (Table $table) => ! $table->is_active)) {
            throw ValidationException::withMessages([
                'table_ids' => 'Salah satu meja tidak aktif atau tidak ditemukan.',
            ]);
        }

        if ((int) $tables->sum('capacity') < $guestCount) {
            throw ValidationException::withMessages([
                'table_ids' => 'Total kapasitas meja tidak mencukupi jumlah tamu.',
            ]);
        }

        $requestedEnd = $reservedAt->copy()->addMinutes($durationMinutes);
        $conflict = Reservation::query()
            ->whereIn('status', self::ACTIVE_SCHEDULE_STATUSES)
            ->when($ignoreReservationId, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->where('reserved_at', '<', $requestedEnd)
            ->where(function ($query) use ($tableIds) {
                $query->whereIn('table_id', $tableIds)
                    ->orWhereHas('tables', fn ($tableQuery) => $tableQuery->whereIn('tables.id', $tableIds));
            })
            ->get()
            ->contains(function (Reservation $reservation) use ($reservedAt): bool {
                $existingEnd = $reservation->reserved_at
                    ->copy()
                    ->addMinutes($reservation->duration_minutes ?: 120);

                return $existingEnd->gt($reservedAt);
            });

        if ($conflict) {
            throw ValidationException::withMessages([
                'reserved_at' => 'Jadwal berbenturan dengan reservasi aktif pada meja yang dipilih.',
            ]);
        }
    }

    public static function syncTables(Reservation $reservation, array $tableIds): void
    {
        $reservation->tables()->sync($tableIds);
        $reservation->updateQuietly(['table_id' => $tableIds[0] ?? null]);
        $reservation->unsetRelation('tables');
    }

    public static function tableIds(Reservation $reservation): array
    {
        $ids = $reservation->tables()->pluck('tables.id')->map(fn ($id) => (int) $id)->all();
        if ($reservation->table_id && ! in_array((int) $reservation->table_id, $ids, true)) {
            $ids[] = (int) $reservation->table_id;
        }

        return array_values(array_unique($ids));
    }

    public static function releaseReservedTables(Reservation $reservation): void
    {
        $tableIds = self::tableIds($reservation);
        $busyIds = Table::query()
            ->whereIn('id', $tableIds)
            ->whereHas('linkedBills', fn ($query) => $query->whereIn('bills.status', BillTableManager::ACTIVE_BILL_STATUSES))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        BillTableManager::updateTablesStatus(array_values(array_diff($tableIds, $busyIds)), 'AVAILABLE');
    }
}
