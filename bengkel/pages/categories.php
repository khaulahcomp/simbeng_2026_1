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

$rows_all_count = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM categories WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}

// --- Pagination -------------------------------------------------------
// Batasi jumlah kategori yang ditampilkan per halaman (maks. 100) agar
// daftar tidak memanjang ke bawah tanpa batas saat kategori sudah banyak.
$per_page_opts = [25, 50, 100];
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 25;
$total_pages = max(1, (int)ceil($rows_all_count / $per_page));
$cur_page = max(1, (int)($_GET['p'] ?? 1));
if ($cur_page > $total_pages) $cur_page = $total_pages;
$offset = ($cur_page - 1) * $per_page;

$rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM parts p WHERE p.kategori = c.nama) AS jml_part
    FROM categories c ORDER BY c.nama LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

// Builder link pagination (mempertahankan route page=categories & per_page).
$paginate_url = function ($p) use ($per_page) {
    return 'index.php?' . http_build_query(['page' => 'categories', 'p' => $p, 'per_page' => $per_page]);
};
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
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h6 mb-0">Daftar Kategori Sparepart</h2>
        <form method="get" class="d-flex align-items-center gap-2">
          <input type="hidden" name="page" value="categories">
          <label class="small text-muted mb-0" for="catPerPageSelect">Tampilkan</label>
          <select id="catPerPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()" data-testid="category-per-page">
            <?php foreach ($per_page_opts as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?>/hlm</option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
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
      <?php
        // --- Kontrol pagination ------------------------------------------
        $cat_from_no = $rows_all_count ? ($offset + 1) : 0;
        $cat_to_no   = min($offset + $per_page, $rows_all_count);
      ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2" data-testid="categories-pagination">
        <div class="small text-muted" data-testid="categories-pagination-info">
          Menampilkan <strong><?= $cat_from_no ?>&ndash;<?= $cat_to_no ?></strong> dari <strong><?= $rows_all_count ?></strong> kategori
        </div>
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Pagination kategori">
          <ul class="pagination pagination-sm mb-0" data-testid="categories-pagination-nav">
            <li class="page-item <?= $cur_page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $cur_page > 1 ? esc($paginate_url($cur_page - 1)) : '#' ?>" data-testid="category-page-prev" aria-label="Sebelumnya">
                <i class="bi bi-chevron-left"></i>
              </a>
            </li>
            <?php
              $start = max(1, $cur_page - 2);
              $end   = min($total_pages, $cur_page + 2);
              if ($start > 1):
            ?>
              <li class="page-item"><a class="page-link" href="<?= esc($paginate_url(1)) ?>" data-testid="category-page-1">1</a></li>
              <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($i = $start; $i <= $end; $i++): ?>
              <li class="page-item <?= $i === $cur_page ? 'active' : '' ?>">
                <a class="page-link" href="<?= esc($paginate_url($i)) ?>" data-testid="category-page-<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
              <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
              <li class="page-item"><a class="page-link" href="<?= esc($paginate_url($total_pages)) ?>" data-testid="category-page-<?= $total_pages ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $cur_page >= $total_pages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $cur_page < $total_pages ? esc($paginate_url($cur_page + 1)) : '#' ?>" data-testid="category-page-next" aria-label="Berikutnya">
                <i class="bi bi-chevron-right"></i>
              </a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div></div>
  </div>
</div>
