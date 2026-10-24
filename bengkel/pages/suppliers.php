<?php
$db = db();
require_once __DIR__ . '/../includes/purchase.php';
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [trim($_POST['nama']), trim($_POST['telepon']), trim($_POST['email']), trim($_POST['alamat']), trim($_POST['keterangan'])];
    if ($data[0] !== '') {
        if ($id) {
            $db->prepare("UPDATE suppliers SET nama=?, telepon=?, email=?, alamat=?, keterangan=? WHERE id=?")->execute([...$data, $id]);
            set_flash('success', 'Data supplier diperbarui.');
        } else {
            $db->prepare("INSERT INTO suppliers (nama, telepon, email, alamat, keterangan) VALUES (?,?,?,?,?)")->execute($data);
            set_flash('success', 'Supplier baru ditambahkan.');
        }
    }
    header('Location: index.php?page=suppliers'); exit;
}
if ($action === 'delete') {
    $sid_del = (int)$_POST['id'];
    $ci = $db->prepare("SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id=?");
    $ci->execute([$sid_del]);
    if ((int)$ci->fetchColumn() > 0) {
        set_flash('danger', 'Supplier tidak bisa dihapus karena sudah memiliki faktur tagihan.');
    } else {
        try {
            $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([$sid_del]);
            set_flash('success', 'Supplier dihapus.');
        } catch (\PDOException $e) {
            set_flash('danger', 'Supplier tidak bisa dihapus karena sudah memiliki riwayat barang masuk.');
        }
    }
    header('Location: index.php?page=suppliers'); exit;
}
// Batalkan faktur tagihan supplier (khusus admin): stok dikembalikan bila masih cukup.
if ($action === 'delete_invoice') {
    require_admin();
    $err = delete_purchase_invoice($db, (int)($_POST['id'] ?? 0));
    set_flash($err ? 'danger' : 'success', $err ?: 'Faktur tagihan dibatalkan; stok dikembalikan.');
    $back = (int)($_POST['supplier_id'] ?? 0);
    header('Location: index.php?page=suppliers' . ($back ? '&supplier_id=' . $back : '') . '#faktur'); exit;
}

