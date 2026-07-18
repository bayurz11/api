<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    DB::table('audit_logs')
        ->where('logged_at', '<', now()->subDays(config('audit.retention_days', 180)))
        ->delete();
})->dailyAt('02:30')->name('purge-expired-audit-logs')->withoutOverlapping();
