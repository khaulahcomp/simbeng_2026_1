<?php
// ============================================================
// Lengkapi Harga & Stok Massal — mengisi harga beli/jual + stok
// untuk sparepart sekaligus (khususnya hasil SYNC yang harga/stoknya 0).
// ============================================================
$db = db();

if (($_POST['action'] ?? '') === 'import_prices') {
    $rows = json_decode($_POST['rows_json'] ?? '[]', true) ?: [];

    // Normalisasi angka format Indonesia: "15.000", "Rp 15.000", "15.000,50", "1500".
    $toNum = function ($v) {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = preg_replace('/[^0-9.,\-]/', '', $s); // buang "Rp", spasi, dll
        if ($s === '' || $s === '-') return null;
        if (strpos($s, ',') !== false) {
            // koma sebagai desimal -> titik (ribuan) dibuang, koma jadi titik
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // hanya titik -> anggap pemisah ribuan (harga bulat), buang titik
            $s = str_replace('.', '', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    };

    $chk = $db->prepare("SELECT harga_beli, harga_jual, stok, stok_min FROM parts WHERE kode = ?");
    $upd = $db->prepare("UPDATE parts SET harga_beli=?, harga_jual=?, stok=?, stok_min=? WHERE kode=?");
    $matched = 0; $notfound = 0; $skipped = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        // Normalisasi nama kolom: buang BOM, rapikan spasi -> underscore, lowercase.
        $map = [];
        foreach ($r as $k => $v) {
            $nk = str_replace("\xEF\xBB\xBF", '', (string)$k);      // BOM UTF-8
            $nk = strtolower(trim($nk));
            $nk = preg_replace('/\s+/', '_', $nk);
            $map[$nk] = $v;
        }
        $kodeRaw = $map['kode'] ?? $map['kode_sparepart'] ?? $map['kode_part'] ?? '';
        $kode = strtoupper(trim((string)$kodeRaw));
        if ($kode === '') { $skipped++; continue; }
        $chk->execute([$kode]);
        $cur = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$cur) { $notfound++; continue; }

        $hbN = array_key_exists('harga_beli', $map) ? $toNum($map['harga_beli']) : null;
        $hjN = array_key_exists('harga_jual', $map) ? $toNum($map['harga_jual']) : null;
        $skN = array_key_exists('stok', $map) ? $toNum($map['stok']) : null;
        $smN = array_key_exists('stok_min', $map) ? $toNum($map['stok_min']) : null;

        $hb = $hbN !== null ? $hbN : (float)$cur['harga_beli'];
        $hj = $hjN !== null ? $hjN : (float)$cur['harga_jual'];
        $sk = $skN !== null ? (int)$skN : (int)$cur['stok'];
        $sm = $smN !== null ? (int)$smN : (int)$cur['stok_min'];
        $upd->execute([$hb, $hj, $sk, $sm, $kode]);
        $matched++;
    }
    $msg = "Impor harga selesai: $matched sparepart diperbarui";
    if ($notfound) $msg .= ", $notfound kode tidak ditemukan";
    if ($skipped) $msg .= ", $skipped baris tanpa kode dilewati";
    set_flash($matched > 0 ? 'success' : 'warning', $msg . '.');
    header('Location: index.php?page=parts_bulk'); exit;
}

if (($_POST['action'] ?? '') === 'save_bulk') {
    $ids = $_POST['id'] ?? [];
    $hb = $_POST['harga_beli'] ?? [];
    $hj = $_POST['harga_jual'] ?? [];
    $sk = $_POST['stok'] ?? [];
    $sm = $_POST['stok_min'] ?? [];
    $upd = $db->prepare("UPDATE parts SET harga_beli=?, harga_jual=?, stok=?, stok_min=? WHERE id=?");
    $n = 0;
    foreach ($ids as $i => $id) {
        $upd->execute([
            (float)($hb[$i] ?? 0), (float)($hj[$i] ?? 0),
            (int)($sk[$i] ?? 0), (int)($sm[$i] ?? 5), (int)$id,
        ]);
        $n++;
    }
    set_flash('success', "$n sparepart berhasil diperbarui.");
    $keep = array_intersect_key($_GET, array_flip(['q', 'filter', 'per_page', 'p']));
    header('Location: index.php?page=parts_bulk' . ($keep ? '&' . http_build_query($keep) : '')); exit;
}

