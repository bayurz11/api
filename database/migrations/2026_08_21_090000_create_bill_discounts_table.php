<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->string('voucher_code', 80)->nullable();
            $table->string('reason', 255);
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['bill_id', 'voided_at']);
            $table->index(['type', 'voucher_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_discounts');
    }
};
