<?php
// ============================================================
// csrf.php - Proteksi CSRF ringan berbasis token per-sesi.
// Token disuntikkan otomatis ke seluruh form POST via footer.php
// (JS) + form login (manual), dan diverifikasi di index.php.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Verifikasi token untuk request POST. Bila gagal -> tolak (aman).
function csrf_valid(): bool {
    $t = $_POST['csrf_token'] ?? '';
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $t);
}

// Panggil di controller untuk menolak POST tanpa token valid.
function csrf_verify_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!csrf_valid()) {
        if (function_exists('app_log')) app_log('security', 'CSRF token invalid/missing on ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:520px;margin:60px auto;text-align:center">'
           . '<h2>Sesi kedaluwarsa</h2><p>Permintaan tidak dapat diproses karena token keamanan tidak valid. '
           . 'Silakan muat ulang halaman dan coba lagi.</p><p><a href="index.php">Kembali</a></p></div>';
        exit;
    }
}
