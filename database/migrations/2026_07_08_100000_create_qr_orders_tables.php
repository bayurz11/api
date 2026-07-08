<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('guest_token')->unique();
            $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $table->foreignId('linked_bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->foreignId('approved_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->text('notes')->nullable();
            $table->string('status')->default('PENDING');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('qr_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_order_id')->constrained('qr_orders')->cascadeOnDelete();
            $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('menu_name');
            $table->string('station_type')->default('KITCHEN');
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->text('notes')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_order_items');
        Schema::dropIfExists('qr_orders');
    }
};
