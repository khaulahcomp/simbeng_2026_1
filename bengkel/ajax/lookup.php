<?php
// ============================================================
// lookup.php - Endpoint JSON untuk kebutuhan AJAX halaman
// ?action=vehicles&customer_id=..   -> kendaraan milik pelanggan
// ?action=search_trx&q=..           -> cari nota (garansi)
// ?action=search_parts&q=..         -> cari sparepart (kode/nama) utk autocomplete stok
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
init_db();
header('Content-Type: application/json');

$db = db();
$action = $_GET['action'] ?? '';

if ($action === 'vehicles') {
    $stmt = $db->prepare("SELECT id, merek, model, plat_nomor FROM vehicles WHERE customer_id=? ORDER BY id DESC");
    $stmt->execute([(int)($_GET['customer_id'] ?? 0)]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Cari sparepart berdasarkan kode / barcode / nama untuk autocomplete
// (POS Kasir & halaman Stok). Skalabel untuk ratusan ribu record:
//  - kode & barcode: pencarian prefix (memakai index unik/idx_parts_barcode)
//  - nama: FULLTEXT MATCH..AGAINST (memakai ft_parts_nama), fallback prefix LIKE
if ($action === 'search_parts') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || mb_strlen($q) < 1) { echo json_encode([]); exit; }
    $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $cols = "id, kode, barcode, nama, kategori, stok, harga_jual";
    $results = []; $seen = [];
    $push = function ($rows) use (&$results, &$seen) {
        foreach ($rows as $r) { if (!isset($seen[$r['id']])) { $seen[$r['id']] = true; $results[] = $r; } }
    };

    // 1) Prefix kode/barcode -> memakai index_merge (index unik kode + idx_parts_barcode).
    $st1 = $db->prepare("SELECT $cols FROM parts WHERE kode LIKE ? OR barcode LIKE ? ORDER BY kode LIMIT 20");
    $st1->execute([$prefix, $prefix]);
    $push($st1->fetchAll(PDO::FETCH_ASSOC));

    // 2) Nama via FULLTEXT (memakai ft_parts_nama), termasuk kata di tengah nama.
    if (count($results) < 20) {
        $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
        $boolean = '';
        foreach ($words as $w) {
            $w = trim(preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $w));
            if ($w !== '' && mb_strlen($w) >= 2) $boolean .= '+' . $w . '* ';
        }
        $boolean = trim($boolean);
        if ($boolean !== '') {
            try {
                $st2 = $db->prepare("SELECT $cols FROM parts WHERE MATCH(nama) AGAINST(? IN BOOLEAN MODE) LIMIT 20");
                $st2->execute([$boolean]);
                $push($st2->fetchAll(PDO::FETCH_ASSOC));
            } catch (\Throwable $e) {
                // Fallback aman (host tanpa FULLTEXT): prefix nama (masih memakai idx_parts_nama).
                $st2 = $db->prepare("SELECT $cols FROM parts WHERE nama LIKE ? ORDER BY nama LIMIT 20");
                $st2->execute([$prefix]);
                $push($st2->fetchAll(PDO::FETCH_ASSOC));
            }
        }
    }

    echo json_encode(array_slice($results, 0, 20));
    exit;
}

// Ambil 1 sparepart berdasarkan barcode / kode PERSIS (untuk scanner kasir).
// Cepat & indexed; tidak memindai seluruh tabel.
if ($action === 'part_by_code') {
    $code = trim($_GET['code'] ?? '');
    if ($code === '') { echo json_encode(null); exit; }
    $stmt = $db->prepare("SELECT id, kode, barcode, nama, kategori, stok, harga_jual
        FROM parts WHERE barcode = ? OR kode = ? ORDER BY (barcode = ?) DESC LIMIT 1");
    $stmt->execute([$code, $code, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ?: null);
    exit;
}

// Ambil beberapa sparepart berdasarkan daftar id (untuk mode edit transaksi).
if ($action === 'parts_by_ids') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
    if (!$ids) { echo json_encode([]); exit; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, kode, barcode, nama, kategori, stok, harga_jual FROM parts WHERE id IN ($in)");
    $stmt->execute($ids);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Cari transaksi berdasarkan no nota / plat / nama pelanggan (modul garansi)
if ($action === 'search_trx') {
    $q = trim($_GET['q'] ?? '');
    $stmt = $db->prepare("SELECT t.id, t.no_nota, t.created_at, c.nama AS customer_nama, v.plat_nomor
        FROM transactions t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN vehicles v ON v.id = t.vehicle_id
        WHERE t.no_nota LIKE ? OR c.nama LIKE ? OR v.plat_nomor LIKE ?
        ORDER BY t.id DESC LIMIT 10");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt = $db->prepare("SELECT id, tipe, nama, qty, harga, subtotal, garansi_hari FROM transaction_items WHERE transaction_id=?");
    foreach ($rows as &$r) {
        $itemStmt->execute([$r['id']]);
        $r['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        // Sediakan versi WIB untuk tampilan & perhitungan masa garansi di browser
        $r['created_at_wib'] = lokal($r['created_at']);
        $r['tgl_beli_wib'] = lokal($r['created_at'], 'Y-m-d');
    }
    echo json_encode($rows);
    exit;
}

echo json_encode(['error' => 'unknown action']);
