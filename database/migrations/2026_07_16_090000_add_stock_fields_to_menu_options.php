<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_options', function (Blueprint $table) {
            $table->foreignId('stock_item_id')
                ->nullable()
                ->after('menu_id')
                ->constrained('ingredients')
                ->nullOnDelete();
            $table->decimal('stock_deduction_qty', 14, 2)
                ->default(1)
                ->after('stock_item_id');
            $table->boolean('is_stock_available')
                ->default(true)
                ->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('menu_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
            $table->dropColumn(['stock_deduction_qty', 'is_stock_available']);
        });
    }
};
