<?php
$db = db();

// ---- Simpan transaksi servis / penjualan (baru atau edit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_trx') {
    $edit_id     = (int)($_POST['edit_id'] ?? 0);
    $customer_id = (int)$_POST['customer_id'];
    $vehicle_id  = (int)($_POST['vehicle_id'] ?? 0) ?: null;
    $jasa_nama   = $_POST['jasa_nama'] ?? [];
    $jasa_biaya  = $_POST['jasa_biaya'] ?? [];
    $jasa_garansi= $_POST['jasa_garansi'] ?? [];
    $jasa_dj     = $_POST['jasa_diskon_jenis'] ?? [];
    $jasa_dn     = $_POST['jasa_diskon_nilai'] ?? [];
    $part_id     = $_POST['part_id'] ?? [];
    $part_qty    = $_POST['part_qty'] ?? [];
    $part_garansi= $_POST['part_garansi'] ?? [];
    $part_special= $_POST['part_special'] ?? [];
    $part_dj     = $_POST['part_diskon_jenis'] ?? [];
    $part_dn     = $_POST['part_diskon_nilai'] ?? [];
    $back = $edit_id ? "index.php?page=pos&edit=$edit_id" : "index.php?page=pos";

    // Diskon per item: kembalikan [jenis, nilai, potongan] dari harga kotor baris
    $item_diskon = function (string $jenis, $nilai, float $gross): array {
        $nilai = max(0, (float)$nilai);
        if ($jenis === 'nominal') return ['nominal', $nilai, min($nilai, $gross)];
        if ($jenis === 'persen')  return ['persen', min($nilai, 100), $gross * min($nilai, 100) / 100];
        return ['', 0, 0.0];
    };

    // Saat edit: tolak bila ada klaim garansi, dan stok item lama dianggap tersedia kembali
    $old_qty_map = [];
    if ($edit_id) {
        $kl = $db->prepare("SELECT COUNT(*) FROM warranty_claims WHERE transaction_id=?");
        $kl->execute([$edit_id]);
        if ((int)$kl->fetchColumn() > 0) {
            set_flash('danger', 'Transaksi ini memiliki klaim garansi terkait dan tidak dapat diedit.');
            header('Location: index.php?page=transactions'); exit;
        }
        $oq = $db->prepare("SELECT part_id, SUM(qty) FROM transaction_items WHERE transaction_id=? AND tipe='part' GROUP BY part_id");
        $oq->execute([$edit_id]);
        foreach ($oq->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $q) $old_qty_map[(int)$pid] = (int)$q;
    }

    // Kumpulkan item jasa
    $items = [];
    $total_jasa = 0;
    foreach ($jasa_nama as $i => $nm) {
        $nm = trim($nm);
        $biaya = (float)($jasa_biaya[$i] ?? 0);
        if ($nm === '' || $biaya <= 0) continue;
        [$dj, $dn, $disc] = $item_diskon($jasa_dj[$i] ?? '', $jasa_dn[$i] ?? 0, $biaya);
        $sub = $biaya - $disc;
        $items[] = ['tipe'=>'jasa', 'part_id'=>null, 'nama'=>$nm, 'qty'=>1, 'harga'=>$biaya, 'diskon_jenis'=>$dj, 'diskon_nilai'=>$dn, 'diskon'=>$disc, 'subtotal'=>$sub, 'garansi_hari'=>(int)($jasa_garansi[$i] ?? 0)];
        $total_jasa += $sub;
    }
    // Kumpulkan item sparepart — kode/item yang sama digabung jadi satu baris
    // (qty terakumulasi; atribut harga/diskon/garansi baris pertama yang dipakai).
    $total_part = 0;
    $merged = [];
    foreach ($part_id as $i => $pid) {
        $pid = (int)$pid;
        if (!$pid) continue;
        if (!isset($merged[$pid])) $merged[$pid] = ['i' => $i, 'qty' => 0];
        $merged[$pid]['qty'] += max(1, (int)($part_qty[$i] ?? 1));
    }
    foreach ($merged as $pid => $m) {
        $i = $m['i']; $qty = $m['qty'];
        $p = $db->prepare("SELECT * FROM parts WHERE id=?"); $p->execute([$pid]);
        $p = $p->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $tersedia = (int)$p['stok'] + ($old_qty_map[$pid] ?? 0);
        if ($qty > $tersedia) {
            set_flash('danger', "Stok {$p['nama']} tidak mencukupi (sisa {$p['stok']}).");
            header("Location: $back"); exit;
        }
        // Special Price (harga khusus owner): bila diisi > 0, menggantikan harga jual normal.
        $special = (float)($part_special[$i] ?? 0);
        $unit = $special > 0 ? $special : (float)$p['harga_jual'];
        $gross = $qty * $unit;
        [$dj, $dn, $disc] = $item_diskon($part_dj[$i] ?? '', $part_dn[$i] ?? 0, $gross);
        $sub = $gross - $disc;
        $items[] = ['tipe'=>'part', 'part_id'=>$pid, 'nama'=>$p['nama'], 'qty'=>$qty, 'harga'=>$unit, 'special_price'=>$special, 'diskon_jenis'=>$dj, 'diskon_nilai'=>$dn, 'diskon'=>$disc, 'subtotal'=>$sub, 'garansi_hari'=>(int)($part_garansi[$i] ?? 0)];
        $total_part += $sub;
    }

    if (!$customer_id || !$items) {
        set_flash('danger', 'Pilih pelanggan dan tambahkan minimal 1 item jasa/sparepart.');
        header("Location: $back"); exit;
    }

    // Diskon: nominal (Rp) atau persen (%) dari subtotal, maksimal sebesar subtotal
    $subtotal = $total_jasa + $total_part;
    $diskon_jenis = in_array($_POST['diskon_jenis'] ?? '', ['nominal', 'persen'], true) ? $_POST['diskon_jenis'] : '';
    $diskon_nilai = (float)($_POST['diskon_nilai'] ?? 0);
    $diskon = 0;
    if ($diskon_jenis === 'nominal') $diskon = min(max($diskon_nilai, 0), $subtotal);
    elseif ($diskon_jenis === 'persen') $diskon = $subtotal * min(max($diskon_nilai, 0), 100) / 100;
    $grand = $subtotal - $diskon;
    $metode_bayar = ($_POST['metode_bayar'] ?? 'cash') === 'transfer' ? 'transfer' : 'cash';

    // Simpan transaksi + sesuaikan stok dalam satu transaksi database
    $db->beginTransaction();
    try {
        if ($edit_id) {
            $st = $db->prepare("SELECT no_nota FROM transactions WHERE id=?");
            $st->execute([$edit_id]);
            $no_nota = $st->fetchColumn();
            if (!$no_nota) throw new Exception('Transaksi tidak ditemukan.');
            // Kembalikan stok item lama, lalu hapus item & pergerakan stok lama
            $old = $db->prepare("SELECT part_id, qty FROM transaction_items WHERE transaction_id=? AND tipe='part'");
            $old->execute([$edit_id]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$o['qty'], $o['part_id']]);
            }
            $db->prepare("DELETE FROM stock_movements WHERE ref_type='penjualan' AND ref_id=?")->execute([$edit_id]);
            $db->prepare("DELETE FROM transaction_items WHERE transaction_id=?")->execute([$edit_id]);
            $db->prepare("UPDATE transactions SET customer_id=?, vehicle_id=?, total_jasa=?, total_part=?, diskon=?, diskon_jenis=?, diskon_nilai=?, grand_total=?, metode_bayar=? WHERE id=?")
               ->execute([$customer_id, $vehicle_id, $total_jasa, $total_part, $diskon, $diskon_jenis, $diskon_nilai, $grand, $metode_bayar, $edit_id]);
            $trx_id = $edit_id;
        } else {
            $no_nota = next_kode('TRX', 'transactions', 'no_nota');
            $db->prepare("INSERT INTO transactions (no_nota, customer_id, vehicle_id, total_jasa, total_part, diskon, diskon_jenis, diskon_nilai, grand_total, metode_bayar, status) VALUES (?,?,?,?,?,?,?,?,?,?, 'selesai')")
               ->execute([$no_nota, $customer_id, $vehicle_id, $total_jasa, $total_part, $diskon, $diskon_jenis, $diskon_nilai, $grand, $metode_bayar]);
            $trx_id = (int)$db->lastInsertId();
        }
        $insItem = $db->prepare("INSERT INTO transaction_items (transaction_id, tipe, part_id, nama, qty, harga, special_price, diskon_jenis, diskon_nilai, diskon, subtotal, garansi_hari) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $updStok = $db->prepare("UPDATE parts SET stok = stok - ? WHERE id=?");
        $insMov  = $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, ref_type, ref_id, keterangan) VALUES (?,?,?,?,?,?)");
        foreach ($items as $it) {
            $insItem->execute([$trx_id, $it['tipe'], $it['part_id'], $it['nama'], $it['qty'], $it['harga'], $it['special_price'] ?? 0, $it['diskon_jenis'], $it['diskon_nilai'], $it['diskon'], $it['subtotal'], $it['garansi_hari']]);
            if ($it['tipe'] === 'part') {
                $updStok->execute([$it['qty'], $it['part_id']]);
                $insMov->execute([$it['part_id'], 'keluar', $it['qty'], 'penjualan', $trx_id, "Nota $no_nota"]);
            }
        }
        $db->commit();
        header('Location: index.php?page=receipt&id=' . $trx_id); exit;
    } catch (Exception $e) {
        $db->rollBack();
        if (function_exists('app_log')) app_log('error', 'POS simpan gagal: ' . $e->getMessage());
        set_flash('danger', 'Gagal menyimpan transaksi. Silakan coba lagi.');
        header("Location: $back"); exit;
    }
}

