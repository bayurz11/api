<?php

namespace App\Support;

use App\Models\Table;
use Illuminate\Support\Facades\Cache;

class TableCleaningManager
{
    public const RELEASE_AFTER_MINUTES = 10;

    private const CHECK_INTERVAL_SECONDS = 10;

    public static function releaseExpiredTables(bool $force = false): int
    {
        if (! $force && ! Cache::add(
            'tables:cleaning-release-check',
            true,
            now()->addSeconds(self::CHECK_INTERVAL_SECONDS),
        )) {
            return 0;
        }

        $cutoff = now()->subMinutes(self::RELEASE_AFTER_MINUTES);

        return Table::query()
            ->where('status', 'CLEANING')
            ->where(function ($query) use ($cutoff) {
                $query
                    ->where('cleaning_started_at', '<=', $cutoff)
                    ->orWhere(function ($fallbackQuery) use ($cutoff) {
                        $fallbackQuery
                            ->whereNull('cleaning_started_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->update([
                'status' => 'AVAILABLE',
                'cleaning_started_at' => null,
                'updated_at' => now(),
            ]);
    }

    public static function statusAttributes(string $status): array
    {
        return [
            'status' => $status,
            'cleaning_started_at' => $status === 'CLEANING' ? now() : null,
        ];
    }
}
