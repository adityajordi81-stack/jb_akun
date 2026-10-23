# JB_AKUN

Website statis HTML, CSS, dan JavaScript yang dapat di-host dengan GitHub Pages. Tidak perlu PHP, database, atau build step.

## Publikasikan dengan GitHub Pages

1. Buat repository di GitHub.
2. Unggah file `index.html`, `admin.html`, dan `catalog.json` ke root repository.
3. Buka **Settings → Pages**.
4. Pada **Build and deployment**, pilih **Deploy from a branch**, pilih branch `main` dan folder `/(root)`, kemudian simpan.
5. Buka URL Pages yang ditampilkan GitHub setelah deployment selesai.

Semua file website harus berada di root repository supaya `catalog.json` dapat dimuat dengan path relatif.

## Mengubah katalog

1. Buka URL website dan tambahkan `/admin.html` di belakangnya.
2. Edit produk, gambar, atau pengaturan pembayaran. Editor hanya bekerja di browser dan tidak mengubah repository secara langsung.
3. Tekan **Unduh catalog.json**.
4. Gantikan file `catalog.json` di repository dengan file yang diunduh, lalu commit/push perubahan.

Gambar produk/QRIS dapat dipilih dari komputer; editor menyimpan gambar sebagai data di dalam JSON (maksimum 500 KB per gambar). Alternatifnya, edit `catalog.json` dan gunakan URL gambar publik. Gambar, nomor DANA/rekening, dan rincian lain di katalog dapat dilihat semua pengunjung; jangan simpan kata sandi atau data pribadi yang tidak ingin dipublikasikan.

## Batasan keamanan dan pembayaran

GitHub Pages bersifat statis. Halaman `admin.html` bukan sistem admin privat: tidak memiliki login, menyimpan data di server, atau menerbitkan perubahan otomatis. Setiap orang dapat membuka editor; hanya pemilik repository yang dapat menerbitkan perubahan melalui GitHub.

Checkout dan instruksi pembayaran hanya demo. Transfer bank, QRIS, dan e-wallet belum menghasilkan pesanan, verifikasi pembayaran, atau pengiriman akun otomatis. Untuk transaksi nyata, gunakan backend/host yang mendukung server dan integrasi payment gateway. Jangan mengandalkan unggahan nomor pembayaran publik sebagai sistem checkout produksi.

## Lokal di XAMPP

Jalankan Apache, kemudian buka `http://localhost/jb_akun_al_game/`. Editor tersedia di `http://localhost/jb_akun_al_game/admin.html`. Alternatifnya, buka `catalog.json` dari editor dan unduh kembali hasil perubahan.