// ---- Mode edit: muat transaksi lama ke dalam form kasir ----
$edit_trx = null;
$edit_items = [];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $s = $db->prepare("SELECT * FROM transactions WHERE id=?");
    $s->execute([$eid]);
    $edit_trx = $s->fetch(PDO::FETCH_ASSOC);
    if (!$edit_trx) {
        set_flash('danger', 'Transaksi tidak ditemukan.');
        header('Location: index.php?page=transactions'); exit;
    }
    $kl = $db->prepare("SELECT COUNT(*) FROM warranty_claims WHERE transaction_id=?");
    $kl->execute([$eid]);
    if ((int)$kl->fetchColumn() > 0) {
        set_flash('danger', 'Transaksi ini memiliki klaim garansi terkait dan tidak dapat diedit.');
        header('Location: index.php?page=transactions'); exit;
    }
    $si = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id=?");
    $si->execute([$eid]);
    $edit_items = $si->fetchAll(PDO::FETCH_ASSOC);
}

$customers = $db->query("SELECT id, nama, telepon FROM customers ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
// CATATAN OPTIMASI: daftar sparepart TIDAK lagi dimuat seluruhnya ke browser.
// Pencarian dilakukan server-side via AJAX (ajax/lookup.php) sehingga tetap
// ringan walau data mencapai ratusan ribu record.
?>
<?php if (!$customers): ?>
<div class="alert alert-warning" data-testid="pos-no-customer">Belum ada pelanggan. <a href="index.php?page=customers">Tambah pelanggan dulu</a> sebelum membuat transaksi.</div>
<?php endif; ?>
<?php if ($edit_trx): ?>
<div class="alert alert-info" data-testid="pos-edit-banner"><i class="bi bi-pencil-square me-1"></i>Mode Edit transaksi <strong><?= esc($edit_trx['no_nota']) ?></strong>. Stok sparepart lama akan dikembalikan lalu dihitung ulang saat disimpan. <a href="index.php?page=transactions" data-testid="pos-edit-cancel">Batalkan edit</a></div>
<?php endif; ?>
<div id="draftBanner"></div>
<div class="card table-card"><div class="card-body">
<style>.part-row.overstock{background:#f8d7da;border-radius:6px;padding-top:2px;padding-bottom:2px}</style>
<form method="post" data-testid="pos-form">
  <input type="hidden" name="action" value="save_trx">
  <input type="hidden" name="edit_id" value="<?= $edit_trx['id'] ?? 0 ?>">
  <div class="row g-3 mb-4">
    <div class="col-md-5">
      <label class="form-label fw-semibold">Pelanggan</label>
      <select name="customer_id" id="posCustomer" class="form-select" required data-testid="pos-customer">
        <option value="">- Pilih pelanggan -</option>
        <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit_trx && (int)$edit_trx['customer_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= esc($c['nama']) ?> (<?= esc($c['telepon']) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Kendaraan</label>
      <select name="vehicle_id" id="posVehicle" class="form-select" data-testid="pos-vehicle">
        <option value="">- Pilih pelanggan dulu -</option>
      </select>
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button type="button" class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#scanModal" data-testid="pos-scan-btn"><i class="bi bi-upc-scan me-1"></i>Scan Barcode Part</button>
    </div>
  </div>

  <h2 class="h6">Jasa Servis</h2>
  <div class="row g-2 mb-1 d-none d-md-flex text-muted small fw-semibold" data-testid="jasa-header">
    <div class="col-md-3">Nama Jasa</div>
    <div class="col-md-2">Biaya</div>
    <div class="col-md-3">Diskon (Rp / %)</div>
    <div class="col-md-2">Garansi (hari)</div>
    <div class="col-md-1 text-end">Total</div>
    <div class="col-md-1"></div>
  </div>
  <div id="jasaRows"></div>
  <button type="button" class="btn btn-sm btn-outline-secondary mb-4" onclick="addJasa()" data-testid="add-jasa-btn"><i class="bi bi-plus-lg me-1"></i>Tambah Jasa</button>

  <h2 class="h6">Sparepart Digunakan / Dijual</h2>
  <div class="row g-2 mb-2 align-items-center" data-testid="pos-quickadd-row">
    <div class="col-md-6 col-lg-5">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
        <input id="posQuickAdd" class="form-control" autocomplete="off"
               placeholder="Ketik/scan Kode Sparepart atau Barcode + Enter"
               data-testid="pos-quickadd-input">
        <button type="button" class="btn btn-outline-primary" id="posQuickAddBtn" data-testid="pos-quickadd-btn">
          <i class="bi bi-plus-lg"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#scanModal" data-testid="pos-quickadd-scan-btn" title="Scan via kamera">
          <i class="bi bi-camera"></i>
        </button>
      </div>
      <div id="posQuickAddMsg" class="small text-muted mt-1" data-testid="pos-quickadd-msg"></div>
    </div>
    <div class="col-md-6 col-lg-7">
      <div id="posQuickAddSuggest" class="list-group" style="display:none;max-height:180px;overflow:auto" data-testid="pos-quickadd-suggest"></div>
    </div>
  </div>
  <div class="row g-2 mb-1 d-none d-md-flex text-muted small fw-semibold" data-testid="part-header">
    <div class="col-md-3">Nama Part</div>
    <div class="col-md-1">QTY</div>
    <div class="col-md-2">Diskon (Rp / %)</div>
    <div class="col-md-2">Garansi (hari)</div>
    <div class="col-md-2">Special Price</div>
    <div class="col-md-1 text-end">Total</div>
    <div class="col-md-1"></div>
  </div>
  <div id="partRows"></div>
  <button type="button" class="btn btn-sm btn-outline-secondary mb-4" onclick="addPart()" data-testid="add-part-btn"><i class="bi bi-plus-lg me-1"></i>Tambah Sparepart</button>

  <div class="row g-3 mb-2 justify-content-end">
    <div class="col-md-4">
      <label class="form-label fw-semibold"><i class="bi bi-wallet2 me-1"></i>Metode Pembayaran</label>
      <?php $mb = $edit_trx['metode_bayar'] ?? 'cash'; ?>
      <div class="btn-group w-100" role="group" data-testid="pos-metode-bayar-group">
        <input type="radio" class="btn-check" name="metode_bayar" id="metodeCash" value="cash" <?= $mb === 'cash' ? 'checked' : '' ?> data-testid="pos-metode-cash">
        <label class="btn btn-outline-success" for="metodeCash"><i class="bi bi-cash-coin me-1"></i>Cash</label>
        <input type="radio" class="btn-check" name="metode_bayar" id="metodeTransfer" value="transfer" <?= $mb === 'transfer' ? 'checked' : '' ?> data-testid="pos-metode-transfer">
        <label class="btn btn-outline-primary" for="metodeTransfer"><i class="bi bi-bank me-1"></i>Transfer</label>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold"><i class="bi bi-percent me-1"></i>Diskon (opsional)</label>
      <div class="input-group input-group-sm">
        <select name="diskon_jenis" id="diskonJenis" class="form-select" onchange="hitung()" data-testid="diskon-jenis">
          <option value="persen" selected>Persen (%)</option>
          <option value="nominal">Nominal (Rp)</option>
          <option value="">Tanpa Diskon</option>
        </select>
        <input name="diskon_nilai" id="diskonNilai" type="number" min="0" step="any" class="form-control" placeholder="0" oninput="hitung()" data-testid="diskon-nilai">
      </div>
    </div>
  </div>

  <div class="row justify-content-end">
    <div class="col-md-4">
      <table class="table table-sm">
        <tr><td>Total Jasa</td><td class="text-end" id="totalJasa" data-testid="total-jasa">Rp 0</td></tr>
        <tr><td>Total Sparepart</td><td class="text-end" id="totalPart" data-testid="total-part">Rp 0</td></tr>
        <tr><td>Diskon</td><td class="text-end text-danger" id="totalDiskon" data-testid="total-diskon">- Rp 0</td></tr>
        <tr class="fw-bold fs-5"><td>Grand Total</td><td class="text-end text-success" id="grandTotal" data-testid="grand-total">Rp 0</td></tr>
      </table>
      <div id="stokWarning" class="alert alert-danger py-2 px-2 small mb-2" style="display:none" data-testid="stok-warning"></div>
      <button class="btn btn-success w-100" data-testid="pos-submit"><i class="bi bi-check2-circle me-1"></i><?= $edit_trx ? 'Simpan Perubahan & Cetak Nota' : 'Simpan & Cetak Nota' ?></button>
      <div id="draftStatus" class="small text-muted text-end mt-1" data-testid="draft-status"></div>
    </div>
  </div>
</form>
</div></div>

<!-- Modal scan barcode -->
<div class="modal fade" id="scanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Scan Barcode Sparepart</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div id="scanner" style="width:100%"></div>
      <div class="mt-2"><label class="form-label small">Atau ketik kode/barcode (scanner USB):</label>
      <input id="scanInput" class="form-control" placeholder="Scan / ketik lalu Enter" data-testid="pos-scan-input"></div>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const rupiah = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
function escHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function debounce(fn, ms){ let t; return function(){ const a=arguments,c=this; clearTimeout(t); t=setTimeout(()=>fn.apply(c,a),ms); }; }
// Pencarian sparepart server-side (indexed). Payload kecil (maks ~20 hasil).
async function apiSearchParts(term){ try{ const r=await fetch('ajax/lookup.php?action=search_parts&q='+encodeURIComponent(term)); if(!r.ok) return []; return await r.json(); }catch(e){ return []; } }
async function apiPartByCode(code){ try{ const r=await fetch('ajax/lookup.php?action=part_by_code&code='+encodeURIComponent(code)); if(!r.ok) return null; return await r.json(); }catch(e){ return null; } }
async function apiPartsByIds(ids){ if(!ids||!ids.length) return []; try{ const r=await fetch('ajax/lookup.php?action=parts_by_ids&ids='+encodeURIComponent(ids.join(','))); if(!r.ok) return []; return await r.json(); }catch(e){ return []; } }
function partOptionLabel(p){ return p.kode + ' - ' + p.nama + ' (' + rupiah(p.harga_jual) + ', stok ' + p.stok + ')'; }

// Muat kendaraan sesuai pelanggan yang dipilih
document.getElementById('posCustomer').addEventListener('change', async function() {
  const sel = document.getElementById('posVehicle');
  sel.innerHTML = '<option value="">- Tanpa kendaraan -</option>';
  if (!this.value) return;
  const res = await fetch('ajax/lookup.php?action=vehicles&customer_id=' + this.value);
  (await res.json()).forEach(v => {
    sel.innerHTML += `<option value="${v.id}">${v.merek} ${v.model} - ${v.plat_nomor}</option>`;
  });
});

function addJasa(nama = '', biaya = '', garansi = 0, dj = '', dn = '') {
  const idx = document.querySelectorAll('.jasa-row').length;
  const div = document.createElement('div');
  div.className = 'row g-2 mb-2 align-items-center jasa-row';
  div.innerHTML = `
    <div class="col-6 col-md-3"><input name="jasa_nama[]" class="form-control form-control-sm" placeholder="Ganti oli, tune-up..." value="${nama}" data-testid="jasa-nama-${idx}"></div>
    <div class="col-6 col-md-2"><input name="jasa_biaya[]" type="number" min="0" class="form-control form-control-sm jasa-biaya" placeholder="Biaya jasa" value="${biaya}" oninput="hitung()" data-testid="jasa-biaya-${idx}"></div>
    <div class="col-6 col-md-3">${diskonCell('jasa', idx, dj, dn)}</div>
    <div class="col-6 col-md-2"><input name="jasa_garansi[]" type="number" min="0" class="form-control form-control-sm" placeholder="Garansi (hari)" value="${garansi}" data-testid="jasa-garansi-${idx}"></div>
    <div class="col-8 col-md-1 text-end small fw-semibold item-total" data-testid="jasa-total-${idx}">Rp 0</div>
    <div class="col-4 col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.jasa-row').remove();hitung()" data-testid="jasa-remove-${idx}"><i class="bi bi-x"></i></button></div>`;
  document.getElementById('jasaRows').appendChild(div);
  hitung();
}

// Sel diskon per item: pilih Rp (nominal) / % (persen) + nilai. Default: persen (%).
function diskonCell(prefix, idx, dj = 'persen', dn = '') {
  return `<div class="input-group input-group-sm">
    <select name="${prefix}_diskon_jenis[]" class="form-select diskon-jenis" style="max-width:56px;flex:0 0 auto" onchange="hitung()" data-testid="${prefix}-diskon-jenis-${idx}">
      <option value="" ${dj===''?'selected':''}>—</option>
      <option value="nominal" ${dj==='nominal'?'selected':''}>Rp</option>
      <option value="persen" ${dj==='persen'?'selected':''}>%</option>
    </select>
    <input name="${prefix}_diskon_nilai[]" type="number" min="0" step="any" class="form-control diskon-nilai" placeholder="0" value="${dn}" oninput="hitung()" data-testid="${prefix}-diskon-nilai-${idx}">
  </div>`;
}

// Tambah baris sparepart yang SUDAH dipilih (dari scan / autocomplete / edit).
// Bila kode/item yang sama sudah ada di faktur -> disatukan (qty terakumulasi).
function addPartRow(part, qty = 1, garansi = 0, dj = 'persen', dn = '', sp = '') {
  let existing = null;
  document.querySelectorAll('.part-row').forEach(row => {
    const sel = row.querySelector('.part-select');
    if (!existing && sel && String(sel.value) === String(part.id)) existing = row;
  });
  if (existing) {
    const q = existing.querySelector('.part-qty');
    q.value = (parseInt(q.value) || 0) + (parseInt(qty) || 1);
    hitung();
    return;
  }
  const idx = document.querySelectorAll('.part-row').length;
  const div = document.createElement('div');
  div.className = 'row g-2 mb-2 align-items-center part-row';
  const hargaNormal = parseFloat(part.harga_jual) || 0;
  const stokPart = (part.stok === '' || part.stok == null) ? '' : part.stok;
  div.innerHTML = `
    <div class="col-6 col-md-3"><select name="part_id[]" class="form-select form-select-sm part-select" onchange="hitung()" data-testid="part-select-${idx}"><option value="${part.id}" data-harga="${part.harga_jual}" data-stok="${stokPart}" selected>${escHtml(part._label || partOptionLabel(part))}</option></select></div>
    <div class="col-6 col-md-1"><input name="part_qty[]" type="number" min="1" class="form-control form-control-sm part-qty" value="${qty}" oninput="hitung()" data-testid="part-qty-${idx}"><div class="qty-warn text-danger fw-semibold" style="display:none;font-size:10px" data-testid="part-qty-warn-${idx}">Melebihi stok!</div></div>
    <div class="col-6 col-md-2">${diskonCell('part', idx, dj, dn)}</div>
    <div class="col-6 col-md-2"><input name="part_garansi[]" type="number" min="0" class="form-control form-control-sm" placeholder="Garansi (hari)" value="${garansi}" data-testid="part-garansi-${idx}"></div>
    <div class="col-6 col-md-2"><input name="part_special[]" type="number" min="0" step="any" class="form-control form-control-sm part-special" placeholder="Normal: ${Math.round(hargaNormal).toLocaleString('id-ID')}" value="${sp}" oninput="hitung()" data-testid="part-special-${idx}" title="Kosongkan = harga jual normal. Isi = harga khusus per unit."></div>
    <div class="col-8 col-md-1 text-end small fw-semibold item-total" data-testid="part-total-${idx}">Rp 0</div>
    <div class="col-4 col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.part-row').remove();hitung()" data-testid="part-remove-${idx}"><i class="bi bi-x"></i></button></div>`;
  document.getElementById('partRows').appendChild(div);
  hitung();
}

// Tombol "Tambah Sparepart" -> baris pencarian autocomplete (server-side).
function addPart() { addPartSearchRow(); }
function addPartSearchRow() {
  const div = document.createElement('div');
  div.className = 'row g-2 mb-2 align-items-start part-search-row';
  div.innerHTML = `
    <div class="col-md-6 position-relative">
      <input type="text" class="form-control form-control-sm part-search-input" placeholder="Cari sparepart (kode / barcode / nama)..." autocomplete="off" data-testid="part-search-input">
      <div class="list-group part-search-suggest" style="display:none;position:absolute;z-index:1000;width:100%;max-height:200px;overflow:auto"></div>
    </div>
    <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.part-search-row').remove()"><i class="bi bi-x"></i></button></div>`;
  document.getElementById('partRows').appendChild(div);
  const inp = div.querySelector('.part-search-input');
  const box = div.querySelector('.part-search-suggest');
  function close(){ box.style.display='none'; }
  const run = debounce(async () => {
    const term = inp.value.trim();
    box.innerHTML=''; close();
    if (term.length < 1) return;
    const rows = await apiSearchParts(term);
    if (!rows.length){ box.innerHTML='<div class="list-group-item small text-muted">Tidak ditemukan</div>'; box.style.display='block'; return; }
    rows.forEach(p => {
      const b=document.createElement('button'); b.type='button';
      b.className='list-group-item list-group-item-action py-1 small';
      b.setAttribute('data-testid','part-search-suggest-item');
      b.innerHTML='<span class="fw-semibold">'+escHtml(p.kode)+'</span> — '+escHtml(p.nama)+' <span class="text-muted">('+rupiah(p.harga_jual)+', stok '+p.stok+')</span>';
      b.addEventListener('click', () => { div.remove(); addPartRow(p); });
      box.appendChild(b);
    });
    box.style.display='block';
  }, 250);
  inp.addEventListener('input', run);
  inp.addEventListener('keydown', async e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const term = inp.value.trim(); if (!term) return;
      const { part: p } = await cariPart(term);
      if (p) { div.remove(); addPartRow(p); } else { run(); }
    } else if (e.key === 'Escape') { close(); }
  });
  document.addEventListener('click', ev => { if (!div.contains(ev.target)) close(); });
  inp.focus();
}

// Diskon per item (nominal Rp / persen %) dari harga kotor baris.
function itemDiskon(jenis, nilai, gross) {
  nilai = Math.max(0, parseFloat(nilai) || 0);
  if (jenis === 'nominal') return Math.min(nilai, gross);
  if (jenis === 'persen')  return gross * Math.min(nilai, 100) / 100;
  return 0;
}

// Hitung total otomatis: tiap item (jasa & sparepart) dipotong diskon per item,
// lalu subtotal dikurangi diskon nota (opsional) menghasilkan Grand Total.
function hitung() {
  let tj = 0, tp = 0;
  document.querySelectorAll('.jasa-row').forEach(r => {
    const biaya = parseFloat(r.querySelector('.jasa-biaya').value) || 0;
    const dj = r.querySelector('.diskon-jenis')?.value || '';
    const dn = r.querySelector('.diskon-nilai')?.value || 0;
    const net = biaya - itemDiskon(dj, dn, biaya);
    const tot = r.querySelector('.item-total'); if (tot) tot.textContent = rupiah(net);
    tj += net;
  });
  document.querySelectorAll('.part-row').forEach(r => {
    const sel = r.querySelector('.part-select');
    const hargaNormal = parseFloat(sel.selectedOptions[0]?.dataset.harga || 0);
    const spInput = r.querySelector('.part-special');
    const sp = parseFloat(spInput?.value || 0);
    const harga = (sp > 0) ? sp : hargaNormal; // Special Price menggantikan harga normal bila diisi
    const qty = parseInt(r.querySelector('.part-qty').value) || 0;
    const gross = harga * qty;
    const dj = r.querySelector('.diskon-jenis')?.value || '';
    const dn = r.querySelector('.diskon-nilai')?.value || 0;
    const net = gross - itemDiskon(dj, dn, gross);
    const tot = r.querySelector('.item-total'); if (tot) tot.textContent = rupiah(net);
    tp += net;
  });
  const sub = tj + tp;
  const jenis = document.getElementById('diskonJenis').value;
  const nilai = parseFloat(document.getElementById('diskonNilai').value) || 0;
  let diskon = 0;
  if (jenis === 'nominal') diskon = Math.min(nilai, sub);
  else if (jenis === 'persen') diskon = sub * Math.min(nilai, 100) / 100;
  document.getElementById('totalJasa').textContent = rupiah(tj);
  document.getElementById('totalPart').textContent = rupiah(tp);
  document.getElementById('totalDiskon').textContent = '- ' + rupiah(diskon);
  document.getElementById('grandTotal').textContent = rupiah(sub - diskon);
  validateStok();
}

// Validasi stok: qty melebihi stok -> baris ditandai merah, muncul peringatan,
// dan tombol simpan dikunci agar item tidak tersimpan minus dari keberadaan stok.
function validateStok() {
  const over = [];
  document.querySelectorAll('.part-row').forEach(r => {
    const sel = r.querySelector('.part-select');
    const opt = sel?.selectedOptions[0];
    const stok = parseInt(opt?.dataset.stok);
    const pid = sel?.value;
    const avail = (isNaN(stok) ? Infinity : stok) + (parseInt((window.POS_OLD_QTY || {})[pid]) || 0);
    const qtyInp = r.querySelector('.part-qty');
    const qty = parseInt(qtyInp.value) || 0;
    const warn = r.querySelector('.qty-warn');
    if (qty > avail) {
      qtyInp.classList.add('is-invalid');
      r.classList.add('overstock');
      if (warn) warn.style.display = '';
      over.push((opt ? opt.textContent.split(' - ')[0] : 'item') + ' (stok ' + stok + ', diminta ' + qty + ')');
    } else {
      qtyInp.classList.remove('is-invalid');
      r.classList.remove('overstock');
      if (warn) warn.style.display = 'none';
    }
  });
  const btn = document.querySelector('[data-testid="pos-submit"]');
  if (btn) btn.disabled = over.length > 0;
  const box = document.getElementById('stokWarning');
  if (box) {
    if (over.length) {
      box.style.display = '';
      box.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>Stok tidak cukup:</strong> ' + escHtml(over.join('; ')) + '. Kurangi qty agar transaksi bisa disimpan.';
    } else {
      box.style.display = 'none';
    }
  }
}

// Scanner kamera + input scanner USB (bertindak seperti keyboard).
// pilihDariScan dipakai baik oleh scanner kamera modal maupun input quickadd.
let scannerObj = null;
const scanModal = document.getElementById('scanModal');
// Cari sparepart: exact by kode/barcode -> pencarian; hasil dicocokkan lagi
// secara toleran spasi/kapital agar kode yang tersimpan beda spasi tetap terbaca.
async function cariPart(q) {
  let p = await apiPartByCode(q);
  if (p) return { part: p, rows: [] };
  const rows = await apiSearchParts(q);
  if (!rows.length) return { part: null, rows: [] };
  const norm = s => String(s || '').replace(/\s+/g, '').toUpperCase();
  const nq = norm(q);
  const exact = rows.find(r => norm(r.kode) === nq || norm(r.barcode) === nq);
  if (exact) return { part: exact, rows };
  if (rows.length === 1) return { part: rows[0], rows };
  return { part: null, rows };
}
// Tampilkan daftar kandidat sparepart pada kotak saran quick-add.
function showQuickAddSuggest(rows) {
  const box = document.getElementById('posQuickAddSuggest');
  if (!box) return;
  box.innerHTML = '';
  rows.forEach(p => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'list-group-item list-group-item-action py-1 small';
    b.setAttribute('data-testid', 'pos-quickadd-suggest-item');
    b.innerHTML = '<span class="fw-semibold">' + escHtml(p.kode) + '</span> — ' + escHtml(p.nama)
                + ' <span class="text-muted">(' + rupiah(p.harga_jual) + ', stok ' + p.stok + ')</span>';
    b.addEventListener('click', () => { addPartRow(p); box.style.display = 'none'; const qi = document.getElementById('posQuickAdd'); if (qi) { qi.value=''; qi.focus(); } });
    box.appendChild(b);
  });
  box.style.display = 'block';
}
async function pilihDariScan(text) {
  const q = (text || '').trim();
  if (!q) return false;
  const { part: p, rows } = await cariPart(q);
  if (p) {
    // Jika baris sparepart yang sama sudah ada -> tambah qty saja.
    let added = false;
    document.querySelectorAll('.part-row').forEach(row => {
      const sel = row.querySelector('.part-select');
      if (!added && sel && String(sel.value) === String(p.id)) {
        const qty = row.querySelector('.part-qty');
        qty.value = (parseInt(qty.value) || 0) + 1;
        added = true;
      }
    });
    if (!added) addPartRow(p);
    hitung();
    const inst = bootstrap.Modal.getInstance(scanModal);
    if (inst) inst.hide();
    showQuickAddMsg('Ditambahkan: ' + p.kode + ' - ' + p.nama, 'text-success');
    return true;
  }
  if (rows.length > 1) {
    // Ketik kode tidak exact tapi ada beberapa kandidat -> tampilkan daftar, bukan "tidak ditemukan".
    showQuickAddMsg('Kode "' + text + '" cocok dengan ' + rows.length + ' sparepart — pilih dari daftar.', 'text-warning');
    showQuickAddSuggest(rows);
    return false;
  }
  showQuickAddMsg('Sparepart dengan kode/barcode "' + text + '" tidak ditemukan.', 'text-danger');
  return false;
}
function showQuickAddMsg(t, cls) {
  const el = document.getElementById('posQuickAddMsg');
  if (!el) return;
  el.className = 'small mt-1 ' + (cls || 'text-muted');
  el.textContent = t;
}
scanModal.addEventListener('shown.bs.modal', () => {
  document.getElementById('scanInput').focus();
  scannerObj = new Html5Qrcode("scanner");
  scannerObj.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, pilihDariScan, () => {});
});
scanModal.addEventListener('hidden.bs.modal', () => { if (scannerObj) scannerObj.stop().catch(()=>{}); });
document.getElementById('scanInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); pilihDariScan(e.target.value.trim()); e.target.value = ''; }
});

