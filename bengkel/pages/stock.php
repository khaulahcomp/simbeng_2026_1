<?php
$db = db();
$action = $_POST['action'] ?? '';

require_once __DIR__ . '/../includes/purchase.php';

// ---- Barang masuk dari supplier = FAKTUR TAGIHAN (banyak item sekaligus) ----
// Fitur "Barang Keluar (manual)" sudah dihapus (tercover oleh data penjualan).
if ($action === 'save_masuk') {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $no_sup  = mb_substr(trim($_POST['no_faktur_supplier'] ?? ''), 0, 100);
    $tgl     = _valid_date($_POST['tgl'] ?? '') ? $_POST['tgl'] : date('Y-m-d');
    $jt      = _valid_date($_POST['jatuh_tempo'] ?? '') ? $_POST['jatuh_tempo'] : null;
    $status  = (($_POST['status_bayar'] ?? 'belum') === 'lunas') ? 'lunas' : 'belum';
    $catatan = mb_substr(trim($_POST['catatan'] ?? ''), 0, 500);
    $upd_hb  = !empty($_POST['update_harga_beli']);

    $sp = $db->prepare("SELECT id, nama FROM suppliers WHERE id=?");
    $sp->execute([$supplier_id]);
    $sup = $sp->fetch(PDO::FETCH_ASSOC);
    if (!$sup) {
        set_flash('danger', 'Pilih supplier terlebih dahulu.');
        header('Location: index.php?page=stock'); exit;
    }

    $pids  = (array)($_POST['part_id'] ?? []);
    $qtys  = (array)($_POST['qty'] ?? []);
    $hargas = (array)($_POST['harga_beli'] ?? []);
    $raks  = (array)($_POST['rak'] ?? []);
    $pq = $db->prepare("SELECT id, kode, nama FROM parts WHERE id=?");
    $lines = []; $total = 0.0;
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid; $qty = (int)($qtys[$i] ?? 0);
        $hb  = max(0, (float)($hargas[$i] ?? 0));
        if ($pid <= 0 || $qty <= 0) continue;
        $pq->execute([$pid]);
        $part = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$part) continue;
        $lines[] = ['part' => $part, 'qty' => $qty, 'hb' => $hb, 'sub' => $qty * $hb, 'rak' => trim((string)($raks[$i] ?? ''))];
        $total += $qty * $hb;
    }
    if (!$lines) {
        set_flash('danger', 'Tambahkan minimal satu sparepart (pilih dari hasil pencarian) dengan jumlah minimal 1.');
        header('Location: index.php?page=stock'); exit;
    }

    try {
        $db->beginTransaction();
        $no_faktur = next_kode('PBL', 'supplier_invoices', 'no_faktur');
        $db->prepare("INSERT INTO supplier_invoices (no_faktur, no_faktur_supplier, supplier_id, tgl, jatuh_tempo, total, status_bayar, catatan, user_nama)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$no_faktur, $no_sup, $supplier_id, $tgl, $jt, $total, $status, $catatan, (string)(current_user()['nama'] ?? '')]);
        $inv_id = (int)$db->lastInsertId();
        $ket = 'Faktur ' . $no_faktur . ($no_sup !== '' ? ' (No. supplier: ' . $no_sup . ')' : '');
        $insItem = $db->prepare("INSERT INTO supplier_invoice_items (invoice_id, part_id, kode, nama, qty, harga_beli, subtotal, rak) VALUES (?,?,?,?,?,?,?,?)");
        $insMv   = $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, supplier_id, ref_type, ref_id, keterangan) VALUES (?,?,?,?,?,?,?)");
        foreach ($lines as $ln) {
            $pt = $ln['part'];
            $insItem->execute([$inv_id, $pt['id'], $pt['kode'], $pt['nama'], $ln['qty'], $ln['hb'], $ln['sub'], $ln['rak']]);
            $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$ln['qty'], $pt['id']]);
            if ($upd_hb && $ln['hb'] > 0) $db->prepare("UPDATE parts SET harga_beli=? WHERE id=?")->execute([$ln['hb'], $pt['id']]);
            $insMv->execute([$pt['id'], 'masuk', $ln['qty'], $supplier_id, 'pembelian', $inv_id, $ket]);
            // Multi Rak: tambahkan ke rak pilihan (Rak Utama bila kosong).
            rack_receive_masuk($db, (int)$pt['id'], $ln['rak'], $ln['qty']);
        }
        sync_purchase_debt($db, $inv_id); // BELUM LUNAS -> otomatis jadi hutang
        $db->commit();
        set_flash('success', "Faktur $no_faktur tersimpan. Stok " . count($lines) . " sparepart bertambah"
            . ($status === 'belum' ? ' dan tagihan tercatat di Hutang Piutang.' : '.'));
        header('Location: index.php?page=purchase_receipt&id=' . $inv_id); exit;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        set_flash('danger', 'Gagal menyimpan faktur barang masuk: ' . $e->getMessage());
    }
    header('Location: index.php?page=stock'); exit;
}

