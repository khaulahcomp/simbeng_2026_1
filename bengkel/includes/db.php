<?php
// ============================================================
// db.php - Koneksi MySQL & bootstrap aplikasi bengkel.
// Kredensial database diatur di includes/config.php (mudah diedit
// untuk cPanel shared hosting / XAMPP).
//
// PENTING (optimasi): pembuatan skema/migrasi dipindah ke
// includes/schema.php (run_full_migration) dan TIDAK dijalankan
// penuh pada setiap request. init_db() kini hanya gerbang versi
// skema yang sangat ringan.
// ============================================================

require_once __DIR__ . '/security.php';
harden_session();

// Polyfill minimal: shared hosting tanpa ekstensi mbstring tetap berjalan
// (pencarian sparepart & katalog online memakai mb_strlen).
if (!function_exists('mb_strlen')) { function mb_strlen($s, $enc = null): int { return strlen((string)$s); } }

// Zona waktu aplikasi (WIB) untuk seluruh fungsi date() PHP
date_default_timezone_set('Asia/Jakarta');

// Versi skema aplikasi. Naikkan nilai ini bila ada perubahan
// struktur/index agar migrasi dijalankan ulang SEKALI saja
// (bukan pada tiap request pengguna).
const APP_SCHEMA_VERSION = '2026.06.20.2';

function db_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = db_config();
        $charset = $c['charset'] ?? 'utf8mb4';

        // Coba buat database otomatis bila diizinkan (berguna di XAMPP).
        // Di shared hosting yang user-nya tidak punya izin CREATE DATABASE,
        // error diabaikan diam-diam (database dibuat manual via cPanel).
        if (!empty($c['auto_create_database'])) {
            try {
                $tmp = new PDO(
                    "mysql:host={$c['host']};port={$c['port']};charset=$charset",
                    $c['user'], $c['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
                $tmp = null;
            } catch (PDOException $e) {
                // abaikan: kemungkinan database sudah ada / tanpa izin CREATE
            }
        }

        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=$charset";
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // Jangan bocorkan kredensial/host ke layar. Catat ke log, tampilkan pesan generik.
            if (function_exists('app_log')) app_log('error', 'DB connect gagal: ' . $e->getMessage());
            http_response_code(500);
            if (PHP_SAPI !== 'cli') {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:520px;margin:60px auto;text-align:center">'
                   . '<h2>Database tidak dapat diakses</h2><p>Silakan coba lagi beberapa saat. Jika berlanjut, hubungi administrator.</p></div>';
            } else {
                fwrite(STDERR, "DB connect gagal.\n");
            }
            exit;
        }
        // Simpan seluruh timestamp dalam UTC agar konsisten dengan helper lokal().
        try { $pdo->exec("SET time_zone = '+00:00'"); } catch (PDOException $e) { /* abaikan */ }
    }
    return $pdo;
}

// ------------------------------------------------------------
// init_db() - Gerbang versi skema RINGAN (bukan migrasi penuh).
// Pada runtime normal hanya melakukan 1 SELECT kecil ke tabel
// settings (kunci primer). Migrasi berat (CREATE/ALTER/seed +
// index) hanya berjalan bila versi belum sesuai / DB masih baru.
// ------------------------------------------------------------
function init_db(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $v = db()->query("SELECT `value` FROM settings WHERE `key`='schema_version'")->fetchColumn();
        if ($v === APP_SCHEMA_VERSION) return; // skema mutakhir -> tidak ada kerja tambahan
    } catch (\Throwable $e) {
        // Tabel settings belum ada (DB baru) -> lanjut migrasi penuh.
    }

    // Perlu instalasi/migrasi.
    require_once __DIR__ . '/schema.php';
    run_full_migration();
    try { set_setting('schema_version', APP_SCHEMA_VERSION); } catch (\Throwable $e) {}
}

// ---------- Helper umum ----------
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function set_flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function get_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ---------- Pengaturan aplikasi ----------
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT `key`, `value` FROM settings") as $r) $cache[$r['key']] = $r['value'];
        } catch (\Throwable $e) { /* tabel belum ada saat instalasi awal */ }
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    db()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([$key, $value]);
}

// ---------- Menu navigasi (urutan dapat diatur di Pengaturan) ----------
// Daftar dasar menu: [page, href, icon, label, admin_only]
function bengkel_nav_items(): array {
    return [
        ['dashboard', 'index.php', 'bi-speedometer2', 'Dashboard', false],
        ['pos', 'index.php?page=pos', 'bi-cash-register', 'Kasir / Servis', false],
        ['transactions', 'index.php?page=transactions', 'bi-receipt', 'Riwayat Transaksi', false],
        ['reports', 'index.php?page=reports', 'bi-file-earmark-bar-graph', 'Rekap & Laporan', false],
        ['finance', 'index.php?page=finance', 'bi-wallet2', 'Keuangan', false],
        ['debts', 'index.php?page=debts', 'bi-cash-coin', 'Hutang Piutang', false],
        ['charts', 'index.php?page=charts', 'bi-bar-chart', 'Grafik Pelanggan', false],
        ['customers', 'index.php?page=customers', 'bi-people', 'Pelanggan', false],
        ['parts', 'index.php?page=parts', 'bi-box-seam', 'Sparepart', false],
        ['parts_bulk', 'index.php?page=parts_bulk', 'bi-pencil-square', 'Lengkapi Harga', false],
        ['categories', 'index.php?page=categories', 'bi-tags', 'Kategori', false],
        ['stock', 'index.php?page=stock', 'bi-arrow-left-right', 'Stok Masuk/Keluar', false],
        ['suppliers', 'index.php?page=suppliers', 'bi-truck', 'Supplier', false],
        ['warranty', 'index.php?page=warranty', 'bi-shield-check', 'Klaim Garansi', false],
        ['notes', 'index.php?page=notes', 'bi-sticky', 'Catatan', false],
        ['users', 'index.php?page=users', 'bi-person-gear', 'Pengguna', true],
        ['settings', 'index.php?page=settings', 'bi-sliders', 'Pengaturan', true],
    ];
}

