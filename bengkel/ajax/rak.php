<?php
// ============================================================
// rak.php - Endpoint JSON untuk fitur "Multi Rak".
// ?action=list            -> {part_id}               daftar rak sebuah part
// ?action=precheck_keluar -> {part_id, jumlah}        cek perlu transfer otomatis?
// POST action=toggle       {part_id, aktif, nama_utama}
// POST action=add_rak      {part_id, nama_rak, jumlah}   (stok BARU, tidak mengurangi Rak Utama)
// POST action=transfer     {part_id, rak_asal, rak_tujuan, rak_tujuan_baru, jumlah}
// POST action=delete_rak   {part_id, nama_rak}
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
init_db();
header('Content-Type: application/json');

$db = db();
$uid = (int)(current_user()['id'] ?? 0);
$is_admin = ((current_user()['role'] ?? '') === 'admin');
$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

function rak_json_ok($data = []) { echo json_encode(['ok' => true] + $data); exit; }
function rak_json_err(string $msg) { echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if ($action === 'list') {
    $part_id = (int)($_GET['part_id'] ?? 0);
    $p = $db->prepare("SELECT id, kode, nama, stok, lokasi_rak, multi_rak_aktif FROM parts WHERE id=?");
    $p->execute([$part_id]);
    $part = $p->fetch(PDO::FETCH_ASSOC);
    if (!$part) rak_json_err('Sparepart tidak ditemukan.');
    $rows = rack_list($db, $part_id);
    rak_json_ok(['part' => $part, 'racks' => $rows]);
}

if ($action === 'precheck_keluar') {
    $part_id = (int)($_GET['part_id'] ?? 0);
    $jumlah = max(0, (int)($_GET['jumlah'] ?? 0));
    rak_json_ok(rack_precheck_keluar($db, $part_id, $jumlah));
}

// ---- Aksi berikut hanya via POST ----
if ($method !== 'POST') rak_json_err('Metode tidak diizinkan.');

if ($action === 'toggle') {
    if (!$is_admin) rak_json_err('Akses ditolak. Hanya admin yang boleh mengubah pengaturan Multi Rak.');
    $part_id = (int)($_POST['part_id'] ?? 0);
    $aktif = !empty($_POST['aktif']) && $_POST['aktif'] !== '0';
    $nama_utama = trim($_POST['nama_utama'] ?? '');
    $p = $db->prepare("SELECT id FROM parts WHERE id=?"); $p->execute([$part_id]);
    if (!$p->fetchColumn()) rak_json_err('Sparepart tidak ditemukan.');
    try {
        if ($aktif) rack_activate($db, $part_id, $nama_utama);
        else rack_deactivate($db, $part_id);
    } catch (Throwable $e) {
        rak_json_err('Gagal mengubah status Multi Rak: ' . $e->getMessage());
    }
    rak_json_ok(['racks' => rack_list($db, $part_id), 'multi_rak_aktif' => $aktif]);
}

if ($action === 'add_rak') {
    $part_id = (int)($_POST['part_id'] ?? 0);
    $nama_rak = trim($_POST['nama_rak'] ?? '');
    $jumlah = max(1, (int)($_POST['jumlah'] ?? 0));
    if ($nama_rak === RAK_UTAMA_KEY) rak_json_err('Nama rak tidak valid.');
    try {
        $db->beginTransaction();
        // Input stok independen: TIDAK mengurangi Rak Utama, boleh berapa pun
        // (mis. gudang menampung lebih banyak dari etalase/Rak Utama).
        rack_add_stock_to_rak($db, $part_id, $nama_rak, $jumlah, $uid, 'Ditambahkan via form Tambah Rak');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        rak_json_err($e->getMessage());
    }
    rak_json_ok(['racks' => rack_list($db, $part_id)]);
}

if ($action === 'transfer') {
    $part_id = (int)($_POST['part_id'] ?? 0);
    $rak_asal = trim($_POST['rak_asal'] ?? '');
    $rak_tujuan = trim($_POST['rak_tujuan'] ?? '');
    if ($rak_tujuan === '__BARU__') $rak_tujuan = trim($_POST['rak_tujuan_baru'] ?? '');
    $jumlah = max(1, (int)($_POST['jumlah'] ?? 0));
    try {
        $db->beginTransaction();
        rack_transfer_manual($db, $part_id, $rak_asal, $rak_tujuan, $jumlah, $uid, 'Transfer Rak manual');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        rak_json_err($e->getMessage());
    }
    rak_json_ok(['racks' => rack_list($db, $part_id)]);
}

if ($action === 'delete_rak') {
    if (!$is_admin) rak_json_err('Akses ditolak. Hanya admin yang boleh menghapus rak.');
    $part_id = (int)($_POST['part_id'] ?? 0);
    $nama_rak = trim($_POST['nama_rak'] ?? '');
    try {
        $db->beginTransaction();
        rack_delete($db, $part_id, $nama_rak, $uid);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        rak_json_err($e->getMessage());
    }
    rak_json_ok(['racks' => rack_list($db, $part_id)]);
}

rak_json_err('Aksi tidak dikenal.');