// Quick-add sparepart via input kode/barcode inline (di atas daftar sparepart).
(function () {
  const inp = document.getElementById('posQuickAdd');
  const btn = document.getElementById('posQuickAddBtn');
  const box = document.getElementById('posQuickAddSuggest');
  if (!inp || !btn || !box) return;

  const renderSuggest = debounce(async (q) => {
    box.innerHTML = ''; box.style.display = 'none';
    const term = (q || '').trim(); if (term.length < 1) return;
    const matches = await apiSearchParts(term);
    if (!matches.length) return;
    matches.forEach(p => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'list-group-item list-group-item-action py-1 small';
      b.setAttribute('data-testid', 'pos-quickadd-suggest-item');
      b.innerHTML = '<span class="fw-semibold">' + escHtml(p.kode) + '</span> — ' + escHtml(p.nama)
                  + ' <span class="text-muted">(' + rupiah(p.harga_jual) + ', stok ' + p.stok + ')</span>';
      b.addEventListener('click', () => { addPartRow(p); inp.value = ''; box.style.display = 'none'; inp.focus(); });
      box.appendChild(b);
    });
    box.style.display = 'block';
  }, 250);

  inp.addEventListener('input', () => renderSuggest(inp.value));
  inp.addEventListener('keydown', async e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const v = inp.value.trim();
      if (v && await pilihDariScan(v)) { inp.value = ''; box.style.display = 'none'; }
      inp.focus();
    } else if (e.key === 'Escape') {
      box.style.display = 'none';
    }
  });
  btn.addEventListener('click', async () => {
    const v = inp.value.trim();
    if (!v) { showQuickAddMsg('Ketik kode / barcode dulu.', 'text-warning'); inp.focus(); return; }
    if (await pilihDariScan(v)) { inp.value = ''; box.style.display = 'none'; }
    inp.focus();
  });
  document.addEventListener('click', e => {
    if (!box.contains(e.target) && e.target !== inp) box.style.display = 'none';
  });
})();

