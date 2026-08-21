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
- Pergantian cabang memperbarui token/sesi terenkripsi dan memuat ulang provider operasional.
- Pengujian otomatis mencakup isolasi meja lintas cabang, salin konfigurasi menu, penempatan pengguna, dan pergantian cabang.

## Kontrak API

- `GET /api/v1/branches`: daftar cabang aktif yang dapat diakses pengguna.
- `POST /api/v1/auth/switch-branch`: mengubah cabang aktif untuk token saat ini.
- `GET /api/v1/branches/manage`: daftar cabang yang dapat dikelola owner/admin.
- `POST /api/v1/branches`: membuat cabang dan menyalin konfigurasi awal dari cabang sumber.
- `PATCH /api/v1/branches/{branch}`: memperbarui identitas dan status cabang.
- `PUT /api/v1/branches/{branch}/users/{user}`: menempatkan pengguna ke cabang.
- `DELETE /api/v1/branches/{branch}/users/{user}`: menghapus akses pengguna dari cabang.

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

## Batas fase saat ini

- Role pengguna masih bersifat global pada organisasi. Snapshot `role_name` tersedia pada relasi cabang, tetapi role berbeda per cabang belum diaktifkan karena permission Spatie saat ini bersifat global.
- Nama, kategori, gambar, dan deskripsi menu merupakan katalog pusat. Harga, SKU lokal, station, status aktif, dan ketersediaan dapat berbeda per cabang.
- Menghapus menu masih menghapus katalog pusat. Untuk operasional multi-cabang, nonaktifkan menu pada cabang; pembatasan penghapusan katalog pusat perlu diselesaikan pada fase UI manajemen cabang.
- Laporan owner lintas seluruh cabang belum tersedia. Endpoint laporan saat ini sengaja mengikuti cabang aktif untuk mencegah kebocoran data.
- Profil/logo publik restoran masih memerlukan kontrak cabang eksplisit jika setiap cabang akan memakai identitas visual berbeda.

## Deploy

1. Cadangkan database production.
2. Jalankan `php artisan migrate --force` agar data lama dipindahkan ke `Cabang Utama`.
3. Jalankan `php artisan db:seed --class=RoleAndPermissionSeeder --force` untuk permission cabang.
4. Jalankan `php artisan optimize:clear` lalu `php artisan optimize`.
5. Login ulang pada aplikasi agar token lama memperoleh `branch_id` aktif.
6. Uji Cabang A dan Cabang B menggunakan meja, menu, transaksi, serta pengguna yang berbeda sebelum go-live.

## Verifikasi

- Backend: `php artisan test` menghasilkan 56 tes lulus dengan 661 assertion.
- Format backend: `vendor/bin/pint --dirty` lulus.
- Flutter: `flutter analyze` lulus tanpa issue.
- Flutter: `flutter test` menghasilkan 125 tes lulus untuk ukuran mobile, tablet, dan Windows.
