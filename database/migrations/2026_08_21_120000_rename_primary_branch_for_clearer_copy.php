<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('branches')
            ->where('code', 'UTAMA')
            ->where('name', 'Cabang Utama')
            ->update([
                'name' => 'Warung Babeh Cabang Utama',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('branches')
            ->where('code', 'UTAMA')
            ->where('name', 'Warung Babeh Cabang Utama')
            ->update([
                'name' => 'Cabang Utama',
                'updated_at' => now(),
            ]);
    }
};