<?php if ($edit_trx): ?>
// Mode edit: isi form dengan item transaksi lama (data sparepart diambil via AJAX)
const EDIT_ITEMS = <?= json_encode($edit_items, JSON_UNESCAPED_UNICODE) ?>;
// Stok lama dianggap tersedia kembali saat edit (dipakai validasi stok di browser).
window.POS_OLD_QTY = {};
EDIT_ITEMS.forEach(it => { if (it.tipe === 'part' && it.part_id) window.POS_OLD_QTY[it.part_id] = (window.POS_OLD_QTY[it.part_id] || 0) + parseInt(it.qty || 0); });
(async () => {
  const partIds = EDIT_ITEMS.filter(it => it.tipe === 'part' && it.part_id).map(it => it.part_id);
  const fetched = await apiPartsByIds(partIds);
  const byId = {}; fetched.forEach(p => { byId[p.id] = p; });
  EDIT_ITEMS.forEach(it => {
    if (it.tipe === 'jasa') { addJasa(it.nama, it.harga, it.garansi_hari, it.diskon_jenis || '', (it.diskon_jenis ? it.diskon_nilai : '')); }
    else {
      const p = byId[it.part_id] || { id: it.part_id, kode: '(part)', nama: it.nama, harga_jual: it.harga, stok: '-' };
      const sp = (parseFloat(it.special_price) > 0) ? it.special_price : '';
      addPartRow(p, it.qty, it.garansi_hari, it.diskon_jenis || '', (it.diskon_jenis ? it.diskon_nilai : ''), sp);
    }
  });
  hitung();
})();
<?php if ((float)$edit_trx['diskon'] > 0): ?>
<?php $edj = $edit_trx['diskon_jenis'] ?? ''; $edn = $edj === 'persen' ? (float)($edit_trx['diskon_nilai'] ?? 0) : (float)$edit_trx['diskon']; ?>
document.getElementById('diskonJenis').value = <?= json_encode($edj === 'persen' ? 'persen' : 'nominal') ?>;
document.getElementById('diskonNilai').value = <?= json_encode($edn) ?>;
<?php endif; ?>
// Mode edit: selalu muat daftar kendaraan pelanggan (walau transaksi lama tanpa kendaraan)
(async () => {
  const res = await fetch('ajax/lookup.php?action=vehicles&customer_id=<?= (int)$edit_trx['customer_id'] ?>');
  const sel = document.getElementById('posVehicle');
  sel.innerHTML = '<option value="">- Tanpa kendaraan -</option>';
  (await res.json()).forEach(v => { sel.innerHTML += `<option value="${v.id}">${v.merek} ${v.model} - ${v.plat_nomor}</option>`; });
  <?php if ($edit_trx['vehicle_id']): ?>sel.value = '<?= (int)$edit_trx['vehicle_id'] ?>';<?php endif; ?>
})();
hitung();
<?php else: ?>
addJasa(); addPart();

