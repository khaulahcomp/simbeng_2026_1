<?php
// ============================================================
// scripts/sync_hsc_cli.php - Menyalakan sinkronisasi katalog HSC dari CLI.
// Dipanggil oleh supervisor `hsc_sync_daemon` sekali per 24 jam.
//   php /app/bengkel/scripts/sync_hsc_cli.php
// ============================================================
require_once __DIR__ . '/../includes/sync_hsc.php';

$log = function ($msg) { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL); };
try {
    $log('== HSC Sync CLI ==');
    $res = hsc_sync_run($log);
    $log('OK: ' . json_encode($res));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
