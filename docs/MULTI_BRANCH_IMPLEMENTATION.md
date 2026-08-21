# Implementasi Multi-Cabang

Dokumen ini menjelaskan fondasi multi-cabang yang disiapkan dalam PR ini dan batas aman implementasinya.

## Selesai dalam PR ini

- Entitas organisasi dan cabang.
- Relasi pengguna ke banyak cabang, termasuk cabang default, status akses, dan snapshot role.
- Konfigurasi menu per cabang untuk SKU lokal, harga, station, status aktif, dan ketersediaan.
- Cabang aktif disimpan pada token Sanctum sehingga konteks cabang mengikuti sesi perangkat.
- Login dan endpoint profil mengembalikan daftar cabang serta cabang aktif.
- Endpoint daftar cabang dan pergantian cabang memvalidasi keanggotaan pengguna.
- Migrasi data lama dan seeder demo memasukkan pengguna serta menu ke `Cabang Utama`.

## Kontrak API

- `GET /api/v1/branches`: daftar cabang aktif yang dapat diakses pengguna.
- `POST /api/v1/auth/switch-branch`: mengubah cabang aktif untuk token saat ini.

Payload pergantian cabang:

```json
{
  "branch_id": 2
}
```

## Resolusi menu cabang

Data pada `menus` tetap menjadi katalog pusat. Nilai operasional menggunakan override dari `branch_menus`:

- SKU: `branch_menus.local_sku`, fallback ke `menus.sku`.
- Harga: `branch_menus.price`, fallback ke `menus.price`.
- Station: `branch_menus.station_type`, fallback ke `menus.station_type`.
- Menu dapat dijual bila konfigurasi pusat dan konfigurasi cabang sama-sama aktif dan tersedia.

## Belum aman untuk diaktifkan penuh

PR ini belum mengisolasi data transaksi berdasarkan cabang. Jangan membuka dua cabang operasional pada production sebelum fase berikut selesai:

1. Tambahkan `branch_id` pada meja, tagihan, pesanan QR, reservasi, pelanggan, printer, stok, shift kasir, pengaturan, dan audit log.
2. Tambahkan middleware `BranchContext` dan filter wajib pada seluruh query operasional.
3. Ubah unique constraint yang saat ini global menjadi unik per cabang bila relevan.
4. Gunakan identitas cabang pada URL atau token QR meja agar kode meja yang sama aman digunakan di cabang berbeda.
5. Tambahkan pengelolaan cabang, penempatan pengguna, dan pemilih cabang pada aplikasi Flutter.
6. Tambahkan pengujian kebocoran data lintas cabang untuk seluruh endpoint.

## Kriteria selesai fase operasional

- Pengguna hanya melihat transaksi, meja, stok, printer, reservasi, dan laporan cabang aktif.
- Owner organisasi dapat melihat laporan gabungan tanpa melewati pembatasan akses cabang.
- Pergantian cabang membatalkan cache layar dan memuat ulang semua provider terkait.
- Pesanan QR selalu masuk ke cabang dan meja yang benar.
- Tes otomatis membuktikan data Cabang A tidak dapat dibaca atau diubah dari token Cabang B.
