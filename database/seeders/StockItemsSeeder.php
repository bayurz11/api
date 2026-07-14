<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StockItemsSeeder extends Seeder
{
    /**
     * Seeder dummy stok barang sekali pakai.
     * Aman dijalankan ulang karena memakai upsert berdasarkan code.
     */
    public function run(): void
    {
        $timestamp = now();

        DB::table('ingredients')->upsert(
            [
                ['code' => 'BHN-001', 'name' => 'Beras', 'unit' => 'kg', 'current_stock' => 25, 'minimum_stock' => 5, 'purchase_price' => 16000, 'last_purchase_price' => 16000, 'notes' => 'Bahan utama nasi putih dan nasi goreng.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-002', 'name' => 'Ayam Fillet', 'unit' => 'kg', 'current_stock' => 18, 'minimum_stock' => 4, 'purchase_price' => 42000, 'last_purchase_price' => 42000, 'notes' => 'Untuk ayam bakar, ayam goreng, dan topping nasi goreng.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-003', 'name' => 'Telur Ayam', 'unit' => 'butir', 'current_stock' => 120, 'minimum_stock' => 24, 'purchase_price' => 2600, 'last_purchase_price' => 2600, 'notes' => 'Tambahan lauk dan campuran menu.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-004', 'name' => 'Bumbu Nasi Goreng', 'unit' => 'pak', 'current_stock' => 30, 'minimum_stock' => 8, 'purchase_price' => 18000, 'last_purchase_price' => 18000, 'notes' => 'Bumbu siap pakai untuk menu nasi goreng.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-005', 'name' => 'Teh Melati', 'unit' => 'pak', 'current_stock' => 22, 'minimum_stock' => 6, 'purchase_price' => 14500, 'last_purchase_price' => 14500, 'notes' => 'Untuk teh panas dan es teh.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-006', 'name' => 'Gula Cair', 'unit' => 'liter', 'current_stock' => 15, 'minimum_stock' => 4, 'purchase_price' => 18000, 'last_purchase_price' => 18000, 'notes' => 'Pemanis minuman dingin dan hangat.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-007', 'name' => 'Jeruk Peras', 'unit' => 'kg', 'current_stock' => 10, 'minimum_stock' => 3, 'purchase_price' => 22000, 'last_purchase_price' => 22000, 'notes' => 'Untuk es jeruk dan jeruk hangat.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-008', 'name' => 'Es Batu', 'unit' => 'bag', 'current_stock' => 40, 'minimum_stock' => 10, 'purchase_price' => 6000, 'last_purchase_price' => 6000, 'notes' => 'Untuk semua minuman dingin.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-009', 'name' => 'Minyak Goreng', 'unit' => 'liter', 'current_stock' => 20, 'minimum_stock' => 5, 'purchase_price' => 18500, 'last_purchase_price' => 18500, 'notes' => 'Untuk penggorengan umum dapur.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-010', 'name' => 'Bawang Merah', 'unit' => 'kg', 'current_stock' => 8, 'minimum_stock' => 2, 'purchase_price' => 38000, 'last_purchase_price' => 38000, 'notes' => 'Bumbu dasar dapur.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-011', 'name' => 'Bawang Putih', 'unit' => 'kg', 'current_stock' => 7, 'minimum_stock' => 2, 'purchase_price' => 42000, 'last_purchase_price' => 42000, 'notes' => 'Bumbu dasar dapur.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-012', 'name' => 'Cabai Merah', 'unit' => 'kg', 'current_stock' => 6, 'minimum_stock' => 2, 'purchase_price' => 52000, 'last_purchase_price' => 52000, 'notes' => 'Untuk sambal dan tumisan.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-013', 'name' => 'Kopi Bubuk', 'unit' => 'pak', 'current_stock' => 12, 'minimum_stock' => 3, 'purchase_price' => 28500, 'last_purchase_price' => 28500, 'notes' => 'Untuk kopi hitam dan kopi susu.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-014', 'name' => 'Susu Cair', 'unit' => 'liter', 'current_stock' => 14, 'minimum_stock' => 4, 'purchase_price' => 21000, 'last_purchase_price' => 21000, 'notes' => 'Untuk latte, cappuccino, dan minuman susu.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
                ['code' => 'BHN-015', 'name' => 'Gas LPG', 'unit' => 'tabung', 'current_stock' => 4, 'minimum_stock' => 2, 'purchase_price' => 210000, 'last_purchase_price' => 210000, 'notes' => 'Kebutuhan operasional dapur.', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ],
            ['code'],
            ['name', 'unit', 'current_stock', 'minimum_stock', 'purchase_price', 'last_purchase_price', 'notes', 'is_active', 'updated_at'],
        );
    }
}
