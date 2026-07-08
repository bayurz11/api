<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['username' => 'owner'],
            [
                'name' => 'Owner Demo',
                'email' => 'owner@restopos.local',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $owner->syncRoles(['Owner']);

        $cashier = User::updateOrCreate(
            ['username' => 'kasir01'],
            [
                'name' => 'Kasir Demo',
                'email' => 'kasir01@restopos.local',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $cashier->syncRoles(['Kasir']);

        $waiter = User::updateOrCreate(
            ['username' => 'waiter01'],
            [
                'name' => 'Waiter Demo',
                'email' => 'waiter01@restopos.local',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $waiter->syncRoles(['Waiter']);

        $kitchen = User::updateOrCreate(
            ['username' => 'kitchen01'],
            [
                'name' => 'Kitchen Demo',
                'email' => 'kitchen01@restopos.local',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $kitchen->syncRoles(['Kitchen']);

        $bar = User::updateOrCreate(
            ['username' => 'bar01'],
            [
                'name' => 'Bar Demo',
                'email' => 'bar01@restopos.local',
                'password' => 'password',
                'is_active' => true,
            ],
        );
        $bar->syncRoles(['Bar']);

        DB::table('customers')->upsert([
            [
                'name' => 'Budi Santoso',
                'phone' => '081234567890',
                'email' => 'budi@example.com',
                'member_code' => 'MBR-001',
                'reward_points' => 120,
                'notes' => 'Pelanggan tetap',
            ],
        ], ['member_code'], ['name', 'phone', 'email', 'reward_points', 'notes']);

        DB::table('tables')->upsert([
            ['code' => 'T01', 'name' => 'Meja 01', 'capacity' => 4, 'area' => 'Indoor', 'status' => 'AVAILABLE', 'is_active' => true],
            ['code' => 'T02', 'name' => 'Meja 02', 'capacity' => 4, 'area' => 'Indoor', 'status' => 'AVAILABLE', 'is_active' => true],
            ['code' => 'T03', 'name' => 'Meja 03', 'capacity' => 2, 'area' => 'Teras', 'status' => 'CLEANING', 'is_active' => true],
        ], ['code'], ['name', 'capacity', 'area', 'status', 'is_active']);

        DB::table('menu_categories')->upsert([
            ['name' => 'Makanan', 'station_type' => 'KITCHEN', 'sort_order' => 1, 'is_active' => true],
            ['name' => 'Minuman', 'station_type' => 'BAR', 'sort_order' => 2, 'is_active' => true],
        ], ['name'], ['station_type', 'sort_order', 'is_active']);

        $makananId = DB::table('menu_categories')->where('name', 'Makanan')->value('id');
        $minumanId = DB::table('menu_categories')->where('name', 'Minuman')->value('id');

        DB::table('menus')->upsert([
            [
                'category_id' => $makananId,
                'sku' => 'MKN-001',
                'name' => 'Nasi Goreng',
                'description' => 'Nasi goreng spesial resto',
                'price' => 28000,
                'station_type' => 'KITCHEN',
                'is_available' => true,
                'is_active' => true,
            ],
            [
                'category_id' => $makananId,
                'sku' => 'MKN-002',
                'name' => 'Ayam Bakar',
                'description' => 'Ayam bakar bumbu manis',
                'price' => 32000,
                'station_type' => 'KITCHEN',
                'is_available' => true,
                'is_active' => true,
            ],
            [
                'category_id' => $minumanId,
                'sku' => 'MNM-001',
                'name' => 'Es Teh',
                'description' => 'Es teh manis',
                'price' => 8000,
                'station_type' => 'BAR',
                'is_available' => true,
                'is_active' => true,
            ],
        ], ['sku'], ['category_id', 'name', 'description', 'price', 'station_type', 'is_available', 'is_active']);

        foreach ([
            [
                'name' => 'Kitchen Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.101',
                'station_type' => 'KITCHEN',
                'is_active' => true,
            ],
            [
                'name' => 'Bar Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.102',
                'station_type' => 'BAR',
                'is_active' => true,
            ],
            [
                'name' => 'Cashier Printer',
                'printer_type' => 'ESC_POS',
                'connection_type' => 'LAN',
                'address' => '192.168.1.103',
                'station_type' => null,
                'is_active' => true,
            ],
        ] as $printer) {
            DB::table('printers')->updateOrInsert(
                ['name' => $printer['name']],
                $printer,
            );
        }
    }
}
