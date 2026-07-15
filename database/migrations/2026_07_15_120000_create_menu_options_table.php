<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('menu_option_id')->nullable()->after('menu_id')->constrained('menu_options')->nullOnDelete();
        });

        Schema::table('qr_order_items', function (Blueprint $table) {
            $table->foreignId('menu_option_id')->nullable()->after('menu_id')->constrained('menu_options')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_option_id');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_option_id');
        });

        Schema::dropIfExists('menu_options');
    }
};
