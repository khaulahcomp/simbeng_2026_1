<?php
$db = db();
$action = $_POST['action'] ?? '';

// ---- Simpan (tambah / edit) pelanggan ----
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $nama = trim($_POST['nama'] ?? '');
    $telepon = trim($_POST['telepon'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    if ($nama !== '') {
        if ($id) {
            $db->prepare("UPDATE customers SET nama=?, telepon=?, alamat=? WHERE id=?")->execute([$nama, $telepon, $alamat, $id]);
            set_flash('success', 'Data pelanggan diperbarui.');
        } else {
            $db->prepare("INSERT INTO customers (nama, telepon, alamat) VALUES (?,?,?)")->execute([$nama, $telepon, $alamat]);
            set_flash('success', 'Pelanggan baru ditambahkan.');
        }
    }
    header('Location: index.php?page=customers'); exit;
}

// ---- Hapus pelanggan ----
if ($action === 'delete') {
    $db->prepare("DELETE FROM customers WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Pelanggan dihapus.');
    header('Location: index.php?page=customers'); exit;
}

// ---- Simpan / hapus kendaraan ----
if ($action === 'save_vehicle') {
    $cid = (int)$_POST['customer_id'];
    $db->prepare("INSERT INTO vehicles (customer_id, merek, model, plat_nomor) VALUES (?,?,?,?)")
       ->execute([$cid, trim($_POST['merek']), trim($_POST['model']), strtoupper(trim($_POST['plat_nomor']))]);
    set_flash('success', 'Kendaraan ditambahkan.');
    header('Location: index.php?page=customers&view=' . $cid); exit;
}
if ($action === 'delete_vehicle') {
    $cid = (int)$_POST['customer_id'];
    $db->prepare("DELETE FROM vehicles WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Kendaraan dihapus.');
    header('Location: index.php?page=customers&view=' . $cid); exit;
}

// ---- Detail pelanggan: kendaraan + riwayat servis ----
$view = (int)($_GET['view'] ?? 0);
if ($view) {
    $cust = $db->prepare("SELECT * FROM customers WHERE id=?"); $cust->execute([$view]);
    $cust = $cust->fetch(PDO::FETCH_ASSOC);
    if (!$cust) { header('Location: index.php?page=customers'); exit; }
    $vs = $db->prepare("SELECT * FROM vehicles WHERE customer_id=? ORDER BY id DESC"); $vs->execute([$view]);
    $vehicles = $vs->fetchAll(PDO::FETCH_ASSOC);
    $hs = $db->prepare("SELECT t.*, v.plat_nomor FROM transactions t LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.customer_id=? ORDER BY t.id DESC");
    $hs->execute([$view]);
    $history = $hs->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <a href="index.php?page=customers" class="btn btn-sm btn-outline-secondary mb-3" data-testid="back-to-customers"><i class="bi bi-arrow-left"></i> Kembali</a>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card table-card"><div class="card-body">
          <h2 class="h6">Profil Pelanggan</h2>
          <p class="mb-1 fw-bold" data-testid="customer-detail-name"><?= esc($cust['nama']) ?></p>
          <p class="mb-1"><i class="bi bi-telephone me-1"></i><?= esc($cust['telepon'] ?: '-') ?></p>
          <p class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= esc($cust['alamat'] ?: '-') ?></p>
        </div></div>
        <div class="card table-card mt-3"><div class="card-body">
          <h2 class="h6">Tambah Kendaraan</h2>
          <form method="post" data-testid="vehicle-form">
            <input type="hidden" name="action" value="save_vehicle">
            <input type="hidden" name="customer_id" value="<?= $view ?>">
            <div class="mb-2"><label class="form-label small">Merek Motor</label>
              <select name="merek" class="form-select form-select-sm" data-testid="vehicle-merek">
                <option>Honda</option><option>Yamaha</option><option>Suzuki</option><option>Kawasaki</option><option>Vespa</option><option>KTM</option><option>Lainnya</option>
              </select></div>
            <div class="mb-2"><label class="form-label small">Model / Tipe</label>
              <input name="model" class="form-control form-control-sm" placeholder="Beat, NMAX, Vario..." data-testid="vehicle-model"></div>
            <div class="mb-2"><label class="form-label small">Nomor Plat</label>
              <input name="plat_nomor" class="form-control form-control-sm" required placeholder="B 1234 ABC" data-testid="vehicle-plat"></div>
            <button class="btn btn-sm btn-primary w-100" data-testid="vehicle-submit">Tambah Kendaraan</button>
          </form>
        </div></div>
      </div>
      <div class="col-lg-8">
        <div class="card table-card"><div class="card-body">
          <h2 class="h6">Kendaraan Terdaftar</h2>
          <table class="table table-sm" data-testid="vehicles-table">
            <thead><tr><th>Merek</th><th>Model</th><th>Plat Nomor</th><th></th></tr></thead>
            <tbody>
            <?php if (!$vehicles): ?><tr><td colspan="4" class="text-center text-muted">Belum ada kendaraan.</td></tr><?php endif; ?>
            <?php foreach ($vehicles as $v): ?>
              <tr>
                <td><?= esc($v['merek']) ?></td><td><?= esc($v['model']) ?></td><td><?= esc($v['plat_nomor']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus kendaraan ini?')">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="customer_id" value="<?= $view ?>">
                    <input type="hidden" name="id" value="<?= $v['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" data-testid="vehicle-delete-<?= $v['id'] ?>"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div></div>
        <div class="card table-card mt-3"><div class="card-body">
          <h2 class="h6">Riwayat Servis / Transaksi</h2>
          <table class="table table-sm" data-testid="service-history-table">
            <thead><tr><th>Nota</th><th>Plat</th><th class="text-end">Total</th><th>Tanggal</th><th></th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="5" class="text-center text-muted">Belum ada riwayat servis.</td></tr><?php endif; ?>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><?= esc($h['no_nota']) ?></td><td><?= esc($h['plat_nomor'] ?? '-') ?></td>
                <td class="text-end"><?= rupiah($h['grand_total']) ?></td><td><?= esc(lokal($h['created_at'])) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="index.php?page=receipt&id=<?= $h['id'] ?>" data-testid="history-receipt-<?= $h['id'] ?>"><i class="bi bi-printer"></i></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div></div>
      </div>
    </div>
    <?php
    return;
}

// ---- Daftar pelanggan (dengan pencarian + pagination) ----
$q = trim($_GET['q'] ?? '');
$allowedPer = [25, 50, 100, 200];
$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, $allowedPer, true)) $per = 50;
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE nama LIKE ? OR telepon LIKE ?"; $params = ["%$q%", "%$q%"]; }
$cstmt = $db->prepare("SELECT COUNT(*) FROM customers $where");
$cstmt->execute($params);
$total = (int)$cstmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $per));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $per;
$stmt = $db->prepare("SELECT * FROM customers $where ORDER BY id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$qs = http_build_query(['page' => 'customers', 'q' => $q, 'per_page' => $per]);
$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM customers WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Pelanggan' : 'Tambah Pelanggan' ?></h2>
      <form method="post" data-testid="customer-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="mb-2"><label class="form-label small">Nama</label>
          <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" data-testid="customer-nama"></div>
        <div class="mb-2"><label class="form-label small">Nomor Telepon</label>
          <input name="telepon" class="form-control form-control-sm" value="<?= esc($edit['telepon'] ?? '') ?>" data-testid="customer-telepon"></div>
        <div class="mb-3"><label class="form-label small">Alamat</label>
          <textarea name="alamat" class="form-control form-control-sm" rows="2" data-testid="customer-alamat"><?= esc($edit['alamat'] ?? '') ?></textarea></div>
        <button class="btn btn-sm btn-primary w-100" data-testid="customer-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Pelanggan' ?></button>
        <?php if ($edit): ?><a href="index.php?page=customers" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
        <form class="d-flex align-items-end gap-2 flex-wrap" method="get">
          <input type="hidden" name="page" value="customers">
          <div>
            <label class="form-label small mb-1">Cari</label>
            <input name="q" class="form-control form-control-sm" placeholder="Cari nama / telepon..." value="<?= esc($q) ?>" data-testid="customer-search" style="max-width:220px">
          </div>
          <div>
            <label class="form-label small mb-1">Per halaman</label>
            <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="customer-perpage">
              <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-sm btn-outline-primary" data-testid="customer-search-btn"><i class="bi bi-search"></i></button>
        </form>
        <div class="btn-group btn-group-sm" role="group" data-testid="customer-export">
          <a href="export.php?type=customers&format=pdf&q=<?= urlencode($q) ?>" target="_blank" rel="noopener" class="btn btn-outline-danger" title="Unduh PDF" data-testid="customer-export-pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
          <a href="export.php?type=customers&format=xls&q=<?= urlencode($q) ?>" target="_blank" rel="noopener" class="btn btn-outline-success" title="Unduh Excel" data-testid="customer-export-xls"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        </div>
      </div>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="customers-table">
        <thead><tr><th>Nama</th><th>Telepon</th><th>Alamat</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted">Belum ada data pelanggan.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= esc($r['nama']) ?></td><td><?= esc($r['telepon']) ?></td><td><?= esc($r['alamat']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-info" href="index.php?page=customers&view=<?= $r['id'] ?>" title="Kendaraan & Riwayat" data-testid="customer-view-<?= $r['id'] ?>"><i class="bi bi-eye"></i></a>
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=customers&edit=<?= $r['id'] ?>" data-testid="customer-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pelanggan beserta kendaraannya?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="customer-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
        <span class="small text-muted" data-testid="customer-count">Menampilkan <?= count($rows) ?> dari <?= $total ?> pelanggan<?= $q!==''?' (filter: "'.esc($q).'")':'' ?>.</span>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex gap-2 align-items-center" data-testid="customer-pagination">
          <a class="btn btn-sm btn-outline-secondary <?= $pageNum<=1?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum-1 ?>" data-testid="customer-prev">&laquo; Sebelumnya</a>
          <span class="small text-muted">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
          <a class="btn btn-sm btn-outline-secondary <?= $pageNum>=$totalPages?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= $pageNum+1 ?>" data-testid="customer-next">Berikutnya &raquo;</a>
        </nav>
        <?php endif; ?>
      </div>
    </div></div>
  </div>
</div>
