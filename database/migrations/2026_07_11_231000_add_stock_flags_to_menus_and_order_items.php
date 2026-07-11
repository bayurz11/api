<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('is_stock_available')->default(true)->after('is_available');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('stock_deducted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('stock_deducted');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('is_stock_available');
        });
    }
};
