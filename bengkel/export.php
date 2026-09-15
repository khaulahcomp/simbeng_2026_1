<?php
// ============================================================
// export.php - Unduh laporan dalam 3 format:
//   - Excel (.xls) : tabel HTML dengan MIME Excel, langsung terbuka di Excel
//   - Word  (.doc) : dokumen HTML dengan MIME Word
//   - PDF          : tampilan cetak -> pengguna memilih "Save as PDF"
// Tanpa library eksternal agar tetap ringan untuk cPanel/XAMPP.
// ============================================================
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
init_db();

$type   = $_GET['type'] ?? 'transactions';
$format = $_GET['format'] ?? 'xls';
// Whitelist parameter agar nilai tak dikenal tidak diproses sembarangan
if (!in_array($type, ['transactions', 'parts', 'stock', 'finance', 'debts', 'customers', 'template'], true)) $type = 'transactions';
if (!in_array($format, ['xls', 'doc', 'pdf'], true)) $format = 'pdf';
[, $dari, $sampai, $label] = resolve_periode();

// ---- Siapkan data sesuai jenis laporan ----
if ($type === 'parts') {
    // scope=page -> hanya baris yang sedang tampil pada halaman aktif menu
    // Sparepart (mengikuti filter pencarian & Stok Menipis + pagination).
    // scope=all (default) -> seluruh sparepart.
    $scope   = ($_GET['scope'] ?? 'all') === 'page' ? 'page' : 'all';
    $q       = trim($_GET['q'] ?? '');
    $filter  = $_GET['filter'] ?? '';
    $where   = []; $params = [];
    if ($q !== '') { $where[] = "(nama LIKE ? OR kode LIKE ? OR barcode LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }
    if ($filter === 'low') $where[] = "stok <= stok_min";

    if ($scope === 'page') {
        $per_page_opts = [25, 50, 100, 200];
        $per_page = (int)($_GET['per_page'] ?? 50);
        if (!in_array($per_page, $per_page_opts, true)) $per_page = 50;
        $countStmt = db()->prepare("SELECT COUNT(*) FROM parts" . ($where ? (' WHERE ' . implode(' AND ', $where)) : ''));
        $countStmt->execute($params);
        $total_rows = (int)$countStmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_rows / $per_page));
        $p = max(1, (int)($_GET['p'] ?? 1)); if ($p > $total_pages) $p = $total_pages;
        $offset = ($p - 1) * $per_page;
        $sql = "SELECT * FROM parts" . ($where ? (' WHERE ' . implode(' AND ', $where)) : '')
             . " ORDER BY id DESC LIMIT $per_page OFFSET $offset";
        $st = db()->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $judul = 'Daftar Sparepart (Halaman ' . $p . '/' . $total_pages . ')';
        $filterLine = [];
        if ($q !== '')       $filterLine[] = 'Pencarian: "' . $q . '"';
        if ($filter === 'low') $filterLine[] = 'Stok Menipis';
        $subjudul = ($filterLine ? implode(' · ', $filterLine) . ' · ' : '')
                  . count($rows) . ' baris (dari ' . $total_rows . ') · Dicetak: ' . date('d/m/Y H:i');
        $fname = 'daftar_sparepart_hlm' . $p . '_' . date('Ymd');
    } else {
        $judul = 'Daftar Sparepart';
        $subjudul = 'Dicetak: ' . date('d/m/Y H:i');
        $sql = "SELECT * FROM parts" . ($where ? (' WHERE ' . implode(' AND ', $where)) : '') . " ORDER BY kategori, nama";
        $st = db()->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $fname = 'daftar_sparepart_' . date('Ymd');
    }
    $headers = ['No','Kode','Barcode','Nama Barang','Kategori','Rak','Harga Beli','Harga Jual','Stok','Stok Min','Status'];
    $data = [];
    $no = 1;
    foreach ($rows as $r) {
        $data[] = [$no++, $r['kode'], $r['barcode'], $r['nama'], $r['kategori'],
                   $r['lokasi_rak'] ?? '',
                   rupiah($r['harga_beli']), rupiah($r['harga_jual']), $r['stok'], $r['stok_min'],
                   $r['stok'] <= $r['stok_min'] ? 'MENIPIS' : 'Aman'];
    }
    $footer = null;
} elseif ($type === 'stock') {
    // ---- Laporan pergerakan stok: masuk / keluar / penjualan / garansi ----
    $jenis = $_GET['jenis'] ?? 'semua';
    $dari = _valid_date($_GET['dari'] ?? '') ? $_GET['dari'] : date('Y-m-01');
    $sampai = _valid_date($_GET['sampai'] ?? '') ? $_GET['sampai'] : date('Y-m-d');
    if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
    $where = "DATE(sm.created_at + INTERVAL 7 HOUR) BETWEEN ? AND ?";
    $params = [$dari, $sampai];
    $labelJenis = 'Semua Pergerakan';
    if ($jenis === 'masuk') { $where .= " AND sm.tipe='masuk'"; $labelJenis = 'Stok Masuk'; }
    elseif ($jenis === 'keluar') { $where .= " AND sm.tipe='keluar'"; $labelJenis = 'Stok Keluar'; }
    elseif ($jenis === 'penjualan') { $where .= " AND sm.ref_type='penjualan'"; $labelJenis = 'Penjualan (Kasir)'; }
    elseif ($jenis === 'garansi') { $where .= " AND sm.ref_type='garansi'"; $labelJenis = 'Penggantian Garansi'; }
    $stmt = db()->prepare("SELECT sm.*, p.kode, p.nama AS part_nama, s.nama AS supplier_nama
        FROM stock_movements sm
        JOIN parts p ON p.id = sm.part_id
        LEFT JOIN suppliers s ON s.id = sm.supplier_id
        WHERE $where ORDER BY sm.created_at");
    $stmt->execute($params);
    $judul = 'Laporan Stok — ' . $labelJenis;
    $subjudul = 'Periode: ' . date('d/m/Y', strtotime($dari)) . ' s.d. ' . date('d/m/Y', strtotime($sampai));
    $headers = ['No','Tanggal','Kode','Nama Barang','Tipe','Sumber','Jumlah','Supplier','Keterangan'];
    $data = [];
    $no = 1; $tj = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tj += (int)$r['jumlah'];
        $data[] = [$no++, lokal($r['created_at']), $r['kode'], $r['part_nama'], strtoupper($r['tipe']),
                   $r['ref_type'] ? ucfirst($r['ref_type']) : 'Manual', $r['jumlah'], $r['supplier_nama'] ?: '-', $r['keterangan']];
    }
    $footer = ['', 'TOTAL', '', '', '', '', (string)$tj, '', ''];
    $fname = 'laporan_stok_' . $jenis . '_' . $dari . '_sd_' . $sampai;
} elseif ($type === 'finance') {
    // ---- Laporan Keuangan: Ringkasan Laba/Rugi + Buku Kas ----
    [, $dari, $sampai, $label] = resolve_periode();
    $t = db()->prepare("SELECT COALESCE(SUM(total_jasa),0) jasa, COALESCE(SUM(total_part),0) part,
        COALESCE(SUM(diskon),0) diskon, COALESCE(SUM(grand_total),0) grand, COUNT(*) n
        FROM transactions WHERE DATE(created_at + INTERVAL 7 HOUR) BETWEEN ? AND ?");
    $t->execute([$dari, $sampai]); $trx = $t->fetch(PDO::FETCH_ASSOC);
    $hstmt = db()->prepare("SELECT COALESCE(SUM(ti.qty * COALESCE(p.harga_beli,0)),0)
        FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id
        LEFT JOIN parts p ON p.id=ti.part_id
        WHERE ti.tipe='part' AND DATE(t.created_at + INTERVAL 7 HOUR) BETWEEN ? AND ?");
    $hstmt->execute([$dari, $sampai]); $hpp = (float)$hstmt->fetchColumn();
    $cstmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE 0 END),0) masuk,
        COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah ELSE 0 END),0) keluar
        FROM cash_entries WHERE tgl BETWEEN ? AND ?");
    $cstmt->execute([$dari, $sampai]); $cash = $cstmt->fetch(PDO::FETCH_ASSOC);
    $pendapatan = (float)$trx['grand']; $labaKotor = $pendapatan - $hpp;
    $pemasukanLain = (float)$cash['masuk']; $pengeluaran = (float)$cash['keluar'];
    $labaBersih = $labaKotor + $pemasukanLain - $pengeluaran;
    $lstmt = db()->prepare("SELECT * FROM cash_entries WHERE tgl BETWEEN ? AND ? ORDER BY tgl, id");
    $lstmt->execute([$dari, $sampai]); $ledger = $lstmt->fetchAll(PDO::FETCH_ASSOC);

    $judul = 'Laporan Keuangan (Laba/Rugi & Buku Kas)';
    $subjudul = 'Periode: ' . $label;
    $fname = 'laporan_keuangan_' . $dari . '_sd_' . $sampai;

    $pnl = [
        ['Pendapatan jasa', rupiah($trx['jasa']), false],
        ['Pendapatan sparepart', rupiah($trx['part']), false],
        ['Diskon diberikan', '- ' . rupiah($trx['diskon']), false],
        ['Total pendapatan', rupiah($pendapatan), true],
        ['HPP sparepart terjual', '- ' . rupiah($hpp), false],
        ['Laba kotor', rupiah($labaKotor), true],
        ['Pemasukan lain (kas)', '+ ' . rupiah($pemasukanLain), false],
        ['Pengeluaran operasional (kas)', '- ' . rupiah($pengeluaran), false],
        [($labaBersih >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH'), rupiah($labaBersih), true],
    ];
    $financeHtml = '<h3 style="margin:14px 0 6px">Ringkasan Laba / Rugi</h3>';
    $financeHtml .= '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:60%;font-size:13px">';
    foreach ($pnl as $row) {
        $financeHtml .= '<tr' . ($row[2] ? ' style="font-weight:bold;background:#eee"' : '') . '><td>'
                      . esc($row[0]) . '</td><td align="right">' . esc($row[1]) . '</td></tr>';
    }
    $financeHtml .= '</table>';
    $financeHtml .= '<h3 style="margin:16px 0 6px">Buku Kas</h3>';
    $financeHtml .= '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px">'
                  . '<thead><tr style="background:#1e2a38;color:#fff"><th>No</th><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th></tr></thead><tbody>';
    if (!$ledger) $financeHtml .= '<tr><td colspan="6" align="center">Tidak ada entri kas.</td></tr>';
    $no = 1;
    foreach ($ledger as $e) {
        $financeHtml .= '<tr><td>' . ($no++) . '</td><td>' . esc(date('d/m/Y', strtotime($e['tgl']))) . '</td><td>'
                      . esc($e['kategori']) . '</td><td>' . esc($e['keterangan']) . '</td><td align="right">'
                      . ($e['tipe'] === 'masuk' ? esc(rupiah($e['jumlah'])) : '-') . '</td><td align="right">'
                      . ($e['tipe'] === 'keluar' ? esc(rupiah($e['jumlah'])) : '-') . '</td></tr>';
    }
    $financeHtml .= '<tr style="font-weight:bold;background:#eee"><td colspan="4" align="right">TOTAL</td><td align="right">'
                  . esc(rupiah($pemasukanLain)) . '</td><td align="right">' . esc(rupiah($pengeluaran)) . '</td></tr>';
    $financeHtml .= '</tbody></table>';
    $headers = []; $data = []; $footer = null;
} elseif ($type === 'debts') {
    // ---- Rekap Hutang & Piutang ----
    $jenis = $_GET['jenis'] ?? 'semua';
    $w = ''; $pr = [];
    if ($jenis === 'piutang' || $jenis === 'hutang') { $w = "WHERE jenis=?"; $pr = [$jenis]; }
    $st = db()->prepare("SELECT * FROM debts $w ORDER BY (status='belum_lunas') DESC, COALESCE(jatuh_tempo,tgl) ASC, id DESC");
    $st->execute($pr);
    $judul = 'Rekap Hutang & Piutang' . ($jenis !== 'semua' ? ' (' . ucfirst($jenis) . ')' : '');
    $subjudul = 'Dicetak: ' . date('d/m/Y H:i');
    $headers = ['No', 'Jenis', 'Pihak', 'Telepon', 'Keterangan', 'Jumlah', 'Dibayar', 'Sisa', 'Tanggal', 'Jatuh Tempo', 'Status'];
    $data = []; $no = 1; $tJ = 0; $tD = 0; $tS = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sisa = (float)$r['jumlah'] - (float)$r['dibayar'];
        $tJ += (float)$r['jumlah']; $tD += (float)$r['dibayar']; $tS += $sisa;
        $data[] = [$no++, strtoupper($r['jenis']), $r['pihak'], $r['telepon'], $r['keterangan'],
                   rupiah($r['jumlah']), rupiah($r['dibayar']), rupiah($sisa),
                   $r['tgl'] ? date('d/m/Y', strtotime($r['tgl'])) : '-',
                   $r['jatuh_tempo'] ? date('d/m/Y', strtotime($r['jatuh_tempo'])) : '-',
                   $r['status'] === 'lunas' ? 'Lunas' : 'Belum lunas'];
    }
    $footer = ['', 'TOTAL', '', '', '', rupiah($tJ), rupiah($tD), rupiah($tS), '', '', ''];
    $fname = 'rekap_hutang_piutang_' . date('Ymd');
} elseif ($type === 'customers') {
    // ---- Daftar Pelanggan (mengikuti pencarian bila ada) ----
    $q = trim($_GET['q'] ?? '');
    $where = ''; $params = [];
    if ($q !== '') { $where = "WHERE c.nama LIKE ? OR c.telepon LIKE ?"; $params = ["%$q%", "%$q%"]; }
    $st = db()->prepare("SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS jml_kendaraan
        FROM customers c $where ORDER BY c.nama");
    $st->execute($params);
    $judul = 'Daftar Pelanggan';
    $subjudul = ($q !== '' ? 'Pencarian: "' . $q . '" · ' : '') . 'Dicetak: ' . date('d/m/Y H:i');
    $headers = ['No', 'Nama', 'Telepon', 'Alamat', 'Jumlah Kendaraan'];
    $data = []; $no = 1;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $data[] = [$no++, $r['nama'], $r['telepon'] ?: '-', $r['alamat'] ?: '-', (int)$r['jml_kendaraan']];
    }
    $footer = ['', 'TOTAL ' . ($no - 1) . ' pelanggan', '', '', ''];
    $fname = 'daftar_pelanggan_' . date('Ymd');
} elseif ($type === 'template') {
    // ---- Template Excel siap-isi untuk Impor Harga Sparepart ----
    $format = 'xls';
    $st = db()->query("SELECT kode, nama, kategori FROM parts WHERE harga_jual=0 OR harga_beli=0 ORDER BY kategori, nama");
    $judul = 'Template Impor Harga Sparepart';
    $subjudul = 'Isi kolom harga_beli, harga_jual, stok, stok_min lalu unggah kembali di menu "Lengkapi Harga". JANGAN ubah kolom kode.';
    $headers = ['kode', 'nama', 'kategori', 'harga_beli', 'harga_jual', 'stok', 'stok_min'];
    $data = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $data[] = [$r['kode'], $r['nama'], $r['kategori'], '', '', '', ''];
    }
    $footer = null;
    $fname = 'template_harga_sparepart_' . date('Ymd');
} else {
    $judul = 'Laporan Transaksi';
    $subjudul = 'Periode: ' . $label;
    $headers = ['No','No. Nota','Tanggal','Pelanggan','Plat','Jasa','Sparepart','Total'];
    $rows = laporan_transaksi($dari, $sampai);
    $data = [];
    $no = 1; $tj = 0; $tp = 0; $ta = 0;
    foreach ($rows as $r) {
        $tj += $r['total_jasa']; $tp += $r['total_part']; $ta += $r['grand_total'];
        $data[] = [$no++, $r['no_nota'], lokal($r['created_at']), $r['customer_nama'], $r['plat_nomor'] ?: '-',
                   rupiah($r['total_jasa']), rupiah($r['total_part']), rupiah($r['grand_total'])];
    }
    $footer = ['', 'TOTAL (' . count($rows) . ' transaksi)', '', '', '', rupiah($tj), rupiah($tp), rupiah($ta)];
    $fname = 'laporan_transaksi_' . $dari . '_sd_' . $sampai;
}

