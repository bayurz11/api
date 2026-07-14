<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->string('item_name');
            $table->string('item_unit', 30)->nullable();
            $table->decimal('requested_qty', 14, 2)->nullable();
            $table->decimal('current_stock', 14, 2)->nullable();
            $table->decimal('minimum_stock', 14, 2)->nullable();
            $table->decimal('estimated_unit_price', 14, 2)->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->string('source', 20)->default('MANUAL');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_notes');
    }
};
