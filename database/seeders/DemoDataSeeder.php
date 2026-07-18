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
        $timestamp = now();

        DB::table('settings')->updateOrInsert(
            ['key' => 'restaurant_name'],
            ['value' => 'Warung Babeh', 'group' => 'restaurant', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );
        DB::table('settings')->updateOrInsert(
            ['key' => 'restaurant_address'],
            ['value' => 'Jl. Contoh No. 1, Jakarta', 'group' => 'restaurant', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );

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

        DB::table('menus')->upsert(
            array_merge(
                $this->buildKitchenMenus($makananId),
                $this->buildBarMenus($minumanId),
            ),
            ['sku'],
            ['category_id', 'name', 'description', 'price', 'station_type', 'is_available', 'is_active'],
        );

        DB::table('ingredients')->upsert([
            ['code' => 'BHN-001', 'name' => 'Beras', 'unit' => 'porsi', 'current_stock' => 120, 'minimum_stock' => 20, 'purchase_price' => 4000, 'last_purchase_price' => 4000, 'notes' => 'Stok nasi siap jual', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-002', 'name' => 'Ayam Fillet', 'unit' => 'porsi', 'current_stock' => 80, 'minimum_stock' => 15, 'purchase_price' => 12000, 'last_purchase_price' => 12000, 'notes' => 'Stok lauk ayam', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-003', 'name' => 'Telur', 'unit' => 'butir', 'current_stock' => 180, 'minimum_stock' => 36, 'purchase_price' => 2500, 'last_purchase_price' => 2500, 'notes' => 'Tambahan telur', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-004', 'name' => 'Bumbu Nasi Goreng', 'unit' => 'porsi', 'current_stock' => 90, 'minimum_stock' => 15, 'purchase_price' => 2500, 'last_purchase_price' => 2500, 'notes' => 'Paket bumbu siap masak', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-005', 'name' => 'Teh Melati', 'unit' => 'gelas', 'current_stock' => 150, 'minimum_stock' => 30, 'purchase_price' => 2500, 'last_purchase_price' => 2500, 'notes' => 'Untuk teh panas dan es teh', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-006', 'name' => 'Gula Cair', 'unit' => 'gelas', 'current_stock' => 220, 'minimum_stock' => 40, 'purchase_price' => 1200, 'last_purchase_price' => 1200, 'notes' => 'Pemanis minuman', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-007', 'name' => 'Jeruk Peras', 'unit' => 'gelas', 'current_stock' => 90, 'minimum_stock' => 20, 'purchase_price' => 3500, 'last_purchase_price' => 3500, 'notes' => 'Untuk es jeruk', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-008', 'name' => 'Es Batu', 'unit' => 'gelas', 'current_stock' => 300, 'minimum_stock' => 60, 'purchase_price' => 300, 'last_purchase_price' => 300, 'notes' => 'Untuk minuman dingin', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-009', 'name' => 'Ayam Paha Atas', 'unit' => 'potong', 'current_stock' => 40, 'minimum_stock' => 10, 'purchase_price' => 11000, 'last_purchase_price' => 11000, 'notes' => 'Varian ayam bakar paha atas', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-010', 'name' => 'Ayam Dada', 'unit' => 'potong', 'current_stock' => 35, 'minimum_stock' => 10, 'purchase_price' => 12000, 'last_purchase_price' => 12000, 'notes' => 'Varian ayam bakar dada', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['code' => 'BHN-011', 'name' => 'Ayam Sayap', 'unit' => 'potong', 'current_stock' => 30, 'minimum_stock' => 8, 'purchase_price' => 9000, 'last_purchase_price' => 9000, 'notes' => 'Varian ayam bakar sayap', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ], ['code'], ['name', 'unit', 'current_stock', 'minimum_stock', 'purchase_price', 'last_purchase_price', 'notes', 'is_active', 'updated_at']);

        $ingredients = DB::table('ingredients')->pluck('id', 'code');
        DB::table('menus')->where('sku', 'MKN-001')->update(['stock_item_id' => $ingredients['BHN-001'], 'stock_deduction_qty' => 1]);
        DB::table('menus')->where('sku', 'MKN-009')->update(['stock_item_id' => null, 'stock_deduction_qty' => 1]);
        DB::table('menus')->where('sku', 'MKN-010')->update(['stock_item_id' => $ingredients['BHN-002'], 'stock_deduction_qty' => 1]);
        DB::table('menus')->where('sku', 'MNM-001')->update(['stock_item_id' => $ingredients['BHN-005'], 'stock_deduction_qty' => 1]);
        DB::table('menus')->where('sku', 'MNM-003')->update(['stock_item_id' => $ingredients['BHN-007'], 'stock_deduction_qty' => 1]);

        $ayamBakarId = DB::table('menus')->where('sku', 'MKN-009')->value('id');

        DB::table('menu_ingredients')->updateOrInsert(
            ['menu_id' => $ayamBakarId, 'ingredient_id' => $ingredients['BHN-001']],
            ['qty_per_portion' => 1, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        );

        foreach ([
            ['name' => 'Paha Atas', 'stock_code' => 'BHN-009', 'sort_order' => 1],
            ['name' => 'Dada', 'stock_code' => 'BHN-010', 'sort_order' => 2],
            ['name' => 'Sayap', 'stock_code' => 'BHN-011', 'sort_order' => 3],
        ] as $option) {
            DB::table('menu_options')->updateOrInsert(
                ['menu_id' => $ayamBakarId, 'name' => $option['name']],
                [
                    'stock_item_id' => $ingredients[$option['stock_code']],
                    'stock_deduction_qty' => 1,
                    'price_delta' => 0,
                    'is_available' => true,
                    'is_stock_available' => true,
                    'is_active' => true,
                    'sort_order' => $option['sort_order'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            );
        }

    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildKitchenMenus(int $categoryId): array
    {
        return [
            ['category_id' => $categoryId, 'sku' => 'MKN-001', 'name' => 'Nasi Goreng Babeh', 'description' => 'Nasi goreng spesial dengan ayam suwir dan telur.', 'price' => 28000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-002', 'name' => 'Nasi Goreng Kambing', 'description' => 'Nasi goreng kambing dengan rempah khas Betawi.', 'price' => 36000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-003', 'name' => 'Mie Goreng Jawa', 'description' => 'Mie goreng manis gurih dengan sayur dan ayam.', 'price' => 26000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-004', 'name' => 'Mie Rebus Kampung', 'description' => 'Mie rebus kuah kental dengan telur dan sayuran.', 'price' => 25000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-005', 'name' => 'Soto Betawi Original', 'description' => 'Soto Betawi kuah santan dengan daging sapi.', 'price' => 38000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-006', 'name' => 'Soto Betawi Campur', 'description' => 'Soto Betawi dengan daging, paru, dan babat.', 'price' => 45000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-007', 'name' => 'Soto Betawi Pecak', 'description' => 'Soto Betawi dengan sambal pecak khas.', 'price' => 42000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-008', 'name' => 'Sop Iga Sapi', 'description' => 'Sop iga sapi kuah bening dengan sayur.', 'price' => 48000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-009', 'name' => 'Ayam Bakar Madu', 'description' => 'Ayam bakar bumbu madu dengan nasi putih.', 'price' => 32000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-010', 'name' => 'Ayam Goreng Serundeng', 'description' => 'Ayam goreng renyah dengan serundeng gurih.', 'price' => 30000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-011', 'name' => 'Lele Goreng', 'description' => 'Lele goreng garing dengan sambal dan lalapan.', 'price' => 24000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-012', 'name' => 'Nila Bakar', 'description' => 'Ikan nila bakar sambal kecap.', 'price' => 34000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-013', 'name' => 'Gurame Goreng', 'description' => 'Gurame goreng crispy saus asam manis.', 'price' => 58000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-014', 'name' => 'Iga Bakar Sambal Ijo', 'description' => 'Iga bakar empuk dengan sambal ijo pedas.', 'price' => 55000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-015', 'name' => 'Nasi Uduk Ayam', 'description' => 'Nasi uduk gurih dengan ayam goreng dan sambal.', 'price' => 30000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-016', 'name' => 'Nasi Timbel Komplit', 'description' => 'Nasi timbel dengan ayam, tahu, tempe, dan lalapan.', 'price' => 34000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-017', 'name' => 'Bebek Goreng', 'description' => 'Bebek goreng kremes dengan sambal merah.', 'price' => 42000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-018', 'name' => 'Pecel Lele Paket', 'description' => 'Lele goreng, nasi, tahu, tempe, dan sambal.', 'price' => 26000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-019', 'name' => 'Tahu Tempe Goreng', 'description' => 'Tahu dan tempe goreng hangat.', 'price' => 14000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-020', 'name' => 'Kentang Goreng', 'description' => 'Kentang goreng renyah dengan saus sambal.', 'price' => 18000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-021', 'name' => 'Bakwan Jagung', 'description' => 'Bakwan jagung gurih untuk lauk pendamping.', 'price' => 16000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-022', 'name' => 'Sate Taichan', 'description' => 'Sate ayam taichan dengan sambal pedas.', 'price' => 28000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-023', 'name' => 'Sate Maranggi', 'description' => 'Sate daging sapi bumbu maranggi.', 'price' => 36000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-024', 'name' => 'Rawon Daging', 'description' => 'Rawon kuah hitam dengan potongan daging sapi.', 'price' => 40000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MKN-025', 'name' => 'Nasi Putih', 'description' => 'Nasi putih hangat untuk pendamping hidangan.', 'price' => 7000, 'station_type' => 'KITCHEN', 'is_available' => true, 'is_active' => true],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBarMenus(int $categoryId): array
    {
        return [
            ['category_id' => $categoryId, 'sku' => 'MNM-001', 'name' => 'Es Teh Manis', 'description' => 'Es teh manis segar.', 'price' => 8000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-002', 'name' => 'Teh Tawar Hangat', 'description' => 'Teh tawar hangat.', 'price' => 5000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-003', 'name' => 'Es Jeruk', 'description' => 'Jeruk peras dingin segar.', 'price' => 10000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-004', 'name' => 'Jeruk Hangat', 'description' => 'Jeruk hangat manis.', 'price' => 9000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-005', 'name' => 'Lemon Tea', 'description' => 'Teh lemon dingin dengan rasa segar.', 'price' => 12000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-006', 'name' => 'Kopi Hitam', 'description' => 'Kopi hitam panas.', 'price' => 12000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-007', 'name' => 'Kopi Susu', 'description' => 'Kopi susu hangat manis.', 'price' => 15000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-008', 'name' => 'Es Kopi Susu Gula Aren', 'description' => 'Es kopi susu dengan gula aren.', 'price' => 18000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-009', 'name' => 'Cappuccino', 'description' => 'Cappuccino hangat creamy.', 'price' => 20000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-010', 'name' => 'Cafe Latte', 'description' => 'Cafe latte dengan susu lembut.', 'price' => 22000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-011', 'name' => 'Susu Cokelat', 'description' => 'Susu cokelat dingin.', 'price' => 14000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-012', 'name' => 'Thai Tea', 'description' => 'Thai tea manis dan creamy.', 'price' => 16000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-013', 'name' => 'Matcha Latte', 'description' => 'Minuman matcha dingin creamy.', 'price' => 18000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-014', 'name' => 'Taro Latte', 'description' => 'Minuman taro dingin manis.', 'price' => 18000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
            ['category_id' => $categoryId, 'sku' => 'MNM-015', 'name' => 'Jus Alpukat', 'description' => 'Jus alpukat lembut dengan susu cokelat.', 'price' => 22000, 'station_type' => 'BAR', 'is_available' => true, 'is_active' => true],
        ];
    }
}
