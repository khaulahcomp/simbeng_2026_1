# Sistem Manajemen Bengkel Motor (PHP Native + MySQL)

Aplikasi web manajemen bengkel motor: dashboard, pelanggan & kendaraan, inventory sparepart (barang masuk/keluar, import Excel, scan barcode), kasir/POS dengan cetak struk, data supplier, klaim garansi, laporan/export, dan notifikasi WhatsApp ke pelanggan.

## Kebutuhan
- PHP 7.4+ (atau PHP 8.x) dengan ekstensi `pdo_mysql`
- MySQL / MariaDB (tersedia bawaan di XAMPP dan cPanel shared hosting)
- Tidak butuh Composer

## Konfigurasi Database (WAJIB)
Edit file **`includes/config.php`** sesuai data database Anda:
```php
'host' => 'localhost',            // umumnya 'localhost'
'name' => 'bengkel',              // nama database
'user' => 'root',                 // user database
'pass' => '',                     // password database
'auto_create_database' => true,   // XAMPP: true | cPanel: false
```
Tabel & akun admin default dibuat otomatis saat aplikasi pertama kali dibuka.

## Cara Menjalankan

### A. XAMPP (Windows/Mac/Linux)
1. Install XAMPP, jalankan **Apache** dan **MySQL** dari Control Panel.
2. Salin seluruh folder `bengkel/` ke `C:\xampp\htdocs\bengkel`.
3. Buka `includes/config.php`: host `localhost`, user `root`, pass kosong, name `bengkel`, `auto_create_database => true` (database dibuat otomatis). Atau buat manual di phpMyAdmin.
4. Buka browser: `http://localhost/bengkel/`

### B. cPanel / Shared Hosting
1. Buat database + user MySQL lewat menu **MySQL Databases** (catat nama db, user, password — biasanya berawalan nama akun, mis. `namauser_bengkel`). Beri user hak akses penuh ke database tersebut.
2. Upload seluruh isi folder `bengkel/` ke `public_html/` (atau subfolder, mis. `public_html/bengkel/`).
3. Edit `includes/config.php`: isi `name`, `user`, `pass` sesuai langkah 1, set `host => 'localhost'` dan **`auto_create_database => false`**.
4. Pastikan versi PHP di cPanel >= 7.4 dengan ekstensi `pdo_mysql` aktif (menu "Select PHP Version").
5. Akses `https://domainanda.com/` atau `https://domainanda.com/bengkel/` — tabel & admin dibuat otomatis saat halaman pertama dibuka.

### C. Memindahkan data lama dari SQLite (opsional)
Jika Anda punya file `bengkel.db` (SQLite) lama dan ingin memindahkan datanya ke MySQL:
```bash
php migrate_sqlite_to_mysql.php
```
Jalankan sekali setelah `config.php` diarahkan ke database MySQL tujuan.

## Login Default
- Username: `admin`
- Password: `admin123`

Segera ganti password melalui menu **Pengguna** setelah login pertama. Tabel database & akun admin dibuat otomatis saat aplikasi pertama kali diakses.


## Struktur Folder
```
bengkel/
├── index.php            # Router utama
├── bengkel.db           # Database SQLite (auto-create)
├── includes/
│   ├── db.php           # Koneksi + skema database + helper
│   ├── auth.php         # Manajemen sesi login
│   ├── header.php       # Layout + sidebar
│   └── footer.php
├── pages/               # Halaman: login, dashboard, customers, parts,
│                        # stock, pos, receipt, transactions, suppliers,
│                        # warranty, warranty_print, users
└── ajax/                # Endpoint JSON (lookup kendaraan, cari nota, import Excel)
```

## Fitur
- **Dashboard**: pendapatan hari ini, servis selesai, stok menipis, total pelanggan, klaim garansi aktif.
- **Pelanggan**: CRUD pelanggan + kendaraan (merek, model, plat) + riwayat servis per pelanggan.
- **Sparepart**: CRUD, low stock alert, import Excel/CSV (SheetJS), scan barcode via kamera HP / scanner USB.
- **Stok Masuk/Keluar**: pencatatan dari supplier, barang keluar manual, riwayat pergerakan stok.
- **Kasir/POS**: pilih pelanggan & kendaraan, item jasa + sparepart, stok berkurang otomatis, cetak struk nota.
- **Supplier**: CRUD data supplier.
- **Klaim Garansi**: kode otomatis (GRS-YYYYMM-NNN), pencarian nota, pengajuan klaim, update status (pending/diproses/disetujui/ditolak), penggantian part otomatis mengurangi stok, cetak bukti klaim.
- **Pengguna**: manajemen multi-user dengan role admin/kasir/mekanik (khusus admin).
