<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_name')->nullable()->after('customer_id');
            $table->string('guest_phone', 32)->nullable()->after('guest_name');
            $table->unsignedSmallInteger('duration_minutes')->default(120)->after('reserved_at');
            $table->unsignedSmallInteger('arrival_grace_minutes')->default(15)->after('duration_minutes');
            $table->decimal('deposit_required_amount', 12, 2)->default(0)->after('guest_count');
            $table->string('source')->default('PHONE')->after('status');
            $table->text('cancellation_policy')->nullable()->after('notes');
            $table->text('cancellation_reason')->nullable()->after('cancellation_policy');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('reservation_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['reservation_id', 'table_id']);
            $table->index(['table_id', 'reservation_id']);
        });

        DB::table('reservations')->where('status', 'BOOKED')->update([
            'status' => 'CONFIRMED',
            'confirmed_at' => DB::raw('created_at'),
        ]);

        DB::table('reservations')
            ->whereNotNull('table_id')
            ->orderBy('id')
            ->each(function (object $reservation): void {
                DB::table('reservation_tables')->insertOrIgnore([
                    'reservation_id' => $reservation->id,
                    'table_id' => $reservation->table_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_tables');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'guest_name',
                'guest_phone',
                'duration_minutes',
                'arrival_grace_minutes',
                'deposit_required_amount',
                'source',
                'cancellation_policy',
                'cancellation_reason',
                'confirmed_at',
                'arrived_at',
                'seated_at',
                'cancelled_at',
                'no_show_at',
                'completed_at',
            ]);
        });
    }
};
