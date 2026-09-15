<?php
$db = db();
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
    $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Supplier dihapus.');
    header('Location: index.php?page=suppliers'); exit;
}

$rows = $db->query("SELECT s.*, (SELECT COUNT(*) FROM stock_movements sm WHERE sm.supplier_id = s.id) AS trx_count FROM suppliers s ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
