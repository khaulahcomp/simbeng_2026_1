<?php
// ============================================================
// backup.php - Unduh cadangan database dalam format .sql (khusus admin).
// Dump dibuat murni dengan PHP (tanpa mysqldump/exec) agar tetap jalan di
// cPanel shared hosting. Hasil dapat di-import kembali via phpMyAdmin.
// ============================================================
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_admin();
init_db();

$db    = db();
$fname = 'bengkel_backup_' . date('Ymd_His') . '.sql';

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo "-- ============================================================\n";
echo "-- Backup database SIMBENG (Sistem Manajemen Bengkel Motor)\n";
echo "-- Dibuat pada: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n";
echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    // Struktur tabel
    $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $ddl = $create['Create Table'] ?? '';
    if ($ddl === '') continue; // lewati view/objek lain

    echo "-- ------------------------------------------------------------\n";
    echo "-- Struktur tabel `$table`\n";
    echo "-- ------------------------------------------------------------\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    echo $ddl . ";\n\n";

    // Data tabel
    $stmt = $db->query("SELECT * FROM `$table`");
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols = '`' . implode('`,`', array_keys($row)) . '`';
        $vals = array_map(function ($v) use ($db) {
            return $v === null ? 'NULL' : $db->quote($v);
        }, array_values($row));
        echo "INSERT INTO `$table` ($cols) VALUES (" . implode(',', $vals) . ");\n";
        $count++;
    }
    echo "-- ($count baris)\n\n";
    // Kirim ke browser secara bertahap untuk tabel besar
    if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_flush(); }
    @flush();
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- Selesai.\n";
exit;