// ---- Bangun tabel HTML (dipakai oleh semua format) ----
$html  = '<h2 style="margin:0">' . esc($judul) . '</h2>';
$html .= '<p style="margin:4px 0 12px">' . esc($subjudul) . ' &mdash; ' . esc(setting('nama_bengkel', 'Bengkel Motor')) . '</p>';
if (!empty($financeHtml)) {
    $html .= $financeHtml;
} else {
$html .= '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px">';
$html .= '<thead><tr style="background:#1e2a38;color:#fff">';
foreach ($headers as $h) $html .= '<th>' . esc($h) . '</th>';
$html .= '</tr></thead><tbody>';
if (!$data) $html .= '<tr><td colspan="' . count($headers) . '" align="center">Tidak ada data.</td></tr>';
foreach ($data as $d) {
    $html .= '<tr>';
    foreach ($d as $c) $html .= '<td>' . esc($c) . '</td>';
    $html .= '</tr>';
}
if ($footer) {
    $html .= '<tr style="font-weight:bold;background:#eeeeee">';
    foreach ($footer as $c) $html .= '<td>' . esc($c) . '</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';
}

// ---- Output sesuai format ----
if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '.xls"');
    echo "\xEF\xBB\xBF" . $html; // BOM agar karakter UTF-8 terbaca benar di Excel
    exit;
}
if ($format === 'doc') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '.doc"');
    echo "\xEF\xBB\xBF<html><head><meta charset=\"UTF-8\"></head><body>$html</body></html>";
    exit;
}
// format=pdf: halaman cetak (pilih "Save as PDF" pada dialog print browser)
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= esc($judul) ?></title>
<style>
  body { font-family: Arial, sans-serif; padding: 24px; color: #222; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:14px" data-testid="pdf-toolbar">
  <button onclick="window.print()" data-testid="pdf-print-btn">Cetak / Simpan sebagai PDF</button>
  <button onclick="window.close()">Tutup</button>
  <span style="font-size:12px;color:#666">Pada dialog cetak, pilih tujuan <strong>"Save as PDF"</strong> untuk mengunduh file PDF.</span>
</div>
<?= $html ?>
<script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
