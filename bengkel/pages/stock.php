<?php
$db = db();
$action = $_POST['action'] ?? '';

// ---- Transaksi barang masuk (dari supplier) ----
if ($action === 'masuk') {
    $part_id = (int)$_POST['part_id'];
    $jumlah = max(1, (int)$_POST['jumlah']);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $ket = trim($_POST['keterangan'] ?? '');
    $cek = $db->prepare("SELECT id FROM parts WHERE id=?");
    $cek->execute([$part_id]);
    if (!$cek->fetchColumn()) {
        set_flash('danger', 'Sparepart tidak ditemukan. Pilih sparepart dari hasil pencarian.');
        header('Location: index.php?page=stock'); exit;
    }
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$jumlah, $part_id]);
        $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, supplier_id, ref_type, keterangan) VALUES (?,?,?,?,?,?)")
           ->execute([$part_id, 'masuk', $jumlah, $supplier_id, 'manual', $ket]);
        $db->commit();
        set_flash('success', "Stok bertambah $jumlah unit.");
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Gagal mencatat barang masuk: ' . $e->getMessage());
    }
    header('Location: index.php?page=stock'); exit;
}

// ---- Transaksi barang keluar (manual / penyesuaian) ----
if ($action === 'keluar') {
    $part_id = (int)$_POST['part_id'];
    $jumlah = max(1, (int)$_POST['jumlah']);
    $ket = trim($_POST['keterangan'] ?? '');
    $cekStok = $db->prepare("SELECT stok FROM parts WHERE id=?");
    $cekStok->execute([$part_id]);
    $stok = (int)$cekStok->fetchColumn();
    if (!$part_id) {
        set_flash('danger', 'Sparepart tidak ditemukan. Pilih sparepart dari hasil pencarian.');
    } elseif ($jumlah > $stok) {
        set_flash('danger', "Stok tidak mencukupi (tersedia: $stok).");
    } else {
        $db->beginTransaction();
        $db->prepare("UPDATE parts SET stok = stok - ? WHERE id=?")->execute([$jumlah, $part_id]);
        $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, ref_type, keterangan) VALUES (?,?,?,?,?)")
           ->execute([$part_id, 'keluar', $jumlah, 'manual', $ket]);
        $db->commit();
        set_flash('success', "Stok berkurang $jumlah unit.");
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
$conds = []; $params = [];
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
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>Barang Masuk (dari Supplier)</h2>
      <form method="post" data-testid="stock-in-form" data-part-form="in">
        <input type="hidden" name="action" value="masuk">
        <input type="hidden" name="part_id" value="" data-testid="stock-in-part-id" data-role="part-id" required>
        <div class="mb-2 part-search-wrap">
          <label class="form-label small">Cari Sparepart (kode / nama)</label>
          <input type="text" class="form-control form-control-sm" autocomplete="off"
                 placeholder="Ketik kode part, barcode, atau nama..."
                 data-testid="stock-in-part-search" data-role="part-search">
          <div class="part-search-list" data-role="part-list"></div>
          <div class="form-text small text-muted">Pilih dari hasil pencarian sebelum menekan Catat.</div>
        </div>
        <div class="mb-2 part-selected-card" data-role="part-selected" data-testid="stock-in-part-selected">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div><strong data-role="sel-kode"></strong> — <span data-role="sel-nama"></span></div>
              <div class="small text-muted">Kategori: <span data-role="sel-kategori">-</span> · Stok saat ini: <span data-role="sel-stok">0</span></div>
            </div>
            <button type="button" class="clear-btn" data-role="clear-part" data-testid="stock-in-part-clear">Ganti</button>
          </div>
        </div>
        <div class="mb-2"><label class="form-label small">Jumlah Masuk</label>
          <input name="jumlah" type="number" min="1" value="1" class="form-control form-control-sm" required data-testid="stock-in-jumlah"></div>
        <div class="mb-2"><label class="form-label small">Supplier</label>
          <select name="supplier_id" class="form-select form-select-sm" data-testid="stock-in-supplier">
            <option value="">- Pilih supplier -</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= esc($s['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label small">Keterangan</label>
          <input name="keterangan" class="form-control form-control-sm" placeholder="No. faktur supplier, dll." data-testid="stock-in-keterangan"></div>
        <button class="btn btn-sm btn-success w-100" data-testid="stock-in-submit">Catat Barang Masuk</button>
      </form>
    </div></div>

    <div class="card table-card mt-3"><div class="card-body">
      <h2 class="h6 text-danger"><i class="bi bi-box-arrow-up me-1"></i>Barang Keluar (Manual)</h2>
      <form method="post" data-testid="stock-out-form" data-part-form="out">
        <input type="hidden" name="action" value="keluar">
        <input type="hidden" name="part_id" value="" data-testid="stock-out-part-id" data-role="part-id" required>
        <div class="mb-2 part-search-wrap">
          <label class="form-label small">Cari Sparepart (kode / nama)</label>
          <input type="text" class="form-control form-control-sm" autocomplete="off"
                 placeholder="Ketik kode part, barcode, atau nama..."
                 data-testid="stock-out-part-search" data-role="part-search">
          <div class="part-search-list" data-role="part-list"></div>
        </div>
        <div class="mb-2 part-selected-card" data-role="part-selected" data-testid="stock-out-part-selected">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div><strong data-role="sel-kode"></strong> — <span data-role="sel-nama"></span></div>
              <div class="small text-muted">Kategori: <span data-role="sel-kategori">-</span> · Stok saat ini: <span data-role="sel-stok">0</span></div>
            </div>
            <button type="button" class="clear-btn" data-role="clear-part" data-testid="stock-out-part-clear">Ganti</button>
          </div>
        </div>
        <div class="mb-2"><label class="form-label small">Jumlah Keluar</label>
          <input name="jumlah" type="number" min="1" value="1" class="form-control form-control-sm" required data-testid="stock-out-jumlah"></div>
        <div class="mb-3"><label class="form-label small">Keterangan</label>
          <input name="keterangan" class="form-control form-control-sm" placeholder="Rusak, retur, dipakai internal..." data-testid="stock-out-keterangan"></div>
        <button class="btn btn-sm btn-danger w-100" data-testid="stock-out-submit">Catat Barang Keluar</button>
      </form>
      <p class="small text-muted mt-2 mb-0">Catatan: penjualan via Kasir & penggantian garansi mengurangi stok secara otomatis.</p>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h2 class="h6 mb-0">Riwayat Pergerakan Stok</h2>
        <form class="d-flex gap-1 flex-wrap align-items-center" method="get" action="export.php" target="_blank" data-testid="stock-export-form">
          <input type="hidden" name="type" value="stock">
          <select name="jenis" class="form-select form-select-sm" style="width:auto" data-testid="stock-export-jenis">
            <option value="semua">Semua</option>
            <option value="masuk">Stok Masuk</option>
            <option value="keluar">Stok Keluar</option>
            <option value="penjualan">Penjualan</option>
            <option value="garansi">Garansi</option>
          </select>
          <input type="date" name="dari" class="form-control form-control-sm" style="width:auto" value="<?= date('Y-m-01') ?>" data-testid="stock-export-dari">
          <input type="date" name="sampai" class="form-control form-control-sm" style="width:auto" value="<?= date('Y-m-d') ?>" data-testid="stock-export-sampai">
          <button name="format" value="pdf" class="btn btn-sm btn-outline-danger" title="Unduh PDF" data-testid="stock-export-pdf"><i class="bi bi-file-earmark-pdf"></i></button>
          <button name="format" value="xls" class="btn btn-sm btn-outline-success" title="Unduh Excel" data-testid="stock-export-xls"><i class="bi bi-file-earmark-excel"></i></button>
          <button name="format" value="doc" class="btn btn-sm btn-outline-primary" title="Unduh Word" data-testid="stock-export-doc"><i class="bi bi-file-earmark-word"></i></button>
        </form>
      </div>
      <form class="row g-2 align-items-end mb-3" method="get" data-testid="stock-search-form">
        <input type="hidden" name="page" value="stock">
        <div class="col-sm-5">
          <label class="form-label small mb-1">Cari Kode / Nama Part</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control form-control-sm" placeholder="Ketik kode part atau nama sparepart..." data-testid="stock-search-q">
        </div>
        <div class="col-sm-3">
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
        <div class="col-sm-2">
          <label class="form-label small mb-1">Tampil / halaman</label>
          <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="stock-search-perpage">
            <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?> data</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary" data-testid="stock-search-btn"><i class="bi bi-search me-1"></i>Cari</button>
          <?php if ($q !== '' || $jenis !== 'semua'): ?><a href="index.php?page=stock" class="btn btn-sm btn-outline-secondary" data-testid="stock-search-reset">Reset</a><?php endif; ?>
        </div>
      </form>
      <?php $jenisLbl = ['semua'=>'Semua','masuk'=>'Stok Masuk','keluar'=>'Stok Keluar','penjualan'=>'Penjualan','garansi'=>'Garansi'][$jenis]; ?>
      <div class="small text-muted mb-2" data-testid="stock-log-count">Menampilkan <?= count($log) ?> dari <?= $totalRows ?> data<?= $q !== '' ? ' (cari: "' . esc($q) . '")' : '' ?><?= $jenis !== 'semua' ? ' (jenis: ' . esc($jenisLbl) . ')' : '' ?>.</div>
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
              <?php if ($l['ref_type'] && $l['ref_type'] !== 'manual'): ?><span class="badge bg-secondary"><?= esc($l['ref_type']) ?></span><?php endif; ?></td>
            <td class="text-end"><?= $l['jumlah'] ?></td>
            <td><?= esc($l['supplier_nama'] ?? '-') ?></td>
            <td class="small text-muted"><?= esc($l['keterangan']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($totalPages > 1): $qs = 'page=stock&q=' . urlencode($q) . '&jenis=' . urlencode($jenis) . '&per_page=' . $per; ?>
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
  // Pencarian sparepart (autocomplete) untuk form Barang Masuk & Keluar.
  // Endpoint: ajax/lookup.php?action=search_parts&q=...
  var forms = document.querySelectorAll('[data-part-form]');
  forms.forEach(function (form) {
    var searchInput = form.querySelector('[data-role="part-search"]');
    var listEl      = form.querySelector('[data-role="part-list"]');
    var hiddenId    = form.querySelector('[data-role="part-id"]');
    var selectedBox = form.querySelector('[data-role="part-selected"]');
    var selKode     = form.querySelector('[data-role="sel-kode"]');
    var selNama     = form.querySelector('[data-role="sel-nama"]');
    var selKategori = form.querySelector('[data-role="sel-kategori"]');
    var selStok     = form.querySelector('[data-role="sel-stok"]');
    var clearBtn    = form.querySelector('[data-role="clear-part"]');
    var jumlahInput = form.querySelector('[name="jumlah"]');
    var isOut       = form.getAttribute('data-part-form') === 'out';

    var debounceTimer = null;
    var activeIdx = -1;
    var results = [];

    function escapeHtml(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function render(items) {
      results = items || [];
      activeIdx = -1;
      if (!results.length) {
        listEl.innerHTML = '<div class="part-search-empty">Tidak ada sparepart cocok. Coba kata kunci lain.</div>';
        listEl.classList.add('is-open');
        return;
      }
      var html = results.map(function (p, i) {
        var lowClass = (parseInt(p.stok, 10) <= 0) ? ' low' : '';
        return '<div class="part-search-item" data-idx="' + i + '" data-testid="part-suggest-' + p.id + '">' +
                 '<span class="kode">' + escapeHtml(p.kode) + '</span>' +
                 '<span class="nama">' + escapeHtml(p.nama) + '</span>' +
                 ' <span class="stok' + lowClass + '">· stok: ' + p.stok + '</span>' +
               '</div>';
      }).join('');
      listEl.innerHTML = html;
      listEl.classList.add('is-open');
    }

    function closeList() { listEl.classList.remove('is-open'); activeIdx = -1; }

    function pick(i) {
      var p = results[i];
      if (!p) return;
      hiddenId.value = p.id;
      selKode.textContent = p.kode;
      selNama.textContent = p.nama;
      selKategori.textContent = p.kategori || '-';
      selStok.textContent = p.stok;
      selectedBox.classList.add('is-visible');
      searchInput.value = p.kode + ' - ' + p.nama;
      searchInput.classList.remove('is-invalid');
      closeList();
      if (isOut && jumlahInput) { jumlahInput.max = p.stok; }
      if (jumlahInput) { jumlahInput.focus(); jumlahInput.select(); }
    }

    function fetchSuggestions(q) {
      fetch('ajax/lookup.php?action=search_parts&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { render(data); })
        .catch(function () { render([]); });
    }

    searchInput.addEventListener('input', function () {
      if (hiddenId.value) { hiddenId.value = ''; selectedBox.classList.remove('is-visible'); }
      var q = searchInput.value.trim();
      clearTimeout(debounceTimer);
      if (q.length < 1) { closeList(); return; }
      debounceTimer = setTimeout(function () { fetchSuggestions(q); }, 180);
    });

    searchInput.addEventListener('focus', function () {
      if (results.length && !hiddenId.value) listEl.classList.add('is-open');
    });

    searchInput.addEventListener('keydown', function (e) {
      var items = listEl.querySelectorAll('.part-search-item');
      if (!items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(items.length - 1, activeIdx + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(0, activeIdx - 1); }
      else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pick(activeIdx); } return; }
      else if (e.key === 'Escape') { closeList(); return; }
      else { return; }
      items.forEach(function (el, idx) { el.classList.toggle('is-active', idx === activeIdx); });
      var active = items[activeIdx];
      if (active) active.scrollIntoView({ block: 'nearest' });
    });

    listEl.addEventListener('mousedown', function (e) {
      var target = e.target.closest('.part-search-item');
      if (!target) return;
      e.preventDefault();
      pick(parseInt(target.getAttribute('data-idx'), 10));
    });

    document.addEventListener('click', function (e) { if (!form.contains(e.target)) closeList(); });

    clearBtn.addEventListener('click', function () {
      hiddenId.value = '';
      selectedBox.classList.remove('is-visible');
      searchInput.value = '';
      results = [];
      listEl.innerHTML = '';
      searchInput.focus();
      if (jumlahInput) jumlahInput.removeAttribute('max');
    });

    form.addEventListener('submit', function (e) {
      if (!hiddenId.value) {
        e.preventDefault();
        searchInput.focus();
        searchInput.classList.add('is-invalid');
        alert('Silakan cari dan pilih sparepart terlebih dahulu.');
      } else {
        searchInput.classList.remove('is-invalid');
      }
    });
  });
})();
</script>
