<?php
// ============================================================
// po_tab.php - Tab "Purchase Order" di halaman Supplier (KHUSUS ADMIN).
// Di-include oleh pages/suppliers.php saat ?tab=po atau action po_*.
//   - Riwayat PO (filter, status, detail + jejak penerimaan)
//   - Buat / edit PO (part dari database Sparepart atau input manual)
//   - Hapus PO (hanya bila belum ada penerimaan), Tutup / Buka lagi PO
//   - Terima Barang -> diteruskan ke form Stok Masuk (page=stock&from_po=ID)
//   - Langkah pencocokan part manual sebelum diterima (?tab=po&link=ID)
// ============================================================
require_admin();
require_once __DIR__ . '/purchase_order.php';
$db = db();
po_ensure_schema($db);
$action = $_POST['action'] ?? '';
$uname = (string)(current_user()['nama'] ?? '');
const PO_MAX_ROWS = 200;

// ---------------- Aksi (POST, pola PRG) ----------------
if ($action === 'po_save') {
    $id = (int)($_POST['po_id'] ?? 0);
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $tgl = _valid_date($_POST['tgl'] ?? '') ? $_POST['tgl'] : date('Y-m-d');
    $catatan = mb_substr(trim((string)($_POST['catatan'] ?? '')), 0, 500);
    $after = ($_POST['after'] ?? '') === 'print' ? 'print' : 'list';
    $back = 'index.php?page=suppliers&tab=po&' . ($id ? 'edit=' . $id : 'new=1');

    $sp = $db->prepare("SELECT id FROM suppliers WHERE id=?"); $sp->execute([$supplier_id]);
    if (!$sp->fetchColumn()) { set_flash('danger', 'Pilih supplier terlebih dahulu.'); header('Location: ' . $back); exit; }

    // Rakit baris: part dari database (kode & nama diambil ulang dari DB) atau manual.
    $pids = (array)($_POST['item_part_id'] ?? []); $kodes = (array)($_POST['item_kode'] ?? []);
    $namas = (array)($_POST['item_nama'] ?? []); $qtys = (array)($_POST['item_qty'] ?? []);
    $cats = (array)($_POST['item_catatan'] ?? []);
    $pq = $db->prepare("SELECT id, kode, nama FROM parts WHERE id=?");
    $lines = []; $byPart = [];
    foreach ($qtys as $i => $q) {
        $qty = (int)$q; if ($qty <= 0) continue;
        $pid = (int)($pids[$i] ?? 0);
        $cat = mb_substr(trim((string)($cats[$i] ?? '')), 0, 255);
        if ($pid > 0) {
            $pq->execute([$pid]); $p = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;
            if (isset($byPart[$pid])) { // part sama dua kali -> gabungkan qty
                $lines[$byPart[$pid]]['qty'] += $qty;
                if ($cat !== '' && $lines[$byPart[$pid]]['catatan'] === '') $lines[$byPart[$pid]]['catatan'] = $cat;
                continue;
            }
            $byPart[$pid] = count($lines);
            $lines[] = ['part_id' => $pid, 'kode' => $p['kode'], 'nama' => $p['nama'], 'qty' => $qty, 'catatan' => $cat];
        } else {
            $nama = mb_substr(trim((string)($namas[$i] ?? '')), 0, 200);
            if ($nama === '') continue;
            $lines[] = ['part_id' => null, 'kode' => mb_substr(strtoupper(trim((string)($kodes[$i] ?? ''))), 0, 100), 'nama' => $nama, 'qty' => $qty, 'catatan' => $cat];
        }
    }
    if (!$lines) { set_flash('danger', 'Tambahkan minimal satu part dengan qty minimal 1.'); header('Location: ' . $back); exit; }
    if (count($lines) > PO_MAX_ROWS) { set_flash('danger', 'Maksimal ' . PO_MAX_ROWS . ' baris per PO.'); header('Location: ' . $back); exit; }

    try {
        $db->beginTransaction();
        if ($id) {
            $cur = po_get($db, $id);
            if (!$cur) throw new RuntimeException('PO tidak ditemukan.');
            $rc = $db->prepare("SELECT COUNT(*) FROM purchase_order_receipts WHERE po_id=?"); $rc->execute([$id]);
            if ((int)$rc->fetchColumn() > 0) throw new RuntimeException('PO ' . $cur['no_po'] . ' sudah ada barang diterima, tidak bisa diedit.');
            $db->prepare("UPDATE purchase_orders SET supplier_id=?, tgl=?, catatan=?, updated_at=NOW() WHERE id=?")
               ->execute([$supplier_id, $tgl, $catatan, $id]);
            $db->prepare("DELETE FROM purchase_order_items WHERE po_id=?")->execute([$id]);
            $po_id = $id; $no_po = $cur['no_po'];
        } else {
            $no_po = next_kode('PO', 'purchase_orders', 'no_po');
            $db->prepare("INSERT INTO purchase_orders (no_po, supplier_id, tgl, catatan, status, user_nama) VALUES (?,?,?,?, 'open', ?)")
               ->execute([$no_po, $supplier_id, $tgl, $catatan, $uname]);
            $po_id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare("INSERT INTO purchase_order_items (po_id, part_id, kode, nama, qty, catatan) VALUES (?,?,?,?,?,?)");
        foreach ($lines as $ln) $ins->execute([$po_id, $ln['part_id'], $ln['kode'], $ln['nama'], $ln['qty'], $ln['catatan']]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        set_flash('danger', 'Gagal menyimpan PO: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }
    set_flash('success', "Purchase Order $no_po tersimpan (" . count($lines) . ' item).');
    if ($after === 'print') { header('Location: index.php?page=po_print&id=' . $po_id); exit; }
    header('Location: index.php?page=suppliers&tab=po&open=' . $po_id); exit;
}

if ($action === 'po_delete') {
    $id = (int)($_POST['po_id'] ?? 0);
    $cur = po_get($db, $id);
    $rc = $db->prepare("SELECT COUNT(*) FROM purchase_order_receipts WHERE po_id=?"); $rc->execute([$id]);
    if (!$cur) set_flash('danger', 'PO tidak ditemukan.');
    elseif ((int)$rc->fetchColumn() > 0) set_flash('danger', 'PO ' . $cur['no_po'] . ' sudah ada barang diterima, tidak bisa dihapus. Gunakan "Tutup PO".');
    else {
        $db->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]); // item ikut (CASCADE)
        set_flash('success', 'PO ' . $cur['no_po'] . ' dihapus.');
    }
    header('Location: index.php?page=suppliers&tab=po'); exit;
}

if ($action === 'po_close' || $action === 'po_reopen') {
    $id = (int)($_POST['po_id'] ?? 0);
    $cur = po_get($db, $id);
    if ($cur) {
        if ($action === 'po_close') {
            $db->prepare("UPDATE purchase_orders SET status='closed', closed_at=NOW(), closed_by=? WHERE id=?")->execute([$uname, $id]);
            set_flash('success', 'PO ' . $cur['no_po'] . ' ditutup. Sisa barang yang belum datang tidak lagi ditunggu.');
        } else {
            $db->prepare("UPDATE purchase_orders SET status='open', closed_at=NULL, closed_by='' WHERE id=?")->execute([$id]);
            set_flash('success', 'PO ' . $cur['no_po'] . ' dibuka lagi.');
        }
    }
    header('Location: index.php?page=suppliers&tab=po&open=' . $id); exit;
}

// Terima Barang: bila ada part manual yang belum terhubung -> langkah pencocokan dulu.
if ($action === 'po_receive') {
    $id = (int)($_POST['po_id'] ?? 0);
    $sum = po_summary($db, $id);
    if (!$sum['po']) { set_flash('danger', 'PO tidak ditemukan.'); header('Location: index.php?page=suppliers&tab=po'); exit; }
    if ($sum['manual_open'] > 0) { header('Location: index.php?page=suppliers&tab=po&link=' . $id); exit; }
    header('Location: index.php?page=stock&from_po=' . $id); exit;
}

if ($action === 'po_link') {
    $id = (int)($_POST['po_id'] ?? 0);
    $back = 'index.php?page=suppliers&tab=po&link=' . $id;
    $sum = po_summary($db, $id);
    if (!$sum['po']) { set_flash('danger', 'PO tidak ditemukan.'); header('Location: index.php?page=suppliers&tab=po'); exit; }
    $modes = (array)($_POST['mode'] ?? []);
    try {
        $db->beginTransaction();
        foreach ($sum['items'] as $it) {
            if ($it['part_id'] || $it['sisa'] <= 0) continue;
            $iid = (int)$it['id'];
            $mode = $modes[$iid] ?? 'skip';
            if ($mode === 'existing') {
                $pid = (int)($_POST['exist_part'][$iid] ?? 0);
                $pq = $db->prepare("SELECT id, kode, nama FROM parts WHERE id=?"); $pq->execute([$pid]);
                $p = $pq->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new RuntimeException('Pilih part dari hasil pencarian untuk "' . $it['nama'] . '".');
                // Part tsb sudah ada di PO ini -> gabungkan qty ke baris itu.
                $dup = $db->prepare("SELECT id FROM purchase_order_items WHERE po_id=? AND part_id=? LIMIT 1");
                $dup->execute([$id, $pid]);
                if ($did = (int)$dup->fetchColumn()) {
                    $db->prepare("UPDATE purchase_order_items SET qty = qty + ? WHERE id=?")->execute([(int)$it['qty'], $did]);
                    $db->prepare("DELETE FROM purchase_order_items WHERE id=?")->execute([$iid]);
                } else {
                    $db->prepare("UPDATE purchase_order_items SET part_id=?, kode=?, nama=? WHERE id=?")->execute([$pid, $p['kode'], $p['nama'], $iid]);
                }
            } elseif ($mode === 'new') {
                $kode = mb_substr(strtoupper(trim((string)($_POST['new_kode'][$iid] ?? ''))), 0, 100);
                $nama = mb_substr(trim((string)($_POST['new_nama'][$iid] ?? '')), 0, 200);
                $kat  = mb_substr(trim((string)($_POST['new_kategori'][$iid] ?? '')), 0, 150);
                $hj   = max(0, (float)($_POST['new_harga_jual'][$iid] ?? 0));
                if ($kode === '' || $nama === '') throw new RuntimeException('Kode dan nama part baru wajib diisi untuk "' . $it['nama'] . '".');
                $ck = $db->prepare("SELECT id FROM parts WHERE kode=?"); $ck->execute([$kode]);
                if ($ck->fetchColumn()) throw new RuntimeException("Kode $kode sudah ada di database Sparepart. Pilih \"Pakai part yang sudah ada\".");
                if ($kat !== '') $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)")->execute([$kat]);
                // Part baru: stok 0 (stok bertambah lewat Stok Masuk), harga beli diisi saat penerimaan.
                $db->prepare("INSERT INTO parts (kode, barcode, nama, kategori, harga_beli, harga_jual, stok, lokasi_rak) VALUES (?,?,?,?,0,?,0,'')")
                   ->execute([$kode, '', $nama, $kat, $hj]);
                $pid = (int)$db->lastInsertId();
                $db->prepare("UPDATE purchase_order_items SET part_id=?, kode=?, nama=? WHERE id=?")->execute([$pid, $kode, $nama, $iid]);
            }
            // 'skip': baris tetap manual, tidak ikut penerimaan kali ini.
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        set_flash('danger', $e->getMessage());
        header('Location: ' . $back); exit;
    }
    $after = po_summary($db, $id);
    if ($after['linked_open'] === 0) {
        set_flash('warning', 'Tidak ada part yang bisa diterima. Hubungkan part manual ke database Sparepart terlebih dahulu.');
        header('Location: ' . $back); exit;
    }
    header('Location: index.php?page=stock&from_po=' . $id); exit;
}

// ---------------- Data tampilan ----------------
$allSuppliers = $db->query("SELECT id, nama FROM suppliers ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
$view = 'list';
$formPo = null; $formItems = [];
if (isset($_GET['new'])) $view = 'form';
if (isset($_GET['edit'])) {
    $formPo = po_get($db, (int)$_GET['edit']);
    if (!$formPo) { set_flash('danger', 'PO tidak ditemukan.'); header('Location: index.php?page=suppliers&tab=po'); exit; }
    $rc = $db->prepare("SELECT COUNT(*) FROM purchase_order_receipts WHERE po_id=?"); $rc->execute([$formPo['id']]);
    if ((int)$rc->fetchColumn() > 0) { set_flash('warning', 'PO ' . $formPo['no_po'] . ' sudah ada barang diterima, tidak bisa diedit.'); header('Location: index.php?page=suppliers&tab=po&open=' . $formPo['id']); exit; }
    $formItems = po_items($db, (int)$formPo['id']);
    $view = 'form';
}
$linkSum = null;
if (isset($_GET['link'])) {
    $linkSum = po_summary($db, (int)$_GET['link']);
    if (!$linkSum['po']) { set_flash('danger', 'PO tidak ditemukan.'); header('Location: index.php?page=suppliers&tab=po'); exit; }
    $view = 'link';
}

$tabNav = function (string $active) {
    echo '<ul class="nav nav-tabs mb-3" data-testid="supplier-tabs">'
       . '<li class="nav-item"><a class="nav-link' . ($active === 'sup' ? ' active' : '') . '" href="index.php?page=suppliers" data-testid="tab-supplier"><i class="bi bi-truck me-1"></i>Data Supplier &amp; Faktur Tagihan</a></li>'
       . '<li class="nav-item"><a class="nav-link' . ($active === 'po' ? ' active' : '') . '" href="index.php?page=suppliers&amp;tab=po" data-testid="tab-po"><i class="bi bi-clipboard-check me-1"></i>Purchase Order <span class="badge rounded-pill text-bg-light border small">admin</span></a></li>'
       . '</ul>';
};
$tabNav('po');

// =====================================================================
// VIEW: FORM BUAT / EDIT PO
// =====================================================================
if ($view === 'form'):
    $low = $db->query("SELECT id, kode, nama, stok, stok_min FROM parts WHERE stok_min > 0 AND stok < stok_min ORDER BY (stok_min - stok) DESC, kode LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $initItems = array_map(function ($r) {
        return ['part_id' => $r['part_id'] ? (int)$r['part_id'] : 0, 'kode' => $r['part_id'] ? (string)($r['part_kode'] ?? $r['kode']) : (string)$r['kode'],
            'nama' => $r['part_id'] ? (string)($r['part_nama'] ?? $r['nama']) : (string)$r['nama'], 'qty' => (int)$r['qty'], 'catatan' => (string)$r['catatan'],
            'stok' => $r['part_stok'] === null ? null : (int)$r['part_stok'], 'stok_min' => $r['part_stok_min'] === null ? null : (int)$r['part_stok_min']];
    }, $formItems);
    $nextNo = $formPo ? $formPo['no_po'] : next_kode('PO', 'purchase_orders', 'no_po');
?>
<style>
  .po-search-wrap { position: relative; }
  .po-search-list { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d0d5dd; border-top: none;
    border-radius: 0 0 .375rem .375rem; max-height: 280px; overflow-y: auto; z-index: 30; box-shadow: 0 8px 20px rgba(15,23,42,.12); display: none; }
  .po-search-list.is-open { display: block; }
  .po-search-item { padding: .5rem .625rem; cursor: pointer; border-bottom: 1px solid #f1f3f7; font-size: .8125rem; }
  .po-search-item:hover, .po-search-item.is-active { background: #eef4ff; }
  .po-search-item .kode, .po-kode { font-family: ui-monospace, Menlo, monospace; background: #eef2f7; padding: 1px 6px; border-radius: 4px; font-size: .72rem; margin-right: .35rem; }
  .po-search-item.manual { color: #c2410c; font-weight: 600; }
</style>
<form method="post" id="poForm" autocomplete="off" data-testid="po-form">
  <input type="hidden" name="action" value="po_save">
  <input type="hidden" name="po_id" value="<?= (int)($formPo['id'] ?? 0) ?>">
  <input type="hidden" name="after" id="poAfter" value="list">
  <div class="card table-card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h2 class="h6 mb-0"><i class="bi bi-clipboard-plus me-1"></i><?= $formPo ? 'Edit Purchase Order ' . esc($formPo['no_po']) : 'Buat Purchase Order' ?></h2>
      <a href="index.php?page=suppliers&amp;tab=po" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali ke riwayat</a>
    </div>
    <div class="row g-2">
      <div class="col-6 col-md-3"><label class="form-label small mb-1">No. PO <?= $formPo ? '' : '(otomatis)' ?></label>
        <input class="form-control form-control-sm bg-light" value="<?= esc($nextNo) ?>" readonly data-testid="po-no"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Tanggal</label>
        <input type="date" name="tgl" class="form-control form-control-sm" value="<?= esc($formPo && _valid_date($formPo['tgl']) ? $formPo['tgl'] : date('Y-m-d')) ?>" data-testid="po-tgl"></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Kepada supplier <span class="text-danger">*</span></label>
        <select name="supplier_id" class="form-select form-select-sm" required data-testid="po-supplier">
          <option value="">- Pilih supplier -</option>
          <?php foreach ($allSuppliers as $s_): ?><option value="<?= (int)$s_['id'] ?>" <?= ($formPo && (int)$formPo['supplier_id'] === (int)$s_['id']) ? 'selected' : '' ?>><?= esc($s_['nama']) ?></option><?php endforeach; ?>
        </select>
        <?php if (!$allSuppliers): ?><div class="small text-danger mt-1">Belum ada supplier. Tambahkan di tab Data Supplier.</div><?php endif; ?>
      </div>
    </div>
  </div></div>

  <div class="card table-card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h2 class="h6 mb-0">Daftar part yang dipesan</h2>
      <button type="button" class="btn btn-sm btn-outline-warning" id="poLowBtn" data-testid="po-low-btn" <?= $low ? '' : 'disabled' ?>><i class="bi bi-exclamation-triangle me-1"></i>Ambil part stok menipis (<?= count($low) ?>)</button>
    </div>
    <label class="form-label small mb-1">Cari kode part / nama / barcode dari database Sparepart</label>
    <div class="po-search-wrap mb-2">
      <input type="text" class="form-control form-control-sm" id="poSearch" placeholder="Ketik kode part atau nama, lalu pilih. Tidak ada? pilih Input manual." data-testid="po-search">
      <div class="po-search-list" id="poList"></div>
    </div>
    <div class="border border-warning-subtle rounded p-2 mb-2 bg-warning-subtle d-none" id="poManual" data-testid="po-manual-box">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Kode part (manual)</label><input class="form-control form-control-sm" id="poMK" placeholder="boleh kosong" data-testid="po-manual-kode"></div>
        <div class="col-6 col-md-5"><label class="form-label small mb-1">Nama part <span class="text-danger">*</span></label><input class="form-control form-control-sm" id="poMN" data-testid="po-manual-nama"></div>
        <div class="col-4 col-md-2"><label class="form-label small mb-1">Qty</label><input type="number" min="1" value="1" class="form-control form-control-sm" id="poMQ"></div>
        <div class="col-8 col-md-2 d-flex gap-1"><button type="button" class="btn btn-sm btn-primary flex-fill" id="poMAdd" data-testid="po-manual-add">Tambah</button><button type="button" class="btn btn-sm btn-outline-secondary" id="poMCancel">Batal</button></div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-1" data-testid="po-items">
        <thead><tr><th style="width:36px">No</th><th style="width:170px">Kode part</th><th>Nama part</th><th class="text-center" style="width:90px">Stok</th><th style="width:90px">Qty</th><th style="width:200px">Catatan</th><th style="width:36px"></th></tr></thead>
        <tbody id="poBody"><tr id="poEmpty"><td colspan="7" class="text-center text-muted small py-3">Belum ada part. Cari di kotak atas atau ambil dari stok menipis.</td></tr></tbody>
      </table>
    </div>
    <div class="small text-muted">Jumlah item: <strong id="poCountI">0</strong> &middot; Total qty: <strong id="poCountQ">0</strong> &middot; kolom Stok hanya bantuan di layar, tidak ikut tercetak.</div>
  </div></div>

  <div class="card table-card mb-3"><div class="card-body">
    <label class="form-label small mb-1">Catatan untuk supplier (ikut tercetak di BON)</label>
    <textarea name="catatan" class="form-control form-control-sm" rows="2" maxlength="500" placeholder="mis. Mohon dikirim sebelum Sabtu. Jika kosong, kabari via WA." data-testid="po-catatan"><?= esc($formPo['catatan'] ?? '') ?></textarea>
    <div class="d-flex justify-content-end flex-wrap gap-2 mt-3">
      <a href="index.php?page=suppliers&amp;tab=po" class="btn btn-sm btn-outline-secondary">Batal</a>
      <button class="btn btn-sm btn-outline-primary" data-after="list" data-testid="po-save"><i class="bi bi-save me-1"></i>Simpan</button>
      <button class="btn btn-sm btn-primary" data-after="print" data-testid="po-save-print"><i class="bi bi-printer me-1"></i>Simpan &amp; Cetak BON</button>
    </div>
  </div></div>
</form>
<script>
(function () {
  var form = document.getElementById('poForm');
  var body = document.getElementById('poBody'), empty = document.getElementById('poEmpty');
  var input = document.getElementById('poSearch'), listEl = document.getElementById('poList');
  var manual = document.getElementById('poManual');
  var LOW = <?= json_encode($low, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var INIT = <?= json_encode($initItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var MAX_ROWS = <?= PO_MAX_ROWS ?>;
  var timer = null, results = [], activeIdx = -1;
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  function renum() {
    var rows = body.querySelectorAll('tr[data-row]'), q = 0;
    rows.forEach(function (tr, i) { tr.querySelector('[data-role="no"]').textContent = i + 1; q += parseInt(tr.querySelector('[name="item_qty[]"]').value, 10) || 0; });
    empty.style.display = rows.length ? 'none' : '';
    document.getElementById('poCountI').textContent = rows.length;
    document.getElementById('poCountQ').textContent = q;
  }
  function stokBadge(p) {
    if (p.stok == null || p.stok === '') return '<span class="text-muted">-</span>';
    var s = parseInt(p.stok, 10), m = parseInt(p.stok_min, 10);
    var low = !isNaN(m) && m > 0 && s < m;
    return '<span class="badge ' + (low ? 'text-bg-danger' : 'text-bg-light border') + '">' + s + (low ? ' &middot; min ' + m : '') + '</span>';
  }
  function addRow(p, opt) {
    opt = opt || {};
    var pid = parseInt(p.part_id || p.id || 0, 10) || 0;
    if (pid) {
      var ex = body.querySelector('tr[data-part="' + pid + '"]');
      if (ex) { var q0 = ex.querySelector('[name="item_qty[]"]'); q0.value = (parseInt(q0.value, 10) || 0) + (parseInt(opt.qty, 10) || 1); renum(); if (!opt.silent) { q0.focus(); q0.select(); } return; }
    }
    var tr = document.createElement('tr');
    tr.setAttribute('data-row', '1'); if (pid) tr.setAttribute('data-part', pid);
    tr.innerHTML = '<td class="text-center small" data-role="no"></td>' +
      '<td><input type="hidden" name="item_part_id[]" value="' + pid + '">' +
        (pid ? '<input type="hidden" name="item_kode[]" value="' + esc(p.kode) + '"><span class="po-kode">' + esc(p.kode) + '</span>'
             : '<input name="item_kode[]" class="form-control form-control-sm" maxlength="100" placeholder="kode (opsional)" value="' + esc(p.kode) + '">') + '</td>' +
      '<td>' + (pid ? '<input type="hidden" name="item_nama[]" value="' + esc(p.nama) + '">' + esc(p.nama)
             : '<div class="d-flex gap-1 align-items-center"><input name="item_nama[]" class="form-control form-control-sm" maxlength="200" required value="' + esc(p.nama) + '"><span class="badge text-bg-warning">Manual</span></div>') + '</td>' +
      '<td class="text-center small">' + (pid ? stokBadge(p) : '<span class="text-muted">-</span>') + '</td>' +
      '<td><input type="number" name="item_qty[]" min="1" step="1" value="' + (parseInt(opt.qty || p.qty, 10) || 1) + '" class="form-control form-control-sm" data-testid="po-qty"></td>' +
      '<td><input name="item_catatan[]" class="form-control form-control-sm" maxlength="255" placeholder="catatan baris" value="' + esc(p.catatan || '') + '"></td>' +
      '<td><button type="button" class="btn-close" aria-label="Hapus baris" data-role="remove"></button></td>';
    body.appendChild(tr); renum();
    if (!opt.silent) { var q = tr.querySelector('[name="item_qty[]"]'); q.focus(); q.select(); }
  }
  function closeList() { listEl.classList.remove('is-open'); activeIdx = -1; }
  function render(items) {
    results = items || []; activeIdx = -1;
    listEl.innerHTML = results.map(function (p, i) {
      return '<div class="po-search-item" data-idx="' + i + '"><span class="kode">' + esc(p.kode) + '</span>' + esc(p.nama) + ' <span class="text-muted small">&middot; stok ' + esc(p.stok) + '</span></div>';
    }).join('') + '<div class="po-search-item manual" data-idx="m" data-testid="po-manual-opt">+ Input manual' +
      (results.length ? ' <span class="text-muted fw-normal">(part tidak ada di daftar?)</span>' : ' <span class="text-muted fw-normal">"' + esc(input.value) + '" belum ada di database Sparepart</span>') + '</div>';
    listEl.classList.add('is-open');
  }
  function openManual() {
    var v = input.value.trim(); closeList(); manual.classList.remove('d-none');
    var looksKode = /\d/.test(v) && /^[0-9A-Za-z\-]{5,}$/.test(v);
    document.getElementById('poMK').value = looksKode ? v.toUpperCase() : '';
    document.getElementById('poMN').value = looksKode ? '' : v;
    document.getElementById('poMQ').value = 1;
    (looksKode || !v ? document.getElementById('poMN') : document.getElementById('poMQ')).focus();
  }
  function pick(i) {
    if (i === 'm') { openManual(); return; }
    var p = results[i]; if (!p) return;
    addRow({ part_id: p.id, kode: p.kode, nama: p.nama, stok: p.stok, stok_min: null });
    input.value = ''; closeList();
  }
  input.addEventListener('input', function () {
    clearTimeout(timer); var q = input.value.trim();
    if (q === '') { closeList(); return; }
    timer = setTimeout(function () {
      fetch('ajax/lookup.php?action=search_parts&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(render).catch(function () { render([]); });
    }, 220);
  });
  input.addEventListener('keydown', function (e) {
    var items = listEl.querySelectorAll('.po-search-item');
    if (!listEl.classList.contains('is-open')) { if (e.key === 'Enter') e.preventDefault(); return; }
    if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); activeIdx = (activeIdx + 1) % items.length; }
    else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); activeIdx = (activeIdx - 1 + items.length) % items.length; }
    else if (e.key === 'Enter') { e.preventDefault(); if (activeIdx >= 0) pick(items[activeIdx].getAttribute('data-idx') === 'm' ? 'm' : activeIdx); else if (results.length === 1) pick(0); else if (!results.length) pick('m'); return; }
    else if (e.key === 'Escape') { closeList(); return; }
    else return;
    items.forEach(function (el, i) { el.classList.toggle('is-active', i === activeIdx); });
  });
  listEl.addEventListener('mousedown', function (e) {
    var it = e.target.closest('.po-search-item'); if (!it) return;
    e.preventDefault(); var x = it.getAttribute('data-idx'); pick(x === 'm' ? 'm' : parseInt(x, 10));
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.po-search-wrap')) closeList(); });
  document.getElementById('poMAdd').addEventListener('click', function () {
    var n = document.getElementById('poMN').value.trim();
    if (!n) { alert('Nama part wajib diisi untuk input manual.'); document.getElementById('poMN').focus(); return; }
    addRow({ part_id: 0, kode: document.getElementById('poMK').value.trim().toUpperCase(), nama: n }, { qty: document.getElementById('poMQ').value, silent: true });
    manual.classList.add('d-none'); input.value = ''; input.focus();
  });
  document.getElementById('poMCancel').addEventListener('click', function () { manual.classList.add('d-none'); });
  document.getElementById('poLowBtn').addEventListener('click', function () {
    var n = 0;
    LOW.forEach(function (p) {
      if (body.querySelector('tr[data-part="' + p.id + '"]')) return;
      var s = parseInt(p.stok, 10) || 0, m = parseInt(p.stok_min, 10) || 0;
      addRow({ part_id: p.id, kode: p.kode, nama: p.nama, stok: s, stok_min: m }, { qty: Math.max(1, m * 2 - s), silent: true }); n++;
    });
    alert(n ? n + ' part stok menipis ditambahkan (qty saran: sampai 2x stok minimum). Silakan sesuaikan.' : 'Semua part stok menipis sudah ada di daftar.');
  });
  body.addEventListener('input', renum);
  body.addEventListener('click', function (e) { var b = e.target.closest('[data-role="remove"]'); if (b) { b.closest('tr').remove(); renum(); } });
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault(); });
  form.querySelectorAll('button[data-after]').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('poAfter').value = b.getAttribute('data-after'); }); });
  form.addEventListener('submit', function (e) {
    var rows = body.querySelectorAll('tr[data-row]');
    if (!rows.length) { e.preventDefault(); alert('Tambahkan minimal satu part ke daftar.'); input.focus(); return; }
    if (rows.length > MAX_ROWS) { e.preventDefault(); alert('Maksimal ' + MAX_ROWS + ' baris per PO. Pecah menjadi 2 PO.'); return; }
    var bad = false; rows.forEach(function (tr) { if (!(parseInt(tr.querySelector('[name="item_qty[]"]').value, 10) >= 1)) bad = true; });
    if (bad) { e.preventDefault(); alert('Qty setiap part minimal 1.'); }
  });
  INIT.forEach(function (p) { addRow(p, { qty: p.qty, silent: true }); });
  renum();
})();
</script>
<?php
return;
endif;

// =====================================================================
// VIEW: PENCOCOKAN PART MANUAL SEBELUM TERIMA BARANG
// =====================================================================
if ($view === 'link'):
    $lp = $linkSum['po'];
    $manualItems = array_values(array_filter($linkSum['items'], function ($r) { return !$r['part_id'] && $r['sisa'] > 0; }));
    $categories = $db->query("SELECT nama FROM categories ORDER BY nama")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="card table-card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="h6 mb-0"><i class="bi bi-link-45deg me-1"></i>Terima Barang <?= esc($lp['no_po']) ?>: cocokkan part manual</h2>
    <a href="index.php?page=suppliers&amp;tab=po&amp;open=<?= (int)$lp['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
  </div>
  <div class="alert alert-info small py-2">PO ini berisi part yang diinput manual (belum ada di database Sparepart). Agar bisa masuk stok, tentukan untuk tiap part:
    daftarkan sebagai <strong>part baru</strong>, <strong>pakai part yang sudah ada</strong>, atau <strong>lewati</strong> dulu bila barangnya belum datang. Setelah itu Anda diarahkan ke form Stok Masuk.</div>
  <form method="post" data-testid="po-link-form">
    <input type="hidden" name="action" value="po_link"><input type="hidden" name="po_id" value="<?= (int)$lp['id'] ?>">
    <?php foreach ($manualItems as $mi): $iid = (int)$mi['id']; ?>
    <div class="border rounded p-2 mb-2" data-link-row="<?= $iid ?>">
      <div class="fw-semibold small mb-1"><?= esc($mi['nama']) ?> <?php if ($mi['kode'] !== ''): ?><span class="po-kode" style="font-family:ui-monospace,monospace;background:#eef2f7;padding:1px 6px;border-radius:4px;font-size:.72rem"><?= esc($mi['kode']) ?></span><?php endif; ?> &middot; qty <?= (int)$mi['qty'] ?><?= $mi['catatan'] !== '' ? ' &middot; <span class="text-muted">' . esc($mi['catatan']) . '</span>' : '' ?></div>
      <div class="d-flex flex-wrap gap-3 small mb-2">
        <label><input type="radio" name="mode[<?= $iid ?>]" value="new" checked data-testid="po-link-new-<?= $iid ?>"> Daftarkan sebagai part baru</label>
        <label><input type="radio" name="mode[<?= $iid ?>]" value="existing" data-testid="po-link-exist-<?= $iid ?>"> Pakai part yang sudah ada</label>
        <label><input type="radio" name="mode[<?= $iid ?>]" value="skip" data-testid="po-link-skip-<?= $iid ?>"> Lewati (belum datang)</label>
      </div>
      <div class="row g-2" data-pane="new">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Kode part <span class="text-danger">*</span></label><input name="new_kode[<?= $iid ?>]" class="form-control form-control-sm" maxlength="100" value="<?= esc($mi['kode']) ?>" data-testid="po-link-kode-<?= $iid ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small mb-1">Nama part <span class="text-danger">*</span></label><input name="new_nama[<?= $iid ?>]" class="form-control form-control-sm" maxlength="200" value="<?= esc($mi['nama']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Kategori</label>
          <select name="new_kategori[<?= $iid ?>]" class="form-select form-select-sm"><option value="">-</option><?php foreach ($categories as $c): ?><option><?= esc($c) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Harga jual (Rp)</label><input type="number" min="0" step="any" name="new_harga_jual[<?= $iid ?>]" class="form-control form-control-sm" value="0"></div>
        <div class="col-12 small text-muted">Stok awal 0; stok bertambah lewat Stok Masuk berikutnya. Harga beli diisi di form Stok Masuk.</div>
      </div>
      <div class="d-none" data-pane="existing">
        <input type="hidden" name="exist_part[<?= $iid ?>]" value="" data-role="exist-id">
        <div class="po-search-wrap" style="position:relative">
          <input type="text" class="form-control form-control-sm" placeholder="Cari kode / nama part yang sudah ada..." data-role="exist-search" data-testid="po-link-search-<?= $iid ?>">
          <div class="list-group position-absolute w-100 shadow-sm" style="z-index:30;max-height:240px;overflow:auto" data-role="exist-list"></div>
        </div>
        <div class="small mt-1" data-role="exist-picked"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="d-flex justify-content-end gap-2">
      <a href="index.php?page=suppliers&amp;tab=po&amp;open=<?= (int)$lp['id'] ?>" class="btn btn-sm btn-outline-secondary">Batal</a>
      <button class="btn btn-sm btn-success" data-testid="po-link-submit"><i class="bi bi-arrow-right-circle me-1"></i>Lanjut ke Stok Masuk</button>
    </div>
  </form>
</div></div>
<script>
(function () {
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  document.querySelectorAll('[data-link-row]').forEach(function (row) {
    function sync() {
      var m = (row.querySelector('input[type=radio]:checked') || {}).value;
      row.querySelector('[data-pane="new"]').classList.toggle('d-none', m !== 'new');
      row.querySelector('[data-pane="existing"]').classList.toggle('d-none', m !== 'existing');
      row.querySelectorAll('[data-pane="new"] input[name^="new_kode"], [data-pane="new"] input[name^="new_nama"]').forEach(function (i) { i.required = (m === 'new'); });
    }
    row.querySelectorAll('input[type=radio]').forEach(function (r) { r.addEventListener('change', sync); }); sync();
    var inp = row.querySelector('[data-role="exist-search"]'), list = row.querySelector('[data-role="exist-list"]'), t = null;
    inp.addEventListener('input', function () {
      clearTimeout(t); var q = inp.value.trim(); if (!q) { list.innerHTML = ''; return; }
      t = setTimeout(function () {
        fetch('ajax/lookup.php?action=search_parts&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rs) {
          list.innerHTML = (rs || []).slice(0, 12).map(function (p) { return '<button type="button" class="list-group-item list-group-item-action small py-1" data-id="' + esc(p.id) + '" data-label="' + esc(p.kode + ' - ' + p.nama) + '">' + esc(p.kode) + ' &middot; ' + esc(p.nama) + '</button>'; }).join('') || '<div class="list-group-item small text-muted">Tidak ditemukan.</div>';
        });
      }, 220);
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-id]'); if (!b) return;
      row.querySelector('[data-role="exist-id"]').value = b.getAttribute('data-id');
      row.querySelector('[data-role="exist-picked"]').innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>Dipilih: <strong>' + esc(b.getAttribute('data-label')) + '</strong>';
      list.innerHTML = ''; inp.value = '';
    });
  });
  document.querySelector('[data-testid="po-link-form"]').addEventListener('submit', function (e) {
    var miss = false;
    document.querySelectorAll('[data-link-row]').forEach(function (row) {
      var m = (row.querySelector('input[type=radio]:checked') || {}).value;
      if (m === 'existing' && !row.querySelector('[data-role="exist-id"]').value) miss = true;
    });
    if (miss) { e.preventDefault(); alert('Pilih part dari hasil pencarian untuk setiap baris "Pakai part yang sudah ada".'); }
  });
})();
</script>
<?php
return;
endif;

