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
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'entity_type', 'entity_id'], 'notification_reads_unique');
            $table->index(['user_id', 'channel', 'entity_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
