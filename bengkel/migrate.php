<?php
// ============================================================
// migrate.php - Runner instalasi/migrasi & maintenance database.
// Menjalankan run_full_migration() (CREATE TABLE, kolom, index,
// seed) secara MANUAL - bukan pada request pengguna biasa.
//
// Akses:
//   1) CLI (aman, direkomendasikan):  php migrate.php
//   2) Web (khusus admin login):      /migrate.php?run=1  (POST/GET admin)
// Setelah migrasi, gerbang versi skema di init_db() akan melewati
// pemeriksaan berulang pada request normal.
// ============================================================
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema.php';

if (PHP_SAPI === 'cli') {
    run_full_migration();
    set_setting('schema_version', APP_SCHEMA_VERSION);
    fwrite(STDOUT, "Migrasi selesai. schema_version = " . APP_SCHEMA_VERSION . "\n");
    exit(0);
}

// --- Akses web: wajib admin login ---
require_once __DIR__ . '/includes/auth.php';
require_login();
require_admin();

run_full_migration();
set_setting('schema_version', APP_SCHEMA_VERSION);
set_flash('success', 'Migrasi/maintenance database selesai. Versi skema: ' . APP_SCHEMA_VERSION);
header('Location: index.php?page=settings');
exit;