$suppliers = $db->query("SELECT id, nama FROM suppliers ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);

// Riwayat pergerakan stok: filter cari (kode/nama part) + jenis pergerakan + jumlah per halaman + pagination
$q = trim($_GET['q'] ?? '');
$jenis = $_GET['jenis'] ?? 'semua';
$allowedJenis = ['semua', 'masuk', 'keluar', 'penjualan', 'garansi'];
if (!in_array($jenis, $allowedJenis, true)) $jenis = 'semua';
$allowedPer = [10, 25, 50, 100];
$per = (int)($_GET['per_page'] ?? 25);
if (!in_array($per, $allowedPer, true)) $per = 25;
// Rentang tanggal (WIB, inklusif). Kosong = tidak dibatasi. Nilai dipertahankan
// pada form, pagination, dan unduhan (tidak lagi kembali ke tanggal 01).
$dari   = _valid_date($_GET['dari']   ?? '') ? $_GET['dari']   : '';
$sampai = _valid_date($_GET['sampai'] ?? '') ? $_GET['sampai'] : '';
if ($dari !== '' && $sampai !== '' && $dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
$conds = []; $params = [];
if ($dari !== '')   { [$d_su, ]  = wib_range_utc($dari, $dari);     $conds[] = "sm.created_at >= ?"; $params[] = $d_su; }
if ($sampai !== '') { [, $d_eu] = wib_range_utc($sampai, $sampai); $conds[] = "sm.created_at < ?";  $params[] = $d_eu; }
if ($q !== '') {
    $conds[] = "(p.kode LIKE ? OR p.nama LIKE ? OR p.barcode LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
// Filter jenis pergerakan (selaras dengan opsi ekspor):
//  masuk=barang masuk, keluar=barang keluar manual, penjualan=kasir, garansi=penggantian klaim
if ($jenis === 'masuk')          $conds[] = "sm.tipe='masuk'";
elseif ($jenis === 'keluar')     $conds[] = "sm.tipe='keluar' AND (sm.ref_type='manual' OR sm.ref_type='')";
elseif ($jenis === 'penjualan')  $conds[] = "sm.ref_type='penjualan'";
elseif ($jenis === 'garansi')    $conds[] = "sm.ref_type='garansi'";
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
$cnt = $db->prepare("SELECT COUNT(*) FROM stock_movements sm JOIN parts p ON p.id = sm.part_id $where");
$cnt->execute($params);
$totalRows = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $per));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $per;
$logStmt = $db->prepare("SELECT sm.*, p.kode, p.nama AS part_nama, s.nama AS supplier_nama
    FROM stock_movements sm
    JOIN parts p ON p.id = sm.part_id
    LEFT JOIN suppliers s ON s.id = sm.supplier_id
    $where
    ORDER BY sm.id DESC LIMIT $per OFFSET $offset");
$logStmt->execute($params);
$log = $logStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
  /* Autocomplete pencarian kode part */
  .part-search-wrap { position: relative; }
  .part-search-list {
    position: absolute; left: 0; right: 0; top: 100%;
    background: #fff; border: 1px solid #d0d5dd; border-top: none;
    border-radius: 0 0 .375rem .375rem; max-height: 260px; overflow-y: auto;
    z-index: 30; box-shadow: 0 8px 20px rgba(15, 23, 42, .12);
    display: none;
  }
  .part-search-list.is-open { display: block; }
  .part-search-item {
    padding: .5rem .625rem; cursor: pointer; border-bottom: 1px solid #f1f3f7;
    font-size: .8125rem; line-height: 1.25;
  }
  .part-search-item:last-child { border-bottom: 0; }
  .part-search-item:hover, .part-search-item.is-active { background: #eef4ff; }
  .part-search-item .kode {
    display: inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    background: #eef2f7; color: #1f2937; padding: 1px 6px; border-radius: 4px;
    font-size: .72rem; margin-right: .35rem;
  }
  .part-search-item .stok { color: #64748b; font-size: .72rem; }
  .part-search-item .stok.low { color: #b91c1c; font-weight: 600; }
  .part-search-empty { padding: .6rem .625rem; font-size: .78rem; color: #64748b; }
  .part-selected-card {
    background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: .375rem;
    padding: .45rem .55rem; font-size: .78rem; display: none;
  }
  .part-selected-card.is-visible { display: block; }
  .part-selected-card .clear-btn {
    background: transparent; border: 0; color: #b91c1c; font-size: .72rem; padding: 0;
  }
</style>

<div class="row g-3">
  <div class="col-12">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h6 mb-0 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>Barang Masuk dari Supplier &mdash; Faktur Tagihan</h2>
        <a href="index.php?page=suppliers" class="small"><i class="bi bi-truck me-1"></i>Lihat faktur tagihan per supplier</a>
      </div>
      <?php if (!$suppliers): ?>
        <div class="alert alert-warning small mb-0">Belum ada supplier. <a href="index.php?page=suppliers">Tambah supplier</a> terlebih dahulu.</div>
      <?php else: ?>
      <form method="post" id="purchaseForm" data-testid="purchase-form" autocomplete="off">
        <input type="hidden" name="action" value="save_masuk">
        <div class="row g-2 mb-2">
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Supplier <span class="text-danger">*</span></label>
            <select name="supplier_id" class="form-select form-select-sm" required data-testid="purchase-supplier">
              <option value="">- Pilih supplier -</option>
              <?php foreach ($suppliers as $s_): ?><option value="<?= $s_['id'] ?>"><?= esc($s_['nama']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label small mb-1">No. Faktur dari Supplier</label>
            <input type="text" name="no_faktur_supplier" class="form-control form-control-sm" maxlength="100" placeholder="mis. INV-2026/0123" data-testid="purchase-no-supplier">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Tanggal Faktur</label>
            <input type="date" name="tgl" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm" data-testid="purchase-tgl">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Jatuh Tempo Bayar</label>
            <input type="date" name="jatuh_tempo" class="form-control form-control-sm" data-testid="purchase-jt">
          </div>
        </div>

        <label class="form-label small mb-1">Cari sparepart (kode / barcode / nama), klik hasil untuk menambah ke daftar</label>
        <div class="part-search-wrap mb-2">
          <input type="text" class="form-control form-control-sm" placeholder="Ketik kode part, barcode, atau nama..." data-role="part-search" data-testid="purchase-part-search">
          <div class="part-search-list" data-role="part-list"></div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-2" data-testid="purchase-items">
            <thead><tr>
              <th>Sparepart</th><th class="text-end" style="width:70px">Stok</th>
              <th style="width:100px">Jumlah</th><th style="width:150px">Harga Beli / unit (Rp)</th>
              <th class="text-end" style="width:130px">Subtotal</th><th style="width:150px">Rak</th><th style="width:36px"></th>
            </tr></thead>
            <tbody id="purchaseBody">
              <tr id="purchaseEmpty"><td colspan="7" class="text-center text-muted small py-3">Belum ada barang. Cari sparepart di atas untuk menambahkan.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-5">
            <label class="form-label small mb-1">Catatan</label>
            <input type="text" name="catatan" class="form-control form-control-sm" maxlength="500" placeholder="Opsional" data-testid="purchase-catatan">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label small mb-1 d-block">Status Pembayaran ke Supplier</label>
            <div class="btn-group btn-group-sm w-100" role="group">
              <input type="radio" class="btn-check" name="status_bayar" id="sbBelum" value="belum" checked>
              <label class="btn btn-outline-danger" for="sbBelum" data-testid="purchase-status-belum">Belum Lunas</label>
              <input type="radio" class="btn-check" name="status_bayar" id="sbLunas" value="lunas">
              <label class="btn btn-outline-success" for="sbLunas" data-testid="purchase-status-lunas">Lunas</label>
            </div>
          </div>
          <div class="col-12 col-md-4 text-md-end">
            <div class="small text-muted">Total Tagihan</div>
            <div class="fs-5 fw-bold" id="purchaseTotal" data-testid="purchase-total">Rp 0</div>
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="update_harga_beli" value="1" id="updHb" data-testid="purchase-upd-hb">
          <label class="form-check-label small" for="updHb">Perbarui harga beli di data sparepart sesuai faktur ini <span class="text-muted">(memengaruhi perhitungan laba)</span></label>
        </div>
        <button class="btn btn-success w-100 mt-2" data-testid="purchase-submit"><i class="bi bi-receipt me-1"></i>Simpan &amp; Cetak Faktur Tagihan</button>
        <div class="form-text">Status <strong>Belum Lunas</strong> otomatis dicatat sebagai hutang ke supplier di menu Hutang Piutang.</div>
      </form>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-12">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h2 class="h6 mb-0">Riwayat Pergerakan Stok</h2>
        <form class="d-flex gap-1 flex-wrap align-items-center" method="get" action="export.php" target="_blank" data-testid="stock-export-form">
          <input type="hidden" name="type" value="stock">
          <!-- Unduhan mengikuti filter di bawah (jenis, rentang tanggal, kata kunci). -->
          <input type="hidden" name="jenis" value="<?= esc($jenis) ?>" data-testid="stock-export-jenis">
          <input type="hidden" name="dari" value="<?= esc($dari) ?>" data-testid="stock-export-dari">
          <input type="hidden" name="sampai" value="<?= esc($sampai) ?>" data-testid="stock-export-sampai">
          <input type="hidden" name="q" value="<?= esc($q) ?>">
          <span class="small text-muted">Unduh sesuai filter:</span>
          <button name="format" value="pdf" class="btn btn-sm btn-outline-danger" title="Unduh PDF" data-testid="stock-export-pdf"><i class="bi bi-file-earmark-pdf"></i></button>
          <button name="format" value="xls" class="btn btn-sm btn-outline-success" title="Unduh Excel" data-testid="stock-export-xls"><i class="bi bi-file-earmark-excel"></i></button>
          <button name="format" value="doc" class="btn btn-sm btn-outline-primary" title="Unduh Word" data-testid="stock-export-doc"><i class="bi bi-file-earmark-word"></i></button>
        </form>
      </div>
      <form class="row g-2 align-items-end mb-3" method="get" data-testid="stock-search-form">
        <input type="hidden" name="page" value="stock">
        <!-- Baris 1: kata kunci + rentang tanggal -->
        <div class="col-12 col-lg-6">
          <label class="form-label small mb-1">Cari Kode / Nama Part</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control form-control-sm" placeholder="Ketik kode part atau nama sparepart..." data-testid="stock-search-q">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small mb-1">Dari Tanggal</label>
          <input type="date" name="dari" value="<?= esc($dari) ?>" class="form-control form-control-sm" data-testid="stock-search-dari">
        </div>
        <div class="col-6 col-lg-3">
          <label class="form-label small mb-1">Sampai Tanggal</label>
          <input type="date" name="sampai" value="<?= esc($sampai) ?>" class="form-control form-control-sm" data-testid="stock-search-sampai">
        </div>
        <!-- Baris 2: jenis + per halaman + tombol aksi (sejajar, tidak keluar kartu) -->
        <div class="col-7 col-lg-4">
          <label class="form-label small mb-1">Jenis Pergerakan</label>
          <select name="jenis" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="stock-search-jenis">
            <?php foreach ([
                'semua'     => 'Semua',
                'masuk'     => 'Stok Masuk',
                'keluar'    => 'Stok Keluar',
                'penjualan' => 'Penjualan',
                'garansi'   => 'Garansi',
            ] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $jenis === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-5 col-lg-3">
          <label class="form-label small mb-1">Tampil / halaman</label>
          <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="stock-search-perpage">
            <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?> data</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-5 d-flex gap-2">
          <button class="btn btn-sm btn-primary flex-fill" data-testid="stock-search-btn"><i class="bi bi-search me-1"></i>Cari</button>
          <?php if ($q !== '' || $jenis !== 'semua' || $dari !== '' || $sampai !== ''): ?><a href="index.php?page=stock" class="btn btn-sm btn-outline-secondary flex-fill" data-testid="stock-search-reset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a><?php endif; ?>
        </div>
      </form>
      <?php $jenisLbl = ['semua'=>'Semua','masuk'=>'Stok Masuk','keluar'=>'Stok Keluar','penjualan'=>'Penjualan','garansi'=>'Garansi'][$jenis]; ?>
      <div class="small text-muted mb-2" data-testid="stock-log-count">Menampilkan <?= count($log) ?> dari <?= $totalRows ?> data<?= $q !== '' ? ' (cari: "' . esc($q) . '")' : '' ?><?= $jenis !== 'semua' ? ' (jenis: ' . esc($jenisLbl) . ')' : '' ?><?= ($dari !== '' || $sampai !== '') ? ' (periode: ' . ($dari !== '' ? esc(date('d/m/Y', strtotime($dari))) : 'awal') . ' s.d. ' . ($sampai !== '' ? esc(date('d/m/Y', strtotime($sampai))) : 'sekarang') . ')' : '' ?>.</div>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="stock-log-table">
        <thead><tr><th>Tanggal</th><th>Barang</th><th>Tipe</th><th class="text-end">Jumlah</th><th>Supplier/Sumber</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php if (!$log): ?><tr><td colspan="6" class="text-center text-muted">Belum ada pergerakan stok.</td></tr><?php endif; ?>
        <?php foreach ($log as $l): ?>
          <tr>
            <td class="small"><?= esc(lokal($l['created_at'])) ?></td>
            <td><?= esc($l['kode']) ?> - <?= esc($l['part_nama']) ?></td>
            <td><span class="badge bg-<?= $l['tipe']==='masuk' ? 'success' : 'danger' ?>"><?= strtoupper($l['tipe']) ?></span>
              <?php if ($l['ref_type'] === 'rak_baru'): ?><span class="badge bg-warning text-dark"><i class="bi bi-diagram-3 me-1"></i>Stok Rak Baru</span>
              <?php elseif ($l['ref_type'] && $l['ref_type'] !== 'manual'): ?><span class="badge bg-secondary"><?= esc($l['ref_type']) ?></span><?php endif; ?></td>
            <td class="text-end"><?= $l['jumlah'] ?></td>
            <td><?= esc($l['supplier_nama'] ?? '-') ?></td>
            <td class="small text-muted"><?php if ($l['ref_type'] === 'pembelian' && !empty($l['ref_id'])): ?><a href="index.php?page=purchase_receipt&amp;id=<?= (int)$l['ref_id'] ?>"><?= esc($l['keterangan']) ?></a><?php else: ?><?= esc($l['keterangan']) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($totalPages > 1): $qs = 'page=stock&q=' . urlencode($q) . '&jenis=' . urlencode($jenis) . '&dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai) . '&per_page=' . $per; ?>
      <nav class="d-flex justify-content-between align-items-center mt-2" data-testid="stock-pagination">
        <a class="btn btn-sm btn-outline-secondary <?= $pageNum<=1?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum-1 ?>" data-testid="stock-prev">&laquo; Sebelumnya</a>
        <span class="small text-muted">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $pageNum>=$totalPages?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum+1 ?>" data-testid="stock-next">Berikutnya &raquo;</a>
      </nav>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<script>
(function () {
  // Tombol unduh PDF/Excel/Word memakai nilai filter yang SEDANG terisi di form
  // pencarian (termasuk bila belum menekan Cari), sehingga tak ada lagi dua
  // set tanggal yang tidak saling terhubung.
  var exp = document.querySelector('[data-testid="stock-export-form"]');
  var flt = document.querySelector('[data-testid="stock-search-form"]');
  if (exp && flt) {
    exp.addEventListener('submit', function () {
      ['jenis', 'dari', 'sampai', 'q'].forEach(function (n) {
        var src = flt.querySelector('[name="' + n + '"]');
        var dst = exp.querySelector('[name="' + n + '"]');
        if (src && dst) dst.value = src.value;
      });
    });
  }
})();
(function () {
  // Form Faktur Barang Masuk: cari sparepart -> tambah baris -> hitung total.
  // Endpoint: ajax/lookup.php?action=search_parts&q=...  &  ajax/rak.php?action=list
  var form = document.getElementById('purchaseForm');
  if (!form) return;
  var input = form.querySelector('[data-role="part-search"]');
  var listEl = form.querySelector('[data-role="part-list"]');
  var body = document.getElementById('purchaseBody');
  var emptyRow = document.getElementById('purchaseEmpty');
  var totalEl = document.getElementById('purchaseTotal');
  var timer = null, results = [], activeIdx = -1;

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function rp(n) { return 'Rp ' + Math.round(n || 0).toLocaleString('id-ID'); }
  function num(v) { var x = parseFloat(v); return isNaN(x) ? 0 : x; }

  function recalc() {
    var total = 0, rows = body.querySelectorAll('tr[data-part]');
    rows.forEach(function (tr) {
      var sub = num(tr.querySelector('[name="qty[]"]').value) * num(tr.querySelector('[name="harga_beli[]"]').value);
      tr.querySelector('[data-role="sub"]').textContent = rp(sub);
      total += sub;
    });
    totalEl.textContent = rp(total);
    emptyRow.style.display = rows.length ? 'none' : '';
  }

  function closeList() { listEl.classList.remove('is-open'); activeIdx = -1; }

  function render(items) {
    results = items || []; activeIdx = -1;
    if (!results.length) {
      listEl.innerHTML = '<div class="part-search-empty">Tidak ada sparepart cocok. Coba kata kunci lain.</div>';
    } else {
      listEl.innerHTML = results.map(function (p, i) {
        var low = (parseInt(p.stok, 10) <= 0) ? ' low' : '';
        return '<div class="part-search-item" data-idx="' + i + '" data-testid="purchase-suggest-' + p.id + '">' +
          '<span class="kode">' + esc(p.kode) + '</span><span class="nama">' + esc(p.nama) + '</span>' +
          ' <span class="stok' + low + '">· stok: ' + p.stok + '</span></div>';
      }).join('');
    }
    listEl.classList.add('is-open');
  }

  function loadRak(tr, partId) {
    var cell = tr.querySelector('[data-role="rak-cell"]');
    fetch('ajax/rak.php?action=list&part_id=' + partId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.part || parseInt(d.part.multi_rak_aktif, 10) !== 1 || !d.racks) return;
        var sel = '<select name="rak[]" class="form-select form-select-sm">' + d.racks.map(function (r) {
          var val = (parseInt(r.is_utama, 10) === 1) ? '__UTAMA__' : r.nama_rak;
          return '<option value="' + esc(val) + '">' + esc(r.nama_rak) + (parseInt(r.is_utama, 10) === 1 ? ' (Utama)' : '') + ' · ' + r.stok + '</option>';
        }).join('') + '</select>';
        cell.innerHTML = sel;
      }).catch(function () {});
  }

  function addPart(p) {
    var existing = body.querySelector('tr[data-part="' + p.id + '"]');
    if (existing) {
      var q = existing.querySelector('[name="qty[]"]');
      q.value = (parseInt(q.value, 10) || 0) + 1;
      recalc(); q.focus(); q.select(); return;
    }
    var tr = document.createElement('tr');
    tr.setAttribute('data-part', p.id);
    tr.innerHTML =
      '<td><input type="hidden" name="part_id[]" value="' + p.id + '"><span class="part-search-item-kode kode" style="font-family:ui-monospace,monospace;font-size:.75rem;background:#eef2f7;padding:1px 6px;border-radius:4px">' + esc(p.kode) + '</span> ' + esc(p.nama) + '</td>' +
      '<td class="text-end text-muted small">' + p.stok + '</td>' +
      '<td><input type="number" name="qty[]" min="1" step="1" value="1" class="form-control form-control-sm" data-testid="purchase-qty-' + p.id + '"></td>' +
      '<td><input type="number" name="harga_beli[]" min="0" step="any" value="' + (p.harga_beli ? parseFloat(p.harga_beli) : 0) + '" class="form-control form-control-sm" data-testid="purchase-harga-' + p.id + '"></td>' +
      '<td class="text-end small" data-role="sub">Rp 0</td>' +
      '<td data-role="rak-cell"><input type="hidden" name="rak[]" value=""><span class="text-muted small">-</span></td>' +
      '<td><button type="button" class="btn-close" aria-label="Hapus baris" data-role="remove" data-testid="purchase-remove-' + p.id + '"></button></td>';
    body.appendChild(tr);
    loadRak(tr, p.id);
    recalc();
    var q2 = tr.querySelector('[name="qty[]"]'); q2.focus(); q2.select();
  }

  function pick(i) {
    var p = results[i]; if (!p) return;
    addPart(p);
    input.value = ''; closeList();
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.trim();
    if (q === '') { closeList(); return; }
    timer = setTimeout(function () {
      fetch('ajax/lookup.php?action=search_parts&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(render).catch(closeList);
    }, 220);
  });
  input.addEventListener('keydown', function (e) {
    var items = listEl.querySelectorAll('.part-search-item');
    if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); activeIdx = (activeIdx + 1) % items.length; }
    else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); activeIdx = (activeIdx - 1 + items.length) % items.length; }
    else if (e.key === 'Enter') { e.preventDefault(); if (activeIdx >= 0) pick(activeIdx); else if (items.length === 1) pick(0); return; }
    else if (e.key === 'Escape') { closeList(); return; }
    else return;
    items.forEach(function (el, i) { el.classList.toggle('is-active', i === activeIdx); });
  });
  listEl.addEventListener('mousedown', function (e) {
    var it = e.target.closest('.part-search-item'); if (!it) return;
    e.preventDefault(); pick(parseInt(it.getAttribute('data-idx'), 10));
  });
  document.addEventListener('click', function (e) { if (!form.contains(e.target)) closeList(); });

  body.addEventListener('input', recalc);
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-role="remove"]'); if (!b) return;
    b.closest('tr').remove(); recalc();
  });
  // Enter di kolom angka tidak boleh langsung menyimpan faktur.
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
  });
  form.addEventListener('submit', function (e) {
    var rows = body.querySelectorAll('tr[data-part]');
    if (!rows.length) { e.preventDefault(); alert('Tambahkan minimal satu sparepart ke daftar.'); input.focus(); return; }
    var bad = false;
    rows.forEach(function (tr) { if (!(parseInt(tr.querySelector('[name="qty[]"]').value, 10) >= 1)) bad = true; });
    if (bad) { e.preventDefault(); alert('Jumlah setiap sparepart minimal 1.'); }
  });
  recalc();
})();
</script>
