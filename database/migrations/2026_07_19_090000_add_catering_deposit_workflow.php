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
            $table->unsignedTinyInteger('deposit_required_percent')->nullable()->after('event_scheduled_at');
            $table->timestamp('payment_due_at')->nullable()->after('deposit_required_percent');
            $table->text('cancellation_policy')->nullable()->after('payment_due_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_type')->default('REGULAR')->after('payment_method');
            $table->index(['bill_id', 'payment_type', 'status'], 'payments_bill_type_status_index');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('bill_id')->constrained('payments')->nullOnDelete();
            $table->string('payment_method')->default('CASH')->after('amount');
            $table->string('reference_no')->nullable()->after('payment_method');
            $table->text('notes')->nullable()->after('reference_no');
        });

        DB::table('deposits')
            ->whereNotNull('bill_id')
            ->where('status', 'PAID')
            ->whereNull('payment_id')
            ->orderBy('id')
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    $paymentId = DB::table('payments')->insertGetId([
                        'bill_id' => $deposit->bill_id,
                        'payment_no' => 'DP-MIG-'.$deposit->id,
                        'payment_method' => $deposit->payment_method ?? 'CASH',
                        'payment_type' => 'DEPOSIT',
                        'amount' => $deposit->amount,
                        'reference_no' => $deposit->reference_no ?? null,
                        'paid_by' => $deposit->received_by,
                        'paid_at' => $deposit->received_at ?? $deposit->created_at,
                        'status' => 'PAID',
                        'created_at' => $deposit->created_at,
                        'updated_at' => now(),
                    ]);

                    DB::table('deposits')->where('id', $deposit->id)->update([
                        'payment_id' => $paymentId,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('bills')
            ->whereIn('id', DB::table('deposits')->whereNotNull('bill_id')->select('bill_id'))
            ->orderBy('id')
            ->chunkById(100, function ($bills): void {
                foreach ($bills as $bill) {
                    $paid = (float) DB::table('payments')
                        ->where('bill_id', $bill->id)
                        ->where('status', 'PAID')
                        ->sum('amount');
                    $refunded = (float) DB::table('payments')
                        ->where('bill_id', $bill->id)
                        ->where('status', 'REFUND')
                        ->sum('amount');
                    $netPaid = max($paid - $refunded, 0);

                    DB::table('bills')->where('id', $bill->id)->update([
                        'paid_total' => $netPaid,
                        'balance_due' => max((float) $bill->grand_total - $netPaid, 0),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['payment_method', 'reference_no', 'notes']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_bill_type_status_index');
            $table->dropColumn('payment_type');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['deposit_required_percent', 'payment_due_at', 'cancellation_policy']);
        });
    }
};