// Daftar menu sesuai urutan tersimpan (setting 'menu_order').
// Menu baru yang belum ada di urutan tersimpan otomatis ditambahkan di akhir.
function bengkel_nav_items_ordered(): array {
    $items = bengkel_nav_items();
    $byKey = [];
    foreach ($items as $it) $byKey[$it[0]] = $it;
    $order = array_filter(array_map('trim', explode(',', setting('menu_order', ''))));
    if (!$order) return $items;
    $ordered = [];
    foreach ($order as $k) {
        if (isset($byKey[$k])) { $ordered[] = $byKey[$k]; unset($byKey[$k]); }
    }
    foreach ($items as $it) { // sisa menu (baru) mengikuti urutan default
        if (isset($byKey[$it[0]])) $ordered[] = $it;
    }
    return $ordered;
}

// Konversi datetime tersimpan (UTC) ke WIB untuk tampilan & cetakan
function lokal(?string $dt, string $format = 'd/m/Y H:i'): string {
    if (!$dt) return '-';
    try {
        $d = new DateTime($dt, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $d->format($format);
    } catch (Exception $e) {
        return $dt;
    }
}

// ------------------------------------------------------------
// wib_range_utc() - Ubah rentang tanggal WIB (Y-m-d inklusif)
// menjadi batas UTC [mulai, akhir_eksklusif] agar query bisa
// memakai index pada kolom created_at:
//   WHERE created_at >= :start AND created_at < :end
// (menggantikan pola DATE(created_at + INTERVAL 7 HOUR) yang
//  membuat index tidak terpakai / full table scan).
// ------------------------------------------------------------
function wib_range_utc(string $dari, string $sampai): array {
    $tz  = new DateTimeZone('Asia/Jakarta');
    $utc = new DateTimeZone('UTC');
    $start = new DateTime($dari . ' 00:00:00', $tz);
    $end   = new DateTime($sampai . ' 00:00:00', $tz);
    $end->modify('+1 day'); // akhir eksklusif = hari berikutnya 00:00 WIB
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

// Rentang UTC untuk "hari ini" menurut WIB.
function wib_today_utc(): array {
    $today = date('Y-m-d');
    return wib_range_utc($today, $today);
}

// Generator kode berurut per bulan, misal: TRX-202606-001 / GRS-202606-001
// Memakai MAX nomor urut (bukan COUNT) agar aman terhadap penghapusan baris.
function next_kode(string $prefix, string $table, string $col): string {
    $ym = date('Ym');
    $start = strlen($prefix) + 9; // posisi 1-based digit pertama nomor urut
    $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING($col, $start) AS UNSIGNED)) FROM $table WHERE $col LIKE ?");
    $stmt->execute(["$prefix-$ym-%"]);
    $next = ((int)$stmt->fetchColumn()) + 1;
    return sprintf('%s-%s-%03d', $prefix, $ym, $next);
}

// ============================================================
// Helper laporan: hitung rentang tanggal dari parameter periode
// (harian / mingguan / bulanan / tahunan / custom dari-sampai)
// ============================================================
function _valid_date($d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function resolve_periode(): array {
    $periode = $_GET['periode'] ?? 'harian';
    $today = date('Y-m-d');
    switch ($periode) {
        case 'mingguan':
            $base = _valid_date($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : $today;
            $dari = date('Y-m-d', strtotime('monday this week', strtotime($base)));
            $sampai = date('Y-m-d', strtotime('sunday this week', strtotime($base)));
            $label = 'Mingguan (' . date('d/m/Y', strtotime($dari)) . ' - ' . date('d/m/Y', strtotime($sampai)) . ')';
            break;
        case 'bulanan':
            $bulan = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? '') ? $_GET['bulan'] : date('Y-m');
            $dari = $bulan . '-01';
            $sampai = date('Y-m-t', strtotime($dari));
            $label = 'Bulanan (' . date('m/Y', strtotime($dari)) . ')';
            break;
        case 'tahunan':
            $tahun = preg_match('/^\d{4}$/', $_GET['tahun'] ?? '') ? $_GET['tahun'] : date('Y');
            $dari = "$tahun-01-01";
            $sampai = "$tahun-12-31";
            $label = "Tahunan ($tahun)";
            break;
        case 'custom':
            $dari = _valid_date($_GET['dari'] ?? '') ? $_GET['dari'] : $today;
            $sampai = _valid_date($_GET['sampai'] ?? '') ? $_GET['sampai'] : $today;
            if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
            $label = date('d/m/Y', strtotime($dari)) . ' s.d. ' . date('d/m/Y', strtotime($sampai));
            break;
        default: // harian
            $periode = 'harian';
            $dari = $sampai = _valid_date($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : $today;
            $label = 'Harian (' . date('d/m/Y', strtotime($dari)) . ')';
    }
    return [$periode, $dari, $sampai, $label];
}

// Ambil daftar transaksi dalam rentang tanggal untuk laporan.
// Memakai rentang created_at (UTC) agar index idx_trx_created_at terpakai.
function laporan_transaksi(string $dari, string $sampai): array {
    [$su, $eu] = wib_range_utc($dari, $sampai);
    $stmt = db()->prepare("SELECT t.*, c.nama AS customer_nama, v.plat_nomor
        FROM transactions t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN vehicles v ON v.id = t.vehicle_id
        WHERE t.created_at >= ? AND t.created_at < ?
        ORDER BY t.created_at");
    $stmt->execute([$su, $eu]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