$rows = $db->query("SELECT s.*, (SELECT COUNT(*) FROM supplier_invoices si WHERE si.supplier_id = s.id)
        + (SELECT COUNT(*) FROM stock_movements sm WHERE sm.supplier_id = s.id AND sm.ref_type <> 'pembelian') AS trx_count FROM suppliers s ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
// ---- Faktur tagihan supplier: Pilih Supplier -> daftar faktur ----
$sid = (int)($_GET['supplier_id'] ?? 0);
$fstatus = $_GET['status'] ?? 'semua';
if (!in_array($fstatus, ['semua', 'belum', 'lunas'], true)) $fstatus = 'semua';
$kw = trim((string)($_GET['q'] ?? ''));
$allowedPer = [25, 50, 100];
$per = (int)($_GET['per_page'] ?? 25);
if (!in_array($per, $allowedPer, true)) $per = 25;
$sisaExpr = "(CASE WHEN i.status_bayar='lunas' THEN 0 ELSE COALESCE(d.jumlah - d.dibayar, i.total) END)";
$joinSql = "FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id LEFT JOIN debts d ON d.purchase_id = i.id";
$supCond = $sid > 0 ? "WHERE i.supplier_id = " . $sid : "";
$sm = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(i.total),0) AS total, COALESCE(SUM($sisaExpr),0) AS sisa $joinSql $supCond")->fetch(PDO::FETCH_ASSOC);
$fconds = []; $fparams = [];
if ($sid > 0) { $fconds[] = "i.supplier_id = ?"; $fparams[] = $sid; }
if ($fstatus !== 'semua') { $fconds[] = "i.status_bayar = ?"; $fparams[] = $fstatus; }
if ($kw !== '') {
    $fconds[] = "(i.no_faktur LIKE ? OR i.no_faktur_supplier LIKE ? OR i.catatan LIKE ?)";
    array_push($fparams, "%$kw%", "%$kw%", "%$kw%");
}
$fwhere = $fconds ? ('WHERE ' . implode(' AND ', $fconds)) : '';
$stc = $db->prepare("SELECT COUNT(*) $joinSql $fwhere");
$stc->execute($fparams);
$invTotal = (int)$stc->fetchColumn();
$invPages = max(1, (int)ceil($invTotal / $per));
$invPage = max(1, (int)($_GET['p'] ?? 1));
if ($invPage > $invPages) $invPage = $invPages;
$invOffset = ($invPage - 1) * $per;
$stl = $db->prepare("SELECT i.*, s.nama AS supplier_nama, d.id AS debt_id, d.dibayar AS debt_dibayar, $sisaExpr AS sisa
    $joinSql $fwhere ORDER BY (i.status_bayar='belum') DESC, COALESCE(i.jatuh_tempo, i.tgl) ASC, i.id DESC LIMIT $per OFFSET $invOffset");
$stl->execute($fparams);
$invoices = $stl->fetchAll(PDO::FETCH_ASSOC);
$allSuppliers = $db->query("SELECT id, nama FROM suppliers ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
$is_admin_user = ((current_user()['role'] ?? '') === 'admin');
$invBaseQs = 'page=suppliers&supplier_id=' . $sid . '&status=' . urlencode($fstatus) . '&q=' . urlencode($kw) . '&per_page=' . $per;

$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM suppliers WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Supplier' : 'Tambah Supplier' ?></h2>
      <form method="post" data-testid="supplier-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="mb-2"><label class="form-label small">Nama Supplier / Toko</label>
          <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" data-testid="supplier-nama"></div>
        <div class="mb-2"><label class="form-label small">Telepon / WA</label>
          <input name="telepon" class="form-control form-control-sm" value="<?= esc($edit['telepon'] ?? '') ?>" data-testid="supplier-telepon"></div>
        <div class="mb-2"><label class="form-label small">Email</label>
          <input name="email" type="email" class="form-control form-control-sm" value="<?= esc($edit['email'] ?? '') ?>" data-testid="supplier-email"></div>
        <div class="mb-2"><label class="form-label small">Alamat</label>
          <textarea name="alamat" class="form-control form-control-sm" rows="2" data-testid="supplier-alamat"><?= esc($edit['alamat'] ?? '') ?></textarea></div>
        <div class="mb-3"><label class="form-label small">Keterangan (barang yang disuplai, dll.)</label>
          <textarea name="keterangan" class="form-control form-control-sm" rows="2" data-testid="supplier-keterangan"><?= esc($edit['keterangan'] ?? '') ?></textarea></div>
        <button class="btn btn-sm btn-primary w-100" data-testid="supplier-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Supplier' ?></button>
        <?php if ($edit): ?><a href="index.php?page=suppliers" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="suppliers-table">
        <thead><tr><th>Nama</th><th>Telepon</th><th>Email</th><th>Keterangan</th><th class="text-end">Riwayat Masuk</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted">Belum ada data supplier.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= esc($r['nama']) ?></td><td><?= esc($r['telepon']) ?></td><td><?= esc($r['email']) ?></td>
            <td class="text-muted small"><?= esc($r['keterangan']) ?></td>
            <td class="text-end"><span class="badge bg-secondary"><?= $r['trx_count'] ?>x</span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-success" href="index.php?page=suppliers&supplier_id=<?= $r['id'] ?>#faktur" title="Lihat faktur tagihan" data-testid="supplier-faktur-<?= $r['id'] ?>"><i class="bi bi-receipt"></i></a>
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=suppliers&edit=<?= $r['id'] ?>" data-testid="supplier-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus supplier ini?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="supplier-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1" id="faktur">
  <div class="col-12">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-receipt me-1"></i>Faktur Tagihan Supplier</h2>
        <a href="index.php?page=stock" class="btn btn-sm btn-success" data-testid="supplier-new-invoice"><i class="bi bi-plus-lg me-1"></i>Input Barang Masuk Baru</a>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Jumlah Faktur</div><div class="fs-5 fw-bold" data-testid="sinv-count"><?= (int)$sm['n'] ?></div></div></div>
        <div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Total Tagihan</div><div class="fs-5 fw-bold" data-testid="sinv-total"><?= rupiah($sm['total']) ?></div></div></div>
        <div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Sisa Harus Dibayar</div><div class="fs-5 fw-bold text-danger" data-testid="sinv-sisa"><?= rupiah($sm['sisa']) ?></div></div></div>
      </div>

      <form method="get" class="row g-2 align-items-end mb-2" data-testid="sinv-filter-form">
        <input type="hidden" name="page" value="suppliers">
        <div class="col-12 col-md-4">
          <label class="form-label small mb-1">Pilih Supplier</label>
          <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="sinv-supplier">
            <option value="0">Semua supplier</option>
            <?php foreach ($allSuppliers as $as): ?><option value="<?= $as['id'] ?>" <?= $sid === (int)$as['id'] ? 'selected' : '' ?>><?= esc($as['nama']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Status</label>
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="sinv-status">
            <option value="semua" <?= $fstatus==='semua'?'selected':'' ?>>Semua</option>
            <option value="belum" <?= $fstatus==='belum'?'selected':'' ?>>Belum lunas</option>
            <option value="lunas" <?= $fstatus==='lunas'?'selected':'' ?>>Lunas</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Tampil</label>
          <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="sinv-perpage">
            <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?> data</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <div class="input-group input-group-sm">
            <input type="search" name="q" value="<?= esc($kw) ?>" class="form-control" placeholder="Cari no. faktur / no. faktur supplier..." data-testid="sinv-search-q">
            <button class="btn btn-primary" title="Cari" data-testid="sinv-search-btn"><i class="bi bi-search"></i></button>
            <?php if ($kw !== ''): ?><a class="btn btn-outline-secondary" title="Hapus pencarian" href="index.php?page=suppliers&amp;supplier_id=<?= $sid ?>&amp;status=<?= esc($fstatus) ?>&amp;per_page=<?= $per ?>#faktur"><i class="bi bi-x-lg"></i></a><?php endif; ?>
          </div>
        </div>
      </form>
      <div class="small text-muted mb-2" data-testid="sinv-count-info">Menampilkan <?= count($invoices) ?> dari <?= $invTotal ?> faktur<?= $sid ? '' : ' (semua supplier)' ?>.</div>

      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="sinv-table">
        <thead><tr>
          <th>Tanggal</th><th>Faktur</th><?php if (!$sid): ?><th>Supplier</th><?php endif; ?>
          <th class="text-end">Total</th><th class="text-end">Sisa</th><th>Jatuh Tempo</th><th>Status</th><th class="text-end">Aksi</th>
        </tr></thead>
        <tbody>
        <?php if (!$invoices): ?><tr><td colspan="<?= $sid ? 7 : 8 ?>" class="text-center text-muted">Belum ada faktur tagihan<?= $kw !== '' ? ' yang cocok' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($invoices as $iv):
            $lunas = ($iv['status_bayar'] === 'lunas');
            $jtv = (!empty($iv['jatuh_tempo']) && _valid_date($iv['jatuh_tempo'])) ? $iv['jatuh_tempo'] : '';
            $telat = (!$lunas && $jtv !== '' && $jtv < date('Y-m-d'));
        ?>
          <tr data-testid="sinv-row-<?= (int)$iv['id'] ?>">
            <td class="small text-nowrap"><?= _valid_date($iv['tgl']) ? esc(date('d/m/Y', strtotime($iv['tgl']))) : '-' ?></td>
            <td><a href="index.php?page=purchase_receipt&amp;id=<?= (int)$iv['id'] ?>"><strong><?= esc($iv['no_faktur']) ?></strong></a>
              <?php if ($iv['no_faktur_supplier'] !== ''): ?><div class="small text-muted">Faktur supplier: <?= esc($iv['no_faktur_supplier']) ?></div><?php endif; ?></td>
            <?php if (!$sid): ?><td><?= esc($iv['supplier_nama']) ?></td><?php endif; ?>
            <td class="text-end text-nowrap"><?= rupiah($iv['total']) ?></td>
            <td class="text-end text-nowrap <?= $lunas ? 'text-muted' : 'text-danger fw-semibold' ?>"><?= rupiah($iv['sisa']) ?></td>
            <td class="small text-nowrap <?= $telat ? 'text-danger fw-semibold' : '' ?>"><?= $jtv !== '' ? esc(date('d/m/Y', strtotime($jtv))) : '-' ?><?= $telat ? ' (lewat)' : '' ?></td>
            <td><span class="badge bg-<?= $lunas ? 'success' : 'primary' ?>"><?= $lunas ? 'Lunas' : 'Belum lunas' ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary py-0 px-1" href="index.php?page=purchase_receipt&amp;id=<?= (int)$iv['id'] ?>" title="Lihat / cetak faktur" data-testid="sinv-view-<?= (int)$iv['id'] ?>"><i class="bi bi-printer"></i></a>
              <?php if (!$lunas && $iv['debt_id']): ?>
              <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-bs-toggle="modal" data-bs-target="#supPayModal"
                data-debt-id="<?= (int)$iv['debt_id'] ?>" data-faktur="<?= esc($iv['no_faktur']) ?>" data-sisa="<?= (float)$iv['sisa'] ?>" data-testid="sinv-pay-<?= (int)$iv['id'] ?>"><i class="bi bi-cash-stack"></i> Bayar</button>
              <?php endif; ?>
              <?php if ($is_admin_user): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Batalkan faktur <?= esc($iv['no_faktur']) ?>? Stok barang masuk akan dikembalikan dan catatan hutangnya dihapus.')">
                <input type="hidden" name="action" value="delete_invoice"><input type="hidden" name="id" value="<?= (int)$iv['id'] ?>"><input type="hidden" name="supplier_id" value="<?= $sid ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Batalkan faktur" data-testid="sinv-delete-<?= (int)$iv['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($invPages > 1): ?>
      <nav class="d-flex justify-content-between align-items-center mt-2" data-testid="sinv-pagination">
        <a class="btn btn-sm btn-outline-secondary <?= $invPage<=1?'disabled':'' ?>" href="index.php?<?= $invBaseQs ?>&amp;p=<?= $invPage-1 ?>#faktur">&laquo; Sebelumnya</a>
        <span class="small text-muted">Halaman <?= $invPage ?> / <?= $invPages ?></span>
        <a class="btn btn-sm btn-outline-secondary <?= $invPage>=$invPages?'disabled':'' ?>" href="index.php?<?= $invBaseQs ?>&amp;p=<?= $invPage+1 ?>#faktur">Berikutnya &raquo;</a>
      </nav>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<!-- Modal bayar tagihan supplier (memakai mekanisme pembayaran Hutang Piutang) -->
<div class="modal fade" id="supPayModal" tabindex="-1" data-testid="sinv-pay-modal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="index.php?page=debts">
        <input type="hidden" name="action" value="pay">
        <input type="hidden" name="id" id="supPayId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cash-stack me-1"></i>Bayar Tagihan Supplier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="small text-muted mb-2">Faktur <strong id="supPayFaktur"></strong> &middot; sisa <strong id="supPaySisa"></strong></div>
          <div class="mb-2"><label class="form-label small">Jumlah dibayar (Rp)</label>
            <input type="number" name="bayar" id="supPayBayar" min="0" step="100" class="form-control form-control-sm" required data-testid="sinv-pay-amount"></div>
          <div><label class="form-label small">Keterangan</label>
            <input name="keterangan" class="form-control form-control-sm" placeholder="mis. cicilan 1 / transfer BCA"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-sm btn-primary" data-testid="sinv-pay-submit">Simpan Pembayaran</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-bs-target="#supPayModal"]'); if (!b) return;
  document.getElementById('supPayId').value = b.getAttribute('data-debt-id');
  document.getElementById('supPayFaktur').textContent = b.getAttribute('data-faktur');
  var sisa = parseFloat(b.getAttribute('data-sisa')) || 0;
  document.getElementById('supPaySisa').textContent = 'Rp ' + Math.round(sisa).toLocaleString('id-ID');
  document.getElementById('supPayBayar').value = Math.round(sisa);
});
</script>
