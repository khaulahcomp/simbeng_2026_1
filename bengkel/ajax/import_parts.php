<?php
// ============================================================
// import_parts.php - Import massal sparepart dari Excel/CSV
// Menerima JSON: {
//   rows: [ {kode, nama, kategori, harga_beli, harga_jual, stok, stok_min, barcode}, ... ],
//   mode: 'baru' | 'upgrade' (default: upgrade),
//   preview: 1 (opsional -> tidak commit, hanya kembalikan per-row status),
//   filename: '...' (opsional -> disimpan ke riwayat)
// }
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
init_db();
header('Content-Type: application/json');

$payload  = json_decode(file_get_contents('php://input'), true);
$rows     = $payload['rows'] ?? [];
$mode     = ($payload['mode'] ?? 'upgrade') === 'baru' ? 'baru' : 'upgrade';
$isPreview = !empty($payload['preview']);
$filename = trim((string)($payload['filename'] ?? ''));
if (!$rows) { echo json_encode(['ok' => false, 'message' => 'File kosong atau format tidak dikenali.']); exit; }

$db   = db();
$find = $db->prepare("SELECT id FROM parts WHERE kode = ?");

// Normalisasi baris + tentukan status per baris.
$normalized = [];
foreach ($rows as $i => $r) {
    $row = [];
    foreach ($r as $k => $v) $row[strtolower(trim((string)$k))] = $v;
    $kode = strtoupper(trim((string)($row['kode'] ?? '')));
    $nama = trim((string)($row['nama'] ?? ''));
    $status = 'invalid';   // invalid | new | update | skip
    $reason = '';
    if ($kode === '' || $nama === '') {
        $reason = 'Kolom kode/nama kosong';
    } else {
        $find->execute([$kode]);
        $exists = (bool)$find->fetchColumn();
        if ($exists) {
            if ($mode === 'baru') { $status = 'skip'; $reason = 'Kode sudah ada, dilewati (mode Baru).'; }
            else                  { $status = 'update'; $reason = 'Kode sudah ada, data akan diperbarui.'; }
        } else {
            $status = 'new'; $reason = 'Kode baru, akan ditambahkan.';
        }
    }
    $normalized[] = [
        'row'      => $i + 1,
        'kode'     => $kode,
        'nama'     => $nama,
        'kategori' => trim((string)($row['kategori'] ?? '')),
        'harga_beli' => (float)($row['harga_beli'] ?? 0),
        'harga_jual' => (float)($row['harga_jual'] ?? 0),
        'stok'     => (int)($row['stok'] ?? 0),
        'stok_min' => (int)($row['stok_min'] ?? 5),
        'barcode'  => trim((string)($row['barcode'] ?? '')),
        'lokasi_rak' => trim((string)($row['lokasi_rak'] ?? $row['rak'] ?? '')),
        'status'   => $status,
        'reason'   => $reason,
    ];
}

// Rangkuman jumlah per status untuk badge di preview & log.
$summary = ['total' => count($normalized), 'new' => 0, 'update' => 0, 'skip' => 0, 'invalid' => 0];
foreach ($normalized as $n) $summary[$n['status']]++;

if ($isPreview) {
    echo json_encode([
        'ok'      => true,
        'preview' => true,
        'mode'    => $mode,
        'summary' => $summary,
        'rows'    => $normalized,
    ]);
    exit;
}

// ---- Eksekusi commit -----------------------------------------
$ins  = $db->prepare("INSERT INTO parts (kode, nama, kategori, harga_beli, harga_jual, stok, stok_min, barcode, lokasi_rak) VALUES (?,?,?,?,?,?,?,?,?)");
$upd  = $db->prepare("UPDATE parts SET nama=?, kategori=?, harga_beli=?, harga_jual=?, stok=?, stok_min=?, barcode=?, lokasi_rak=? WHERE kode=?");
$insCat = $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)");

try {
    $db->beginTransaction();
    foreach ($normalized as $n) {
        if ($n['status'] === 'invalid' || $n['status'] === 'skip') continue;
        if ($n['kategori'] !== '') $insCat->execute([$n['kategori']]);
        $vals = [$n['nama'], $n['kategori'], $n['harga_beli'], $n['harga_jual'], $n['stok'], $n['stok_min'], $n['barcode'], $n['lokasi_rak']];
        if ($n['status'] === 'update') {
            $upd->execute([...$vals, $n['kode']]);
        } elseif ($n['status'] === 'new') {
            $ins->execute([$n['kode'], ...$vals]);
        }
    }
    // Riwayat import
    $u = current_user() ?: [];
    $db->prepare("INSERT INTO import_logs (filename, mode, total, inserted, updated, skipped, invalid, user_id, user_nama)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           $filename ?: 'unknown.csv', $mode,
           $summary['total'], $summary['new'], $summary['update'], $summary['skip'], $summary['invalid'],
           isset($u['id']) ? (int)$u['id'] : null, (string)($u['nama'] ?? ''),
       ]);
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan import: ' . $e->getMessage()]);
    exit;
}

$msg = $mode === 'baru'
    ? "Import (Format Baru) selesai: {$summary['new']} ditambah, {$summary['skip']} dilewati (kode sudah ada), {$summary['invalid']} baris tidak valid."
    : "Import (Upgrade) selesai: {$summary['new']} ditambah, {$summary['update']} diperbarui, {$summary['invalid']} baris tidak valid.";
echo json_encode(['ok' => true, 'preview' => false, 'summary' => $summary, 'message' => $msg]);
