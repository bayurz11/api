<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->timestamp('event_scheduled_at')->nullable()->after('opened_at');
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'event_reminder_minutes_before'],
            [
                'value' => '1440',
                'group' => 'reminders',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'event_reminder_minutes_before')->delete();

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('event_scheduled_at');
        });
    }
};