// ---- Draf otomatis kasir (localStorage): setiap perubahan inputan tersimpan otomatis ----
// Melindungi inputan dari kendala perangkat/jaringan; draf dapat dipulihkan/dihapus via banner.
const DRAFT_KEY = 'pos_draft_v1';
function serializeDraft() {
  const d = {
    ts: Date.now(), submitted: false,
    customer_id: document.getElementById('posCustomer').value,
    vehicle_id: document.getElementById('posVehicle').value,
    metode: document.querySelector('input[name="metode_bayar"]:checked')?.value || 'cash',
    diskon_jenis: document.getElementById('diskonJenis').value,
    diskon_nilai: document.getElementById('diskonNilai').value,
    jasa: [], parts: []
  };
  document.querySelectorAll('.jasa-row').forEach(r => {
    d.jasa.push({
      nama: r.querySelector('[name="jasa_nama[]"]').value,
      biaya: r.querySelector('[name="jasa_biaya[]"]').value,
      dj: r.querySelector('.diskon-jenis')?.value || 'persen',
      dn: r.querySelector('.diskon-nilai')?.value || '',
      garansi: r.querySelector('[name="jasa_garansi[]"]').value
    });
  });
  document.querySelectorAll('.part-row').forEach(r => {
    const sel = r.querySelector('.part-select'); const opt = sel?.selectedOptions[0];
    d.parts.push({
      id: sel.value, label: opt ? opt.textContent : '', harga: opt?.dataset.harga || 0, stok: opt?.dataset.stok ?? '',
      qty: r.querySelector('.part-qty').value,
      dj: r.querySelector('.diskon-jenis')?.value || 'persen',
      dn: r.querySelector('.diskon-nilai')?.value || '',
      garansi: r.querySelector('[name="part_garansi[]"]').value,
      special: r.querySelector('.part-special').value
    });
  });
  return d;
}
function saveDraft(submitted = false) {
  const d = serializeDraft(); d.submitted = submitted;
  const adaIsi = d.customer_id || d.parts.length || d.jasa.some(j => (j.nama || '').trim() !== '' || String(j.biaya).trim() !== '');
  if (!adaIsi && !submitted) { localStorage.removeItem(DRAFT_KEY); return; }
  try { localStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (e) {}
  const el = document.getElementById('draftStatus');
  if (el && !submitted) el.innerHTML = '<i class="bi bi-cloud-check me-1"></i>Draf tersimpan otomatis ' + new Date().toLocaleTimeString('id-ID');
}
async function restoreDraft(d) {
  document.querySelectorAll('.jasa-row,.part-row,.part-search-row').forEach(r => r.remove());
  (d.jasa || []).forEach(j => addJasa(j.nama, j.biaya, j.garansi, j.dj, j.dn));
  (d.parts || []).forEach(p => addPartRow({ id: p.id, kode: '', nama: '', harga_jual: p.harga, stok: p.stok, _label: p.label }, p.qty, p.garansi, p.dj, p.dn, p.special));
  document.getElementById('diskonJenis').value = d.diskon_jenis || 'persen';
  document.getElementById('diskonNilai').value = d.diskon_nilai || '';
  const mb = document.querySelector(`input[name="metode_bayar"][value="${d.metode}"]`); if (mb) mb.checked = true;
  if (d.customer_id) {
    const c = document.getElementById('posCustomer'); c.value = d.customer_id;
    const res = await fetch('ajax/lookup.php?action=vehicles&customer_id=' + d.customer_id);
    const selV = document.getElementById('posVehicle');
    selV.innerHTML = '<option value="">- Tanpa kendaraan -</option>';
    (await res.json()).forEach(v => { selV.innerHTML += `<option value="${v.id}">${v.merek} ${v.model} - ${v.plat_nomor}</option>`; });
    if (d.vehicle_id) selV.value = d.vehicle_id;
  }
  if (!document.querySelector('.jasa-row')) addJasa();
  if (!document.querySelector('.part-row') && !document.querySelector('.part-search-row')) addPart();
  hitung();
}
(function initDraft() {
  let d = null;
  try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) {}
  if (d && d.submitted) {
    // Simpan gagal (mis. stok berubah saat submit) -> pulihkan otomatis; berhasil -> hapus draf.
    if (document.querySelector('.alert.alert-danger')) {
      d.submitted = false;
      try { localStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (e) {}
      restoreDraft(d);
    } else {
      localStorage.removeItem(DRAFT_KEY);
    }
  } else if (d && (d.customer_id || (d.parts || []).length || (d.jasa || []).some(j => (j.nama || '').trim() !== ''))) {
    const banner = document.getElementById('draftBanner');
    if (banner) {
      banner.innerHTML = `<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2" data-testid="draft-banner">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span>Ditemukan draf inputan kasir (tersimpan ${new Date(d.ts).toLocaleString('id-ID')}).</span>
        <button type="button" class="btn btn-sm btn-warning" id="draftRestoreBtn" data-testid="draft-restore-btn"><i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan Draf</button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="draftDeleteBtn" data-testid="draft-delete-btn"><i class="bi bi-trash me-1"></i>Hapus Draf</button>
      </div>`;
      document.getElementById('draftRestoreBtn').addEventListener('click', () => { restoreDraft(d); banner.innerHTML = ''; });
      document.getElementById('draftDeleteBtn').addEventListener('click', () => { localStorage.removeItem(DRAFT_KEY); banner.innerHTML = ''; });
    }
  }
  const form = document.querySelector('[data-testid="pos-form"]');
  if (form) {
    const auto = debounce(() => saveDraft(false), 600);
    form.addEventListener('input', auto);
    form.addEventListener('change', auto);
    form.addEventListener('submit', () => saveDraft(true));
  }
})();
<?php endif; ?>
</script>
