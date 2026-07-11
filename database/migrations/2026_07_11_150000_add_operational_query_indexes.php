<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'payments_status_paid_at_index');
            $table->index(['bill_id', 'status'], 'payments_bill_status_index');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'bills_status_created_at_index');
            $table->index(['table_id', 'status'], 'bills_table_status_index');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->index(['created_at', 'menu_id'], 'bill_items_created_menu_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['station_type', 'status'], 'order_items_station_status_index');
            $table->index(['status', 'served_at'], 'order_items_status_served_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_paid_at_index');
            $table->dropIndex('payments_bill_status_index');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_status_created_at_index');
            $table->dropIndex('bills_table_status_index');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropIndex('bill_items_created_menu_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_station_status_index');
            $table->dropIndex('order_items_status_served_index');
        });
    }
};
