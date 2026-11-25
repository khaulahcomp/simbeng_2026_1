<?php
// ============================================================
// rekap_sparepart.php - Rekap penjualan sparepart per kode.
// Menggabungkan seluruh baris sparepart (transaction_items.tipe='part')
// dari banyak faktur dalam satu periode menjadi satu baris per sparepart.
//
// HANYA MEMBACA data (SELECT). Tidak ada tabel/kolom baru dan tidak
// mengubah versi skema. Memakai index yang sudah ada:
// transactions.created_at, transaction_items.transaction_id & part_id.
//
// Catatan nilai: Total = jumlah subtotal item (sudah dipotong diskon
// per item). Diskon nota (diskon keseluruhan faktur) tidak dibagi ke
// item, sama seperti kolom "Sparepart" pada laporan transaksi.
// ============================================================

// Ambil & rapikan filter khusus rekap sparepart dari $_GET.
function rekap_sparepart_filter(): array {
    $q    = trim((string)($_GET['q'] ?? ''));
    $kat  = trim((string)($_GET['kategori'] ?? ''));
    $urut = (string)($_GET['urut'] ?? 'total');
    if (!in_array($urut, ['total', 'qty', 'kode'], true)) $urut = 'total';
    return ['q' => mb_substr($q, 0, 100), 'kategori' => mb_substr($kat, 0, 100), 'urut' => $urut];
}

// WHERE bersama untuk rekap & rincian.
function _rekap_sparepart_where(string $dari, string $sampai, array $f): array {
    [$su, $eu] = wib_range_utc($dari, $sampai);
    $where  = "ti.tipe = 'part' AND t.created_at >= ? AND t.created_at < ?";
    $params = [$su, $eu];
    if (($f['q'] ?? '') !== '') {
        $where .= " AND (p.kode LIKE ? OR p.nama LIKE ? OR ti.nama LIKE ?)";
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    if (($f['kategori'] ?? '') !== '') {
        $where .= " AND p.kategori = ?";
        $params[] = $f['kategori'];
    }
    return [$where, $params];
}

// Satu baris per sparepart: jumlah faktur, qty, harga rata-rata, diskon, total.
function rekap_sparepart(string $dari, string $sampai, array $f): array {
    [$where, $params] = _rekap_sparepart_where($dari, $sampai, $f);
    $order = [
        'total' => 'total DESC, kode ASC',
        'qty'   => 'qty DESC, kode ASC',
        'kode'  => 'kode ASC',
    ][$f['urut'] ?? 'total'] ?? 'total DESC, kode ASC';
    $sql = "SELECT ti.part_id,
                COALESCE(p.kode, '') AS kode,
                COALESCE(p.nama, MAX(ti.nama)) AS nama,
                COALESCE(p.kategori, '') AS kategori,
                COUNT(DISTINCT ti.transaction_id) AS jml_faktur,
                COALESCE(SUM(ti.qty), 0) AS qty,
                COALESCE(SUM(ti.qty * ti.harga), 0) AS bruto,
                COALESCE(SUM(ti.diskon), 0) AS diskon,
                COALESCE(SUM(ti.subtotal), 0) AS total
            FROM transaction_items ti
            JOIN transactions t ON t.id = ti.transaction_id
            LEFT JOIN parts p ON p.id = ti.part_id
            WHERE $where
            GROUP BY ti.part_id, p.kode, p.nama, p.kategori
            ORDER BY $order";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['harga_rata'] = (int)$r['qty'] > 0 ? (float)$r['bruto'] / (int)$r['qty'] : 0;
    }
    unset($r);
    return $rows;
}

// Rincian faktur asal. $part_id = null -> semua sparepart (untuk unduhan).
function rekap_sparepart_rincian(string $dari, string $sampai, array $f, ?int $part_id = null): array {
    [$where, $params] = _rekap_sparepart_where($dari, $sampai, $f);
    if ($part_id !== null) { $where .= " AND ti.part_id = ?"; $params[] = $part_id; }
    $sql = "SELECT ti.part_id, ti.transaction_id, t.no_nota, t.created_at, t.status_bayar, c.nama AS customer_nama,
                COALESCE(p.kode, '') AS kode, COALESCE(p.nama, ti.nama) AS nama,
                ti.qty, ti.harga, ti.diskon, ti.subtotal
            FROM transaction_items ti
            JOIN transactions t ON t.id = ti.transaction_id
            LEFT JOIN customers c ON c.id = t.customer_id
            LEFT JOIN parts p ON p.id = ti.part_id
            WHERE $where
            ORDER BY p.kode, t.created_at, ti.id";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Daftar kategori untuk dropdown filter.
function rekap_sparepart_kategori(): array {
    return db()->query("SELECT DISTINCT kategori FROM parts WHERE kategori <> '' ORDER BY kategori")
               ->fetchAll(PDO::FETCH_COLUMN);
}
