<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('purchase_price', 14, 2)->default(0)->after('minimum_stock');
            $table->decimal('last_purchase_price', 14, 2)->default(0)->after('purchase_price');
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('stock_after');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->foreignId('stock_item_id')
                ->nullable()
                ->after('category_id')
                ->constrained('ingredients')
                ->nullOnDelete();
            $table->decimal('stock_deduction_qty', 14, 2)
                ->default(1)
                ->after('stock_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
            $table->dropColumn('stock_deduction_qty');
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'last_purchase_price']);
        });
    }
};