$q = trim($_GET['q'] ?? '');
$filter = ($_GET['filter'] ?? 'incomplete') === 'all' ? 'all' : 'incomplete';
$allowedPer = [25, 50, 100, 200];
$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, $allowedPer, true)) $per = 50;

$where = []; $params = [];
if ($q !== '') { $where[] = "(kode LIKE ? OR nama LIKE ? OR barcode LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }
if ($filter === 'incomplete') $where[] = "(harga_jual = 0 OR harga_beli = 0)";
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt = $db->prepare("SELECT COUNT(*) FROM parts $wsql"); $cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $per));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $per;

$st = $db->prepare("SELECT * FROM parts $wsql ORDER BY (harga_jual=0) DESC, kategori, nama LIMIT $per OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$incompleteTotal = (int)$db->query("SELECT COUNT(*) FROM parts WHERE harga_jual=0 OR harga_beli=0")->fetchColumn();
$qs = 'page=parts_bulk&q=' . urlencode($q) . '&filter=' . urlencode($filter) . '&per_page=' . $per;
?>
<div class="alert alert-<?= $incompleteTotal ? 'warning' : 'success' ?> d-flex align-items-center" data-testid="bulk-info">
  <i class="bi bi-info-circle-fill me-2"></i>
  <?php if ($incompleteTotal): ?>
    Ada <strong><?= $incompleteTotal ?></strong> sparepart yang harga beli / jualnya masih 0 (mis. hasil sync). Lengkapi di bawah lalu klik Simpan.
  <?php else: ?>
    Semua sparepart sudah memiliki harga. Anda tetap dapat memperbarui harga & stok di sini.
  <?php endif; ?>
</div>

<div class="card table-card mb-3" data-testid="import-excel-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h2 class="h6 mb-0"><i class="bi bi-file-earmark-excel me-1 text-success"></i>Impor Harga & Stok dari Excel/CSV</h2>
    <a href="export.php?type=template&format=xls" class="btn btn-sm btn-outline-success" data-testid="download-template-btn"><i class="bi bi-download me-1"></i>Unduh Template (siap isi)</a>
  </div>
  <p class="small text-muted mb-2">Unggah file Excel/CSV berisi kolom <code>kode</code>, <code>harga_beli</code>, <code>harga_jual</code>, <code>stok</code>, <code>stok_min</code>. Data dicocokkan berdasarkan <strong>kode</strong> (yang sudah ada akan diperbarui; kolom kosong dibiarkan).</p>
  <form method="post" id="importForm" data-testid="import-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_prices">
    <input type="hidden" name="rows_json" id="rowsJson">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv" class="form-control form-control-sm" style="max-width:320px" data-testid="import-file">
      <span id="importPreview" class="small text-muted" data-testid="import-preview"></span>
      <button type="submit" id="importSubmit" class="btn btn-sm btn-success" disabled data-testid="import-submit"><i class="bi bi-upload me-1"></i>Impor ke Database</button>
    </div>
  </form>
</div></div>

<div class="card table-card"><div class="card-body">
  <form class="row g-2 align-items-end mb-3" method="get" data-testid="bulk-filter-form">
    <input type="hidden" name="page" value="parts_bulk">
    <div class="col-sm-5">
      <label class="form-label small mb-1">Cari Kode / Nama</label>
      <input type="text" name="q" value="<?= esc($q) ?>" class="form-control form-control-sm" placeholder="Ketik kode atau nama sparepart..." data-testid="bulk-q">
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Tampilkan</label>
      <select name="filter" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="bulk-filter">
        <option value="incomplete" <?= $filter==='incomplete'?'selected':'' ?>>Belum lengkap (harga 0)</option>
        <option value="all" <?= $filter==='all'?'selected':'' ?>>Semua sparepart</option>
      </select>
    </div>
    <div class="col-sm-2">
      <label class="form-label small mb-1">Baris / halaman</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="bulk-perpage">
        <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2 d-flex gap-1">
      <button class="btn btn-sm btn-primary" data-testid="bulk-search-btn"><i class="bi bi-search me-1"></i>Cari</button>
    </div>
  </form>

  <form method="post" data-testid="bulk-save-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_bulk">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <span class="small text-muted">Menampilkan <?= count($rows) ?> dari <?= $total ?> sparepart<?= $q!==''?' (filter: "'.esc($q).'")':'' ?>.</span>
      <button class="btn btn-sm btn-success" data-testid="bulk-save-top"><i class="bi bi-save me-1"></i>Simpan Perubahan</button>
    </div>
    <div class="table-responsive">
    <table class="table table-sm align-middle" data-testid="bulk-table">
      <thead><tr>
        <th>Kode</th><th>Nama</th><th>Kategori</th>
        <th style="width:130px">Harga Beli</th><th style="width:130px">Harga Jual</th>
        <th style="width:90px">Stok</th><th style="width:90px">Stok Min</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Tidak ada sparepart.</td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr data-testid="bulk-row-<?= $r['id'] ?>">
          <td class="small"><input type="hidden" name="id[<?= $i ?>]" value="<?= $r['id'] ?>"><code><?= esc($r['kode']) ?></code></td>
          <td class="small"><?= esc($r['nama']) ?></td>
          <td class="small"><?= esc($r['kategori'] ?: '-') ?></td>
          <td><input type="number" min="0" step="100" name="harga_beli[<?= $i ?>]" value="<?= (int)$r['harga_beli'] ?>" class="form-control form-control-sm text-end" data-testid="bulk-hb-<?= $r['id'] ?>"></td>
          <td><input type="number" min="0" step="100" name="harga_jual[<?= $i ?>]" value="<?= (int)$r['harga_jual'] ?>" class="form-control form-control-sm text-end <?= (int)$r['harga_jual']===0?'border-warning':'' ?>" data-testid="bulk-hj-<?= $r['id'] ?>"></td>
          <td><input type="number" min="0" name="stok[<?= $i ?>]" value="<?= (int)$r['stok'] ?>" class="form-control form-control-sm text-end" data-testid="bulk-stok-<?= $r['id'] ?>"></td>
          <td><input type="number" min="0" name="stok_min[<?= $i ?>]" value="<?= (int)$r['stok_min'] ?>" class="form-control form-control-sm text-end" data-testid="bulk-min-<?= $r['id'] ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
      <?php if ($totalPages > 1): ?>
      <nav class="d-flex gap-2 align-items-center" data-testid="bulk-pagination">
        <a class="btn btn-sm btn-outline-secondary <?= $pageNum<=1?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum-1 ?>" data-testid="bulk-prev">&laquo; Sebelumnya</a>
        <span class="small text-muted">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $pageNum>=$totalPages?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum+1 ?>" data-testid="bulk-next">Berikutnya &raquo;</a>
      </nav>
      <?php else: ?><span></span><?php endif; ?>
      <button class="btn btn-sm btn-success" data-testid="bulk-save-bottom"><i class="bi bi-save me-1"></i>Simpan Perubahan</button>
    </div>
    <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Perubahan disimpan per halaman. Gunakan pagination untuk melengkapi halaman berikutnya.</p>
  </form>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
  var fileInput = document.getElementById('importFile');
  var rowsJson = document.getElementById('rowsJson');
  var preview = document.getElementById('importPreview');
  var submit = document.getElementById('importSubmit');
  if (!fileInput) return;

  function fail(msg) { preview.innerHTML = '<span class="text-danger">' + msg + '</span>'; submit.disabled = true; rowsJson.value = ''; }
  function normKey(s) { return String(s == null ? '' : s).replace(/\uFEFF/g, '').replace(/["']/g, '').trim().toLowerCase().replace(/\s+/g, '_'); }
  function isKodeKey(k) { k = normKey(k); return k === 'kode' || k === 'kode_sparepart' || k === 'kode_part' || k === 'kode_barang'; }

  // Ubah sheet -> array objek. Cari baris header yang memuat kolom "kode"
  // secara otomatis (toleran terhadap baris judul/kosong di atas header).
  function rowsFromSheet(sheet) {
    var aoa = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', blankrows: false, raw: false });
    if (!aoa.length) return { rows: [], headers: [] };
    var hIdx = -1;
    for (var i = 0; i < Math.min(aoa.length, 25); i++) {
      var row = aoa[i] || [];
      if (row.some(function (c) { return isKodeKey(c); })) { hIdx = i; break; }
    }
    if (hIdx < 0) return { rows: [], headers: (aoa[0] || []).map(String) };
    var headers = aoa[hIdx].map(normKey);
    var out = [];
    for (var r = hIdx + 1; r < aoa.length; r++) {
      var arr = aoa[r] || [];
      var o = {}, hasKode = false;
      for (var c = 0; c < headers.length; c++) {
        var h = headers[c];
        if (!h) continue;
        var val = (arr[c] !== undefined && arr[c] !== null) ? arr[c] : '';
        o[h] = val;
        if (isKodeKey(h) && String(val).trim() !== '') hasKode = true;
      }
      if (hasKode) out.push(o);
    }
    return { rows: out, headers: headers };
  }

  function finalize(res) {
    if (res.rows && res.rows.length) {
      rowsJson.value = JSON.stringify(res.rows);
      preview.innerHTML = '<span class="text-success">' + res.rows.length + ' baris siap diimpor.</span>';
      submit.disabled = false;
      return true;
    }
    return false;
  }

  function csvToRows(text) {
    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1); // buang BOM
    var firstLine = (text.split(/\r?\n/).find(function (l) { return l.trim() !== ''; }) || '');
    var counts = { ';': (firstLine.match(/;/g) || []).length, ',': (firstLine.match(/,/g) || []).length,
                   '\t': (firstLine.match(/\t/g) || []).length, '|': (firstLine.match(/\|/g) || []).length };
    // Urutkan pemisah dari yang paling sering muncul; coba satu per satu.
    var order = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; });
    order.push(',', ';', '\t', '|'); // jaring pengaman
    var tried = {};
    for (var i = 0; i < order.length; i++) {
      var sep = order[i];
      if (tried[sep]) continue; tried[sep] = true;
      try {
        var wb = XLSX.read(text, { type: 'string', raw: false, FS: sep });
        var res = rowsFromSheet(wb.Sheets[wb.SheetNames[0]]);
        if (res.rows.length) return res;
      } catch (e) { /* coba pemisah berikutnya */ }
    }
    return { rows: [], headers: [] };
  }

  function reportEmpty(headers) {
    var found = (headers && headers.length) ? headers.filter(function (h) { return String(h).trim() !== ''; }).join(', ') : '(tidak terbaca)';
    fail('File terbaca, tetapi kolom "kode" tidak ditemukan. Header yang terbaca: ' + found + '. Pastikan ada kolom bernama kode (boleh juga harga_beli, harga_jual, stok, stok_min).');
  }

  fileInput.addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (!file) return;
    preview.textContent = 'Membaca file...';
    submit.disabled = true;
    var name = (file.name || '').toLowerCase();
    var isCsv = name.endsWith('.csv') || name.endsWith('.txt') || file.type === 'text/csv' || file.type === 'text/plain';
    var reader = new FileReader();
    if (isCsv) {
      reader.onload = function (ev) {
        try {
          var res = csvToRows(String(ev.target.result || ''));
          if (!finalize(res)) reportEmpty(res.headers);
        } catch (err) { fail('Gagal membaca CSV: ' + err.message); }
      };
      reader.onerror = function () { fail('Gagal membuka file.'); };
      reader.readAsText(file, 'UTF-8');
    } else {
      reader.onload = function (ev) {
        try {
          var wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
          var res = rowsFromSheet(wb.Sheets[wb.SheetNames[0]]);
          if (!finalize(res)) reportEmpty(res.headers);
        } catch (err) { fail('Gagal membaca file: ' + err.message); }
      };
      reader.onerror = function () { fail('Gagal membuka file.'); };
      reader.readAsArrayBuffer(file);
    }
  });
})();
</script>
