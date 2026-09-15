<?php
// ============================================================
// Master kategori jenis sparepart (dipakai sebagai dropdown
// saat input/edit sparepart dan pengelompokan laporan stok)
// ============================================================
$db = db();
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $nama = trim($_POST['nama'] ?? '');
    $ket = trim($_POST['keterangan'] ?? '');
    if ($nama !== '') {
        try {
            if ($id) {
                $db->prepare("UPDATE categories SET nama=?, keterangan=? WHERE id=?")->execute([$nama, $ket, $id]);
                set_flash('success', 'Kategori diperbarui.');
            } else {
                $db->prepare("INSERT INTO categories (nama, keterangan) VALUES (?,?)")->execute([$nama, $ket]);
                set_flash('success', 'Kategori baru ditambahkan.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menyimpan: nama kategori sudah ada.');
        }
    } else {
        set_flash('danger', 'Nama kategori tidak boleh kosong.');
    }
    header('Location: index.php?page=categories'); exit;
}
if ($action === 'delete') {
    $db->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Kategori dihapus. Nilai kategori pada sparepart yang sudah ada tidak berubah.');
    header('Location: index.php?page=categories'); exit;
}

$rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM parts p WHERE p.kategori = c.nama) AS jml_part
    FROM categories c ORDER BY c.nama")->fetchAll(PDO::FETCH_ASSOC);
$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM categories WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Kategori' : 'Tambah Kategori' ?></h2>
      <form method="post" data-testid="category-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="mb-2"><label class="form-label small">Nama Kategori</label>
          <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" placeholder="Oli, Kampas Rem, Busi..." data-testid="category-nama"></div>
        <div class="mb-3"><label class="form-label small">Keterangan</label>
          <textarea name="keterangan" class="form-control form-control-sm" rows="2" data-testid="category-keterangan"><?= esc($edit['keterangan'] ?? '') ?></textarea></div>
        <button class="btn btn-sm btn-primary w-100" data-testid="category-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Kategori' ?></button>
        <?php if ($edit): ?><a href="index.php?page=categories" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3">Daftar Kategori Sparepart</h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="categories-table">
        <thead><tr><th>Nama Kategori</th><th>Keterangan</th><th class="text-end">Jml Sparepart</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted">Belum ada kategori.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= esc($r['nama']) ?></td>
            <td class="text-muted small"><?= esc($r['keterangan']) ?></td>
            <td class="text-end"><span class="badge bg-secondary"><?= $r['jml_part'] ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=categories&edit=<?= $r['id'] ?>" data-testid="category-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus kategori ini?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="category-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
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
