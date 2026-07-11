<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('printers')
            ->where(function ($query) {
                $query
                    ->where('name', 'Kitchen Printer')
                    ->where('address', '192.168.1.101');
            })
            ->orWhere(function ($query) {
                $query
                    ->where('name', 'Bar Printer')
                    ->where('address', '192.168.1.102');
            })
            ->orWhere(function ($query) {
                $query
                    ->where('name', 'Cashier Printer')
                    ->where('address', '192.168.1.103');
            })
            ->delete();
    }

    public function down(): void
    {
        DB::table('printers')->insertOrIgnore([
            [
                'name' => 'Kitchen Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.101',
                'station_type' => 'KITCHEN',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bar Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.102',
                'station_type' => 'BAR',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cashier Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.103',
                'station_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
};
