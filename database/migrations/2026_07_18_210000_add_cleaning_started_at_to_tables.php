<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->timestamp('cleaning_started_at')->nullable()->after('status');
            $table->index(['status', 'cleaning_started_at'], 'tables_cleaning_release_index');
        });

        DB::table('tables')
            ->where('status', 'CLEANING')
            ->whereNull('cleaning_started_at')
            ->update(['cleaning_started_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex('tables_cleaning_release_index');
            $table->dropColumn('cleaning_started_at');
        });
    }
};
