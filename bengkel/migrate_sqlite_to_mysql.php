<?php
// ============================================================
// migrate_sqlite_to_mysql.php
// Skrip sekali-jalan untuk memindahkan data dari bengkel.db (SQLite lama)
// ke database MySQL yang dikonfigurasi di includes/config.php.
//
// Cara pakai (CLI):  php migrate_sqlite_to_mysql.php
// Data lama di MySQL untuk tabel-tabel berikut akan DITIMPA (TRUNCATE) lalu
// diisi ulang dari SQLite. Aman dijalankan berkali-kali.
// ============================================================

// KEAMANAN: skrip ini DESTRUKTIF (TRUNCATE + reimport). Hanya boleh
// dijalankan lewat command line (CLI), tidak pernah via web publik.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Skrip migrasi hanya dapat dijalankan melalui CLI (command line).');
}

require __DIR__ . '/includes/db.php';

$sqlitePath = __DIR__ . '/bengkel.db';
if (!file_exists($sqlitePath)) {
    fwrite(STDERR, "File SQLite tidak ditemukan: $sqlitePath\n");
    exit(1);
}

$src = new PDO('sqlite:' . $sqlitePath);
$src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dst = db();      // memicu koneksi MySQL
init_db();        // pastikan skema MySQL sudah dibuat

$tables = [
    'users', 'customers', 'vehicles', 'suppliers', 'parts',
    'stock_movements', 'transactions', 'transaction_items',
    'warranty_claims', 'categories', 'settings', 'notes',
];

// Daftar tabel yang benar-benar ada di SQLite
$existing = $src->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

$dst->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    if (!in_array($t, $existing, true)) { echo "$t: (lewati, tidak ada di SQLite)\n"; continue; }
    $dst->exec("TRUNCATE TABLE `$t`");
    $rows = $src->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $row) {
        $cols = array_keys($row);
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $dst->prepare("INSERT INTO `$t` ($colList) VALUES ($ph)")->execute(array_values($row));
        $n++;
    }
    echo "$t: $n baris dipindahkan\n";
}
$dst->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Selesai. Data SQLite berhasil dipindahkan ke MySQL.\n";
