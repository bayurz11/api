<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('unit', 30);
            $table->decimal('current_stock', 14, 2)->default(0);
            $table->decimal('minimum_stock', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('qty_per_portion', 14, 2);
            $table->timestamps();

            $table->unique(['menu_id', 'ingredient_id']);
        });

        Schema::create('ingredient_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('qty_delta', 14, 2);
            $table->decimal('stock_before', 14, 2);
            $table->decimal('stock_after', 14, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_stock_movements');
        Schema::dropIfExists('menu_ingredients');
        Schema::dropIfExists('ingredients');
    }
};
