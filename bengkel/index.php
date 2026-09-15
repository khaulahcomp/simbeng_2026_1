<?php
// ============================================================
// index.php - Front controller (router) aplikasi bengkel motor
// ============================================================
// Buffer output agar redirect header('Location: ...') pada handler POST
// selalu berhasil (PRG) meski layout sudah mulai dirender
ob_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

init_db();
send_security_headers();

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'logout') logout();

if ($page === 'login') {
    if (is_logged_in()) { header('Location: index.php'); exit; }
    require __DIR__ . '/pages/login.php';
    exit;
}

require_login();

// Proteksi CSRF: tolak seluruh POST tanpa token valid pada halaman terautentikasi.
// (Halaman login diproses terpisah di pages/login.php.)
csrf_verify_post();

// Daftar halaman yang diizinkan beserta judulnya
$routes = [
    'dashboard'      => 'Dashboard',
    'customers'      => 'Manajemen Pelanggan',
    'suppliers'      => 'Data Supplier',
    'parts'          => 'Inventory Sparepart',
    'parts_bulk'     => 'Lengkapi Harga & Stok Sparepart',
    'categories'     => 'Kategori Sparepart',
    'stock'          => 'Barang Masuk & Keluar',
    'pos'            => 'Kasir / Transaksi Servis',
    'transactions'   => 'Riwayat Transaksi',
    'reports'        => 'Rekap & Laporan',
    'finance'        => 'Keuangan',
    'debts'          => 'Hutang Piutang',
    'charts'         => 'Grafik Pelanggan',
    'receipt'        => 'Struk Nota',
    'warranty'       => 'Klaim Garansi',
    'warranty_print' => 'Bukti Klaim Garansi',
    'debt_receipt'   => 'Kwitansi Pembayaran',
    'users'          => 'Manajemen Pengguna',
    'settings'       => 'Pengaturan',
    'notes'          => 'Catatan',
];
if (!isset($routes[$page])) $page = 'dashboard';

// Halaman cetak tampil tanpa sidebar (bare)
$bare = in_array($page, ['receipt', 'warranty_print', 'debt_receipt'], true);

$title = $routes[$page];
if (!$bare) require __DIR__ . '/includes/header.php';
require __DIR__ . '/pages/' . $page . '.php';
if (!$bare) require __DIR__ . '/includes/footer.php';
