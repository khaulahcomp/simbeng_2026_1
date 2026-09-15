<?php
// ============================================================
// ajax/sync_hsc.php - Tombol "Sync Sekarang" pada menu Sparepart.
// GET  -> kembalikan info sinkronisasi terakhir.
// POST -> jalankan sinkronisasi (butuh login).
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sync_hsc.php';
require_login();
init_db();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = hsc_sync_run();
    echo json_encode([
        'ok' => true,
        'last_sync' => setting('last_hsc_sync', ''),
        'result' => $result,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'last_sync' => setting('last_hsc_sync', ''),
    'summary' => json_decode(setting('last_hsc_sync_summary', '{}'), true),
    'catalog_count' => (int)db()->query("SELECT COUNT(*) FROM part_catalog")->fetchColumn(),
]);
