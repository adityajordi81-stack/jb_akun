# Panduan Admin Lootlane

## Membuka panel admin

1. Jalankan **Apache** di XAMPP.
2. Buka `http://localhost/jb_akun_al_game/admin.php`.
3. Saat pertama kali, buat username admin dan kata sandi minimal 12 karakter. Setelah akun dibuat, halaman selanjutnya meminta login.
4. Pilih **Edit** untuk mengubah produk, atau **Tambah produk** untuk membuat listing baru. Gambar menerima format JPG, PNG, atau WebP dengan batas 4 MB.
5. Tekan **Simpan produk**. Katalog pada `index.html` akan mengikuti data terbaru dari server.
6. Di bagian **Pengaturan pembayaran**, isi nomor DANA, unggah gambar QRIS, dan/atau masukkan rekening bank serta e-wallet lain. Simpan pengaturan; hanya metode yang datanya cukup yang muncul saat checkout.

Daftar produk disimpan di `data/products.json`, rincian metode pembayaran di `data/payments.json`, gambar ada di `uploads/`, dan hash kata sandi disimpan di `data/admin.json`. Folder data diblokir dari akses web. Jangan hapus file-file ini. Nomor pembayaran yang diisi memang ditampilkan kepada pembeli; pastikan rekeningnya benar sebelum menyimpannya. Buat salinan cadangan berkala.

Panel dan katalog dinamis membutuhkan PHP melalui Apache; buka toko lewat `http://localhost/jb_akun_al_game/`, bukan dengan membuka file HTML langsung.

## Catatan sebelum dipublikasikan

Panel ini ditujukan untuk prototipe lokal XAMPP. Untuk server publik, gunakan HTTPS, perbarui PHP/Apache, batasi akses admin, dan siapkan backup. Checkout hanya menampilkan instruksi pembayaran sebagai preview: pesanan, verifikasi transfer, dan pembayaran otomatis belum terhubung. Integrasikan backend/payment gateway sebelum menerima transaksi sungguhan.
