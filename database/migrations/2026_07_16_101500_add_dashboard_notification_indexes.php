<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->index(['status', 'is_active'], 'tables_status_active_index');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['status', 'reserved_at'], 'reservations_status_reserved_at_index');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->index(['bill_type', 'status', 'event_scheduled_at'], 'bills_type_status_event_at_index');
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->index(['movement_type', 'created_at'], 'stock_movements_type_created_at_index');
            $table->index(['ingredient_id', 'created_at'], 'stock_movements_ingredient_created_at_index');
        });

        Schema::table('shopping_notes', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'shopping_notes_status_created_at_index');
            $table->index(['ingredient_id', 'status'], 'shopping_notes_ingredient_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_notes', function (Blueprint $table) {
            $table->dropIndex('shopping_notes_status_created_at_index');
            $table->dropIndex('shopping_notes_ingredient_status_index');
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_type_created_at_index');
            $table->dropIndex('stock_movements_ingredient_created_at_index');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_type_status_event_at_index');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_status_reserved_at_index');
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex('tables_status_active_index');
        });
    }
};
