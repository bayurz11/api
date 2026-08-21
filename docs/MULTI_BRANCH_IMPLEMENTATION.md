# Implementasi Multi-Cabang

Dokumen ini menjelaskan implementasi multi-cabang tahap operasional, kontrak API, dan batas fase saat ini.

## Status implementasi

### Selesai

- Entitas organisasi dan cabang.
- Relasi pengguna ke banyak cabang, termasuk cabang default, status akses, dan snapshot role.
- Cabang aktif disimpan pada token Sanctum sehingga konteks cabang mengikuti sesi perangkat.
- Login dan endpoint profil mengembalikan daftar cabang serta cabang aktif.
- Pengelolaan cabang, penempatan pengguna, serta validasi organisasi dan cabang aktif.
- Konfigurasi menu per cabang untuk SKU lokal, harga, station, status aktif, dan ketersediaan.
- Isolasi data operasional berdasarkan `branch_id` untuk meja, pelanggan, reservasi, tagihan, order, pembayaran, pesanan QR, stok, printer, pengaturan, dan catatan belanja.
- Middleware wajib untuk menyelesaikan konteks cabang dari token pengguna.
- Dashboard, laporan, notifikasi operasional, QR meja, dan manajemen pengguna dibatasi ke cabang aktif.
- Pesanan QR membawa identitas cabang sehingga kode meja yang sama dapat dipakai pada cabang berbeda.
- Migrasi data lama dan seeder demo memasukkan data ke `Cabang Utama`.
- Flutter menampilkan pemilih cabang pada Pengaturan untuk pengguna yang memiliki lebih dari satu cabang.
- Owner/admin dapat membuka halaman Kelola Cabang untuk membuat, mengubah, menonaktifkan cabang, dan mengatur penempatan petugas.
- Role dan permission pengguna dapat berbeda pada setiap cabang dan langsung mengikuti cabang aktif pada token.
- Profil restoran, alamat, dan logo dapat berbeda pada setiap cabang, termasuk URL logo publik berbasis kode cabang.
- Penghapusan menu dari cabang hanya menonaktifkan konfigurasi `branch_menus`; katalog pusat tidak ikut terhapus.
- Laporan penjualan dan dashboard Owner menampilkan perbandingan seluruh cabang dalam organisasi yang sama.
- Pergantian cabang memperbarui token/sesi terenkripsi dan memuat ulang provider operasional.
- Pengujian otomatis mencakup isolasi meja, role, profil, laporan organisasi, salin konfigurasi menu, penempatan pengguna, dan pergantian cabang.

## Kontrak API

- `GET /api/v1/branches`: daftar cabang aktif yang dapat diakses pengguna.
- `POST /api/v1/auth/switch-branch`: mengubah cabang aktif untuk token saat ini.
- `GET /api/v1/branches/manage`: daftar cabang yang dapat dikelola owner/admin.
- `POST /api/v1/branches`: membuat cabang dan menyalin konfigurasi awal dari cabang sumber.
- `PATCH /api/v1/branches/{branch}`: memperbarui identitas dan status cabang.
- `PUT /api/v1/branches/{branch}/users/{user}`: menempatkan pengguna ke cabang.
- `DELETE /api/v1/branches/{branch}/users/{user}`: menghapus akses pengguna dari cabang.
- `GET /api/v1/reports/branch-comparison`: ringkasan omzet dan jumlah transaksi seluruh cabang pada organisasi aktif.
- `GET /api/v1/branches/{branchCode}/restaurant-profile/logo`: logo publik untuk cabang tertentu.

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

## Batas desain

- Nama, kategori, gambar, dan deskripsi menu tetap merupakan katalog pusat agar tidak terjadi duplikasi produk. Harga, SKU lokal, station, status aktif, dan ketersediaan dapat berbeda per cabang.
- Laporan transaksi detail tetap mengikuti cabang aktif. Perbandingan organisasi hanya mengembalikan metrik agregat tiap cabang untuk Owner/Admin dengan permission `reports.view`.
- Satu token hanya membawa satu cabang aktif. Perangkat berbeda dapat memakai cabang aktif berbeda tanpa saling mencabut sesi.

## Deploy

1. Cadangkan database production.
2. Jalankan `php artisan migrate --force` agar data lama dipindahkan ke `Cabang Utama`.
3. Jalankan `php artisan db:seed --class=RoleAndPermissionSeeder --force` untuk permission cabang.
4. Jalankan `php artisan optimize:clear` lalu `php artisan optimize`.
5. Login ulang pada aplikasi agar token lama memperoleh `branch_id` aktif.
6. Uji Cabang A dan Cabang B menggunakan meja, menu, transaksi, serta pengguna yang berbeda sebelum go-live.

## Verifikasi

- Backend: `php artisan test --compact` menghasilkan 59 tes lulus dengan 683 assertion.
- Format backend: `vendor/bin/pint` pada seluruh file perubahan lulus.
- Flutter: `flutter analyze` lulus tanpa issue.
- Flutter: `flutter test` menghasilkan 125 tes lulus untuk ukuran mobile, tablet, dan Windows.
