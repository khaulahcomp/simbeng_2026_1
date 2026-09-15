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