// =====================================================================
// VIEW: RIWAYAT PO
// =====================================================================
$fq = trim((string)($_GET['q'] ?? ''));
$fsup = (int)($_GET['supplier_id'] ?? 0);
$fst = $_GET['status'] ?? '';
if (!in_array($fst, ['', 'menunggu', 'sebagian', 'selesai', 'ditutup'], true)) $fst = '';
$fbln = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? $_GET['bulan'] : '';
$openId = (int)($_GET['open'] ?? 0);

$rcvSub = "(SELECT COALESCE(SUM(LEAST(i.qty, (SELECT COALESCE(SUM(sii.qty),0) FROM purchase_order_receipts r
              JOIN supplier_invoice_items sii ON sii.invoice_id = r.invoice_id
              WHERE r.po_id = i.po_id AND sii.part_id = i.part_id))),0)
           FROM purchase_order_items i WHERE i.po_id = po.id AND i.part_id IS NOT NULL)";
$inner = "SELECT po.*, s.nama AS supplier_nama,
    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.po_id = po.id) AS n_item,
    (SELECT COALESCE(SUM(i.qty),0) FROM purchase_order_items i WHERE i.po_id = po.id) AS q_total,
    $rcvSub AS q_rcv
  FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id";
$conds = []; $params = [];
if ($fsup > 0) { $conds[] = "po.supplier_id = ?"; $params[] = $fsup; }
if ($fbln !== '') { $conds[] = "po.tgl LIKE ?"; $params[] = $fbln . '%'; }
if ($fq !== '') {
    $conds[] = "(po.no_po LIKE ? OR s.nama LIKE ? OR po.catatan LIKE ? OR EXISTS (SELECT 1 FROM purchase_order_items i2 WHERE i2.po_id = po.id AND (i2.kode LIKE ? OR i2.nama LIKE ?)))";
    array_push($params, "%$fq%", "%$fq%", "%$fq%", "%$fq%", "%$fq%");
}
if ($conds) $inner .= ' WHERE ' . implode(' AND ', $conds);
$stKey = "(CASE WHEN x.q_total > 0 AND x.q_rcv >= x.q_total THEN 'selesai' WHEN x.status = 'closed' THEN 'ditutup' WHEN x.q_rcv > 0 THEN 'sebagian' ELSE 'menunggu' END)";
$outerWhere = $fst !== '' ? "WHERE $stKey = " . $db->quote($fst) : '';
$per = 25;
$cnt = $db->prepare("SELECT COUNT(*) FROM ($inner) x $outerWhere"); $cnt->execute($params);
$totalRows = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($totalRows / $per));
$pg = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$off = ($pg - 1) * $per;
$ls = $db->prepare("SELECT x.*, $stKey AS st_key FROM ($inner) x $outerWhere ORDER BY x.id DESC LIMIT $per OFFSET $off");
$ls->execute($params);
$pos = $ls->fetchAll(PDO::FETCH_ASSOC);
$qsBase = 'page=suppliers&tab=po&q=' . urlencode($fq) . '&supplier_id=' . $fsup . '&status=' . urlencode($fst) . '&bulan=' . urlencode($fbln);
$cntOpen = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status='open'")->fetchColumn();
?>
<div class="card table-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="h6 mb-0"><i class="bi bi-clipboard-check me-1"></i>Riwayat Purchase Order</h2>
    <a href="index.php?page=suppliers&amp;tab=po&amp;new=1" class="btn btn-sm btn-success" data-testid="po-new"><i class="bi bi-plus-lg me-1"></i>Buat PO Baru</a>
  </div>
  <form method="get" class="row g-2 mb-3 align-items-end" data-testid="po-filter">
    <input type="hidden" name="page" value="suppliers"><input type="hidden" name="tab" value="po">
    <div class="col-12 col-md-4"><label class="form-label small mb-1">Cari no. PO / supplier / part</label><input name="q" value="<?= esc($fq) ?>" class="form-control form-control-sm" placeholder="mis. PO-<?= date('Ym') ?> atau busi"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Supplier</label>
      <select name="supplier_id" class="form-select form-select-sm"><option value="0">Semua supplier</option><?php foreach ($allSuppliers as $s_): ?><option value="<?= (int)$s_['id'] ?>" <?= $fsup === (int)$s_['id'] ? 'selected' : '' ?>><?= esc($s_['nama']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <?php foreach (['' => 'Semua status', 'menunggu' => 'Menunggu barang', 'sebagian' => 'Diterima sebagian', 'selesai' => 'Selesai', 'ditutup' => 'Ditutup'] as $k => $v): ?><option value="<?= $k ?>" <?= $fst === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Bulan</label><input type="month" name="bulan" value="<?= esc($fbln) ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button></div>
  </form>
  <div class="small text-muted mb-2"><?= $totalRows ?> PO ditemukan &middot; <?= $cntOpen ?> PO masih terbuka. Klik baris untuk melihat isi dan riwayatnya.</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle" data-testid="po-table">
    <thead><tr><th>No. PO</th><th>Tanggal</th><th>Supplier</th><th class="text-end">Item</th><th class="text-end">Dipesan / diterima</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
    <tbody>
    <?php if (!$pos): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada Purchase Order<?= ($fq || $fsup || $fst || $fbln) ? ' pada filter ini' : '' ?>. Klik "Buat PO Baru".</td></tr><?php endif; ?>
    <?php foreach ($pos as $po):
        $pid = (int)$po['id']; $stk = $po['st_key'];
        $nRcpt = 0; $detItems = po_items($db, $pid); $rcpts = po_receipts($db, $pid); $nRcpt = count($rcpts);
        $canReceive = ($po['status'] === 'open' && $stk !== 'selesai');
        $isOpen = ($openId === $pid);
    ?>
      <tr class="po-row" data-bs-toggle="collapse" data-bs-target="#poDet<?= $pid ?>" style="cursor:pointer" data-testid="po-row-<?= esc($po['no_po']) ?>">
        <td class="text-nowrap"><i class="bi bi-chevron-right small text-muted me-1"></i><strong><?= esc($po['no_po']) ?></strong></td>
        <td class="text-nowrap"><?= _valid_date($po['tgl']) ? date('d/m/Y', strtotime($po['tgl'])) : '-' ?></td>
        <td><?= $po['supplier_nama'] !== null ? esc($po['supplier_nama']) : '<span class="text-muted">(supplier dihapus)</span>' ?></td>
        <td class="text-end"><?= (int)$po['n_item'] ?></td>
        <td class="text-end text-nowrap"><?= (int)$po['q_total'] ?> / <?= (int)$po['q_rcv'] ?></td>
        <td><?= po_status_badge($stk) ?></td>
        <td class="text-end text-nowrap" onclick="event.stopPropagation()">
          <?php if ($canReceive): ?>
          <form method="post" class="d-inline"><input type="hidden" name="action" value="po_receive"><input type="hidden" name="po_id" value="<?= $pid ?>">
            <button class="btn btn-sm btn-success" data-testid="po-receive-<?= esc($po['no_po']) ?>"><i class="bi bi-box-arrow-in-down me-1"></i><?= $stk === 'sebagian' ? 'Terima sisa' : 'Terima Barang' ?></button></form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-primary" href="index.php?page=po_print&amp;id=<?= $pid ?>" title="Cetak BON" data-testid="po-print-<?= esc($po['no_po']) ?>"><i class="bi bi-printer"></i></a>
          <?php if ($nRcpt === 0 && $po['status'] === 'open'): ?>
          <a class="btn btn-sm btn-outline-secondary" href="index.php?page=suppliers&amp;tab=po&amp;edit=<?= $pid ?>" title="Edit" data-testid="po-edit-<?= esc($po['no_po']) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Hapus <?= esc($po['no_po']) ?>? PO yang dihapus tidak bisa dikembalikan.')"><input type="hidden" name="action" value="po_delete"><input type="hidden" name="po_id" value="<?= $pid ?>">
            <button class="btn btn-sm btn-outline-danger" title="Hapus" data-testid="po-delete-<?= esc($po['no_po']) ?>"><i class="bi bi-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
      <tr class="po-det"><td colspan="7" class="p-0 border-0">
        <div class="collapse<?= $isOpen ? ' show' : '' ?>" id="poDet<?= $pid ?>"><div class="p-2 ps-4 bg-light border-bottom">
          <div class="row g-3">
            <div class="col-lg-7">
              <table class="table table-sm mb-1 small bg-white">
                <thead><tr><th>Kode</th><th>Nama part</th><th class="text-center">Dipesan</th><th class="text-center">Diterima</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($detItems as $di): $full = $di['diterima'] >= (int)$di['qty']; ?>
                  <tr><td class="text-nowrap"><?= $di['kode'] !== '' ? esc($di['kode']) : '-' ?></td>
                    <td><?= esc($di['nama']) ?><?= $di['part_id'] ? '' : ' <span class="badge text-bg-warning">Manual</span>' ?></td>
                    <td class="text-center"><?= (int)$di['qty'] ?></td>
                    <td class="text-center fw-semibold <?= $full ? 'text-success' : ($di['diterima'] > 0 ? 'text-primary' : 'text-muted') ?>"><?= $di['diterima'] ?></td>
                    <td><?= esc($di['catatan']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php if ($po['catatan'] !== ''): ?><div class="small text-muted">Catatan: <?= esc($po['catatan']) ?></div><?php endif; ?>
            </div>
            <div class="col-lg-5 small">
              <div class="fw-semibold mb-1">Riwayat</div>
              <ul class="list-unstyled mb-2" data-testid="po-history">
                <li class="mb-1"><i class="bi bi-circle-fill text-secondary me-1" style="font-size:.5rem;vertical-align:middle"></i>Dibuat oleh <?= esc($po['user_nama'] ?: '-') ?> <span class="text-muted">&middot; <?= lokal($po['created_at']) ?></span></li>
                <?php if ($po['updated_at'] && !$nRcpt): ?><li class="mb-1"><i class="bi bi-circle-fill text-secondary me-1" style="font-size:.5rem;vertical-align:middle"></i>Terakhir diubah <span class="text-muted">&middot; <?= lokal($po['updated_at']) ?></span></li><?php endif; ?>
                <?php foreach ($rcpts as $rc): ?>
                <li class="mb-1"><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem;vertical-align:middle"></i>Diterima &rarr; Stok Masuk <a href="index.php?page=purchase_receipt&amp;id=<?= (int)$rc['invoice_id'] ?>"><?= esc($rc['no_faktur']) ?></a> (<?= (int)$rc['qty'] ?> pcs, <?= rupiah($rc['total']) ?>)<?= $rc['no_faktur_supplier'] !== '' ? ' &middot; No. supplier ' . esc($rc['no_faktur_supplier']) : '' ?> <span class="text-muted">&middot; <?= esc($rc['user_nama']) ?>, <?= lokal($rc['created_at']) ?></span></li>
                <?php endforeach; ?>
                <?php if ($po['status'] === 'closed'): ?><li class="mb-1"><i class="bi bi-circle-fill text-dark me-1" style="font-size:.5rem;vertical-align:middle"></i>Ditutup oleh <?= esc($po['closed_by'] ?: '-') ?> <span class="text-muted">&middot; <?= lokal($po['closed_at']) ?></span></li><?php endif; ?>
              </ul>
              <?php if ($po['status'] === 'open' && $stk !== 'selesai'): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Tutup <?= esc($po['no_po']) ?>? Sisa barang yang belum datang tidak lagi ditunggu. PO bisa dibuka lagi nanti.')">
                <input type="hidden" name="action" value="po_close"><input type="hidden" name="po_id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-outline-dark" data-testid="po-close-<?= esc($po['no_po']) ?>"><i class="bi bi-x-circle me-1"></i>Tutup PO</button></form>
              <?php elseif ($po['status'] === 'closed' && $stk !== 'selesai'): ?>
              <form method="post" class="d-inline"><input type="hidden" name="action" value="po_reopen"><input type="hidden" name="po_id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-outline-secondary" data-testid="po-reopen-<?= esc($po['no_po']) ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Buka lagi</button></form>
              <?php endif; ?>
            </div>
          </div>
        </div></div>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="d-flex justify-content-between align-items-center mt-2">
    <a class="btn btn-sm btn-outline-secondary <?= $pg <= 1 ? 'disabled' : '' ?>" href="index.php?<?= esc($qsBase) ?>&amp;p=<?= $pg - 1 ?>">&laquo; Sebelumnya</a>
    <span class="small text-muted">Halaman <?= $pg ?> / <?= $pages ?></span>
    <a class="btn btn-sm btn-outline-secondary <?= $pg >= $pages ? 'disabled' : '' ?>" href="index.php?<?= esc($qsBase) ?>&amp;p=<?= $pg + 1 ?>">Berikutnya &raquo;</a>
  </nav>
  <?php endif; ?>
</div></div>
<?php return; ?>
