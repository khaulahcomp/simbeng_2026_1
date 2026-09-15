<?php
// ============================================================
// db_admin.php - Restore & Format database (khusus admin).
//   - action=restore : impor berkas .sql (hasil Backup / phpMyAdmin)
//   - action=format  : kosongkan seluruh data untuk instalasi baru
//                      (akun pengguna & pengaturan dipertahankan)
// Murni PHP (tanpa mysqldump/exec) agar tetap jalan di cPanel.
// ============================================================
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_login();
require_admin();
csrf_verify_post();
init_db();

$db = db();
$action = $_POST['action'] ?? '';

// Pisah skrip SQL menjadi statement, aman terhadap tanda kutip/escape.
function split_sql_statements(string $sql): array {
    // Buang baris komentar (-- ...)
    $lines = preg_split('/\r?\n/', $sql);
    $kept = [];
    foreach ($lines as $ln) {
        if (strpos(ltrim($ln), '--') === 0) continue;
        $kept[] = $ln;
    }
    $sql = implode("\n", $kept);

    $stmts = []; $buf = ''; $inStr = false; $q = ''; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inStr) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $sql[$i + 1]; $i++; continue; }
            if ($ch === $q) $inStr = false;
        } else {
            if ($ch === "'" || $ch === '"' || $ch === '`') { $inStr = true; $q = $ch; $buf .= $ch; }
            elseif ($ch === ';') { $s = trim($buf); if ($s !== '') $stmts[] = $s; $buf = ''; }
            else $buf .= $ch;
        }
    }
    $s = trim($buf); if ($s !== '') $stmts[] = $s;
    return $stmts;
}

// ---------------- RESTORE ----------------
if ($action === 'restore') {
    $err = $_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        set_flash('danger', $err === UPLOAD_ERR_NO_FILE ? 'Pilih berkas .sql terlebih dahulu.' : 'Upload berkas gagal (mungkin melebihi batas ukuran).');
        header('Location: index.php?page=settings'); exit;
    }
    $name = $_FILES['sqlfile']['name'] ?? '';
    if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
        set_flash('danger', 'Berkas harus berformat .sql.');
        header('Location: index.php?page=settings'); exit;
    }
    $sql = file_get_contents($_FILES['sqlfile']['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        set_flash('danger', 'Berkas .sql kosong atau tidak terbaca.');
        header('Location: index.php?page=settings'); exit;
    }

    $stmts = split_sql_statements($sql);
    $ok = 0; $fail = 0; $firstErr = '';
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($stmts as $st) {
            if (stripos($st, 'SET FOREIGN_KEY_CHECKS') === 0) continue; // dikelola manual
            try { $db->exec($st); $ok++; }
            catch (PDOException $e) { $fail++; if ($firstErr === '') $firstErr = $e->getMessage(); }
        }
    } finally {
        try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (PDOException $e) {}
    }
    init_db(); // pastikan skema terbaru (tabel/kolom baru) tetap ada

    if ($fail === 0) set_flash('success', "Restore berhasil: $ok perintah dijalankan.");
    else set_flash('danger', "Restore selesai dengan $fail perintah gagal (berhasil $ok). Contoh error: " . htmlspecialchars($firstErr));
    header('Location: index.php?page=settings'); exit;
}

// ---------------- FORMAT (kosongkan data) ----------------
if ($action === 'format') {
    if (($_POST['confirm'] ?? '') !== 'FORMAT') {
        set_flash('danger', 'Konfirmasi tidak sesuai. Ketik FORMAT untuk mengosongkan database.');
        header('Location: index.php?page=settings'); exit;
    }
    // Tabel yang DIPERTAHANKAN agar admin tetap bisa login & konfigurasi tetap ada
    $keep = ['users', 'settings'];
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $cleared = 0;
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            if (in_array($t, $keep, true)) continue;
            try { $db->exec("TRUNCATE TABLE `$t`"); $cleared++; } catch (PDOException $e) {}
        }
    } finally {
        try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (PDOException $e) {}
    }
    // Izinkan seeding data contoh lagi setelah format
    set_setting('dummy_seeded', '');
    // Bersihkan info sinkronisasi katalog terakhir
    set_setting('last_hsc_sync', '');
    set_setting('last_hsc_sync_summary', '');

    init_db(); // jaga-jaga bila ada tabel yang perlu dibuat ulang
    set_flash('success', "Database berhasil diformat. $cleared tabel dikosongkan. Akun & pengaturan dipertahankan. Siap untuk instalasi baru.");
    header('Location: index.php?page=settings'); exit;
}

set_flash('danger', 'Aksi tidak dikenal.');
header('Location: index.php?page=settings'); exit;
