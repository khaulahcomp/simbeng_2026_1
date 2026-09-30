<?php
// ============================================================
// auth.php - Manajemen sesi login
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

function is_logged_in(): bool { return !empty($_SESSION['user']); }
function current_user() { return $_SESSION['user'] ?? null; }

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_admin(): void {
    if ((current_user()['role'] ?? '') !== 'admin') {
        set_flash('danger', 'Akses ditolak. Halaman ini khusus admin.');
        header('Location: index.php');
        exit;
    }
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

// ============================================================
// Rate limiting login: kunci sementara 15 menit setelah 5x gagal
// berturut-turut PER AKUN (username), independen antar akun/role.
// Sengaja TIDAK dikunci per-IP: satu perangkat/warnet dipakai
// bergantian oleh admin, kasir, & mekanik (role berbeda-beda), jadi
// kalau akun admin kena limit, akun kasir/mekanik di perangkat yang
// sama tetap bisa dipakai login sbg jalur alternatif. Disimpan di
// tabel login_attempts (lihat includes/schema.php), bukan session,
// agar tetap berlaku meski penyerang berganti-ganti sesi/browser.
// ============================================================
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

// Daftar (type, value) yang dipantau untuk satu percobaan login.
// Normalnya HANYA per-username (lowercase, agar tak dihindari dgn
// ubah huruf besar/kecil). IP dipakai sbg fallback HANYA saat
// username kosong (mis. POST dipaksa tanpa field username), supaya
// percobaan semacam itu tetap dibatasi, bukan lolos tanpa batas.
function login_rl_keys(string $ip, string $username): array {
    $u = mb_strtolower(trim($username));
    if ($u !== '') return [['user', $u]];
    if ($ip !== '') return [['ip', $ip]];
    return [];
}

function login_rl_row(PDO $db, string $type, string $value): ?array {
    $st = $db->prepare("SELECT * FROM login_attempts WHERE identifier_type=? AND identifier_value=?");
    $st->execute([$type, $value]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// Detik tersisa sebelum kunci lepas (0 = tidak sedang terkunci).
// Dicek SEBELUM password diverifikasi, agar percobaan yg sudah
// terkunci tidak perlu query users/password_verify sama sekali.
function login_lock_seconds_left(string $ip, string $username): int {
    $db = db();
    $max = 0;
    foreach (login_rl_keys($ip, $username) as [$type, $val]) {
        $r = login_rl_row($db, $type, $val);
        if ($r && $r['locked_until']) {
            $left = strtotime($r['locked_until']) - time();
            if ($left > $max) $max = $left;
        }
    }
    return max(0, $max);
}

// Catat 1 kali percobaan GAGAL untuk akun (atau fallback IP bila
// username kosong). Mengembalikan jumlah kegagalan setelah dicatat,
// agar pemanggil bisa menampilkan sisa percobaan / status terkunci.
function login_register_fail(string $ip, string $username): int {
    $db = db();
    $maxFail = 0;
    foreach (login_rl_keys($ip, $username) as [$type, $val]) {
        $r = login_rl_row($db, $type, $val);
        if (!$r) {
            $db->prepare("INSERT INTO login_attempts (identifier_type, identifier_value, fail_count, last_fail_at) VALUES (?,?,1,NOW())")
               ->execute([$type, $val]);
            $fail = 1;
        } else {
            // Kunci sebelumnya sudah kedaluwarsa -> mulai hitungan baru dari 1.
            $expired = $r['locked_until'] && strtotime($r['locked_until']) <= time();
            $fail = $expired ? 1 : (int)$r['fail_count'] + 1;
            $locked_until = $r['locked_until'];
            if ($fail >= LOGIN_MAX_ATTEMPTS) {
                $locked_until = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            } elseif ($expired) {
                $locked_until = null;
            }
            $db->prepare("UPDATE login_attempts SET fail_count=?, last_fail_at=NOW(), locked_until=? WHERE id=?")
               ->execute([$fail, $locked_until, $r['id']]);
        }
        if ($fail > $maxFail) $maxFail = $fail;
    }
    return $maxFail;
}

// Reset hitungan setelah login BERHASIL (utk IP & username tsb).
function login_register_success(string $ip, string $username): void {
    $db = db();
    foreach (login_rl_keys($ip, $username) as [$type, $val]) {
        $db->prepare("DELETE FROM login_attempts WHERE identifier_type=? AND identifier_value=?")->execute([$type, $val]);
    }
}
