<?php
// ============================================================
// security.php - Hardening runtime: session, error handling,
// security headers, dan logger ringan. Dibuat agar kompatibel
// dengan shared hosting cPanel (tanpa ekstensi khusus).
// ============================================================

// ---- Error handling produksi: jangan tampilkan detail ke user ----
// Detail tetap dicatat ke log aplikasi, bukan ke layar.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

function app_log_dir(): string {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

// Logger ringan. Kategori mis: error, security, api, slow, auth.
// Tidak pernah menyimpan password/token mentah.
function app_log(string $category, string $message): void {
    try {
        $dir = app_log_dir();
        $file = $dir . '/app.log';
        // Batasi ukuran file agar tidak memenuhi disk shared hosting (~2MB, rotasi 1x).
        if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
            @rename($file, $dir . '/app.log.1');
        }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $category . '] ' . str_replace(["\n", "\r"], ' ', $message) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) { /* logging tidak boleh mematikan aplikasi */ }
}

// Arahkan error_log PHP ke file aplikasi.
@ini_set('error_log', app_log_dir() . '/php_error.log');

// Handler exception global -> pesan generik ke user, detail ke log.
set_exception_handler(function ($e) {
    app_log('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:520px;margin:60px auto;text-align:center">'
       . '<h2>Terjadi kesalahan</h2><p>Maaf, terjadi gangguan saat memproses permintaan Anda. '
       . 'Silakan coba lagi beberapa saat.</p><p><a href="index.php">Kembali ke Beranda</a></p></div>';
});

// ---- Deteksi HTTPS (termasuk di belakang proxy/CDN) ----
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') == '443') return true;
    $xf = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (strtolower($xf) === 'https') return true;
    return false;
}

// ---- Session aman: HttpOnly + SameSite + Secure (bila HTTPS) ----
function harden_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return; // sudah start, jangan ubah
    $secure = is_https();
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
    } else {
        @ini_set('session.cookie_secure', $secure ? '1' : '0');
    }
}

// ---- Security headers (aman untuk shared hosting) ----
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    // CSP permisif: mengizinkan CDN & inline yang sudah dipakai aplikasi
    // (Bootstrap/jsDelivr, Chart.js, html5-qrcode) agar tidak merusak fitur.
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "font-src 'self' https://cdn.jsdelivr.net data:; "
         . "img-src 'self' data: blob:; "
         . "connect-src 'self'; "
         . "media-src 'self' blob:; "
         . "frame-ancestors 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self' https://wa.me";
    header('Content-Security-Policy: ' . $csp);
    if (is_https()) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}
