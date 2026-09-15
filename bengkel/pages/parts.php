<?php
$db = db();
$action = $_POST['action'] ?? '';

// ---- Simpan (tambah / edit) sparepart ----
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $kode = strtoupper(trim($_POST['kode'] ?? ''));
    $kategori = trim($_POST['kategori'] ?? '');
    // Kategori baru (mis. hasil auto-fill dari hargasukucadang.online) otomatis
    // didaftarkan ke master kategori supaya muncul di dropdown selanjutnya.
    if ($kategori !== '') {
        $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)")->execute([$kategori]);
    }
    $data = [
        $kode,
        trim($_POST['barcode'] ?? ''),
        trim($_POST['nama'] ?? ''),
        $kategori,
        (float)($_POST['harga_beli'] ?? 0),
        (float)($_POST['harga_jual'] ?? 0),
        (int)($_POST['stok'] ?? 0),
        (int)($_POST['stok_min'] ?? 5),
        trim($_POST['lokasi_rak'] ?? ''),
    ];
    if ($kode !== '' && $data[2] !== '') {
        try {
            if ($id) {
                $db->prepare("UPDATE parts SET kode=?, barcode=?, nama=?, kategori=?, harga_beli=?, harga_jual=?, stok=?, stok_min=?, lokasi_rak=? WHERE id=?")
                   ->execute([...$data, $id]);
                set_flash('success', 'Data sparepart diperbarui.');
            } else {
                $db->prepare("INSERT INTO parts (kode, barcode, nama, kategori, harga_beli, harga_jual, stok, stok_min, lokasi_rak) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute($data);
                set_flash('success', 'Sparepart baru ditambahkan.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menyimpan: kode sparepart sudah digunakan.');
        }
    }
    header('Location: index.php?page=parts'); exit;
}
if ($action === 'delete') {
    $db->prepare("DELETE FROM parts WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Sparepart dihapus.');
    header('Location: index.php?page=parts'); exit;
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$sql = "SELECT * FROM parts";
$where = []; $params = [];
if ($q !== '') { $where[] = "(nama LIKE ? OR kode LIKE ? OR barcode LIKE ? OR lokasi_rak LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%", "%$q%"]; }
if ($filter === 'low') { $where[] = "stok <= stok_min"; }
$whereSql = $where ? (" WHERE " . implode(' AND ', $where)) : '';

// --- Pagination -----------------------------------------------------------
// Batasi 50 sparepart per halaman secara default agar tabel tidak terlalu
// panjang. User dapat memilih 25/50/100/200 baris per halaman.
$per_page_opts = [25, 50, 100, 200];
$per_page = (int)($_GET['per_page'] ?? 50);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 50;
$countStmt = $db->prepare("SELECT COUNT(*) FROM parts" . $whereSql);
$countStmt->execute($params);
$total_rows = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$cur_page = max(1, (int)($_GET['p'] ?? 1));
if ($cur_page > $total_pages) $cur_page = $total_pages;
$offset = ($cur_page - 1) * $per_page;

$sql .= $whereSql . " ORDER BY id DESC LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Builder link pagination yang mempertahankan filter & keyword pencarian.
$paginate_url = function ($p) use ($q, $filter, $per_page) {
    $qs = ['page' => 'parts', 'p' => $p, 'per_page' => $per_page];
    if ($q !== '') $qs['q'] = $q;
    if ($filter !== '') $qs['filter'] = $filter;
    return 'index.php?' . http_build_query($qs);
};

$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM parts WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
// Master kategori untuk dropdown pada form input sparepart
$categories = $db->query("SELECT nama FROM categories ORDER BY nama")->fetchAll(PDO::FETCH_COLUMN);

// Info sinkronisasi katalog hargasukucadang.online (last_hsc_sync) + riwayat import
$last_sync = setting('last_hsc_sync', '');
$last_sync_wib = $last_sync ? lokal($last_sync) : '-';
$catalog_count = (int)$db->query("SELECT COUNT(*) FROM part_catalog")->fetchColumn();
$import_logs = $db->query("SELECT * FROM import_logs ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Sparepart' : 'Tambah Sparepart' ?></h2>
      <form method="post" data-testid="part-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="row g-2">
          <div class="col-12 mb-2">
            <label class="form-label small mb-1">
              <i class="bi bi-cloud-download me-1"></i>Cari sparepart online (2 sumber)
              <span class="text-muted" style="font-size:11px">— hargasukucadang.online &amp; hondacengkareng.com · klik hasil untuk mengisi Kode, Nama, Kategori &amp; Harga</span>
            </label>
            <div class="input-group input-group-sm">
              <input type="text" id="hscQuery" class="form-control" placeholder="Ketik nama part (mis. kampas rem, oli) atau kode part..." autocomplete="off" data-testid="hsc-query">
              <button type="button" id="hscSearchBtn" class="btn btn-outline-primary" data-testid="hsc-search-btn"><i class="bi bi-search"></i></button>
            </div>
            <div id="hscFilters" class="d-flex flex-wrap gap-1 mt-1" style="display:none" data-testid="hsc-filters"></div>
            <div id="hscResults" class="list-group mt-1" style="max-height:260px;overflow:auto;display:none" data-testid="hsc-results"></div>
            <div id="hscMsg" class="small text-muted mt-1" style="display:none" data-testid="hsc-msg"></div>
            <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:11px">
              <span class="text-muted" data-testid="hsc-sync-info">
                <i class="bi bi-clock-history me-1"></i>Sync katalog:
                <span id="hscLastSync"><?= esc($last_sync_wib) ?></span>
                <span class="text-muted"> · <?= (int)$catalog_count ?> item lokal</span>
              </span>
              <button type="button" class="btn btn-link btn-sm p-0" id="hscSyncNowBtn" data-testid="hsc-sync-now-btn" style="font-size:11px">
                <i class="bi bi-arrow-clockwise me-1"></i>Sync Sekarang (2 sumber)
              </button>
            </div>
          </div>
          <div class="col-6 mb-2"><label class="form-label small">Kode Sparepart</label>
            <input name="kode" class="form-control form-control-sm" required value="<?= esc($edit['kode'] ?? '') ?>" data-testid="part-kode"></div>
          <div class="col-6 mb-2"><label class="form-label small">Barcode <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#scanModal" data-scan-target="part-barcode" data-testid="part-scan-btn"><i class="bi bi-upc-scan"></i></button></label>
            <input name="barcode" id="part-barcode" class="form-control form-control-sm" value="<?= esc($edit['barcode'] ?? '') ?>" data-testid="part-barcode"></div>
          <div class="col-12 mb-2"><label class="form-label small">Nama Barang</label>
            <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" data-testid="part-nama"></div>
          <div class="col-12 mb-2"><label class="form-label small">Kategori <a href="index.php?page=categories" class="text-decoration-none">(kelola kategori)</a></label>
            <select name="kategori" class="form-select form-select-sm" data-testid="part-kategori">
              <option value="">- Tanpa kategori -</option>
              <?php $katEdit = $edit['kategori'] ?? ''; $katAda = in_array($katEdit, $categories, true); ?>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= esc($cat) ?>" <?= $katEdit === $cat ? 'selected' : '' ?>><?= esc($cat) ?></option>
              <?php endforeach; ?>
              <?php if ($katEdit !== '' && !$katAda): ?><option value="<?= esc($katEdit) ?>" selected><?= esc($katEdit) ?> (lama)</option><?php endif; ?>
            </select></div>
          <div class="col-6 mb-2"><label class="form-label small">Harga Beli</label>
            <input name="harga_beli" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['harga_beli'] ?? 0) ?>" data-testid="part-harga-beli"></div>
          <div class="col-6 mb-2"><label class="form-label small">Harga Jual</label>
            <input name="harga_jual" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['harga_jual'] ?? 0) ?>" data-testid="part-harga-jual"></div>
          <div class="col-6 mb-2"><label class="form-label small">Stok Saat Ini</label>
            <input name="stok" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['stok'] ?? 0) ?>" data-testid="part-stok"></div>
          <div class="col-6 mb-3"><label class="form-label small">Stok Minimum (alert)</label>
            <input name="stok_min" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['stok_min'] ?? 5) ?>" data-testid="part-stok-min"></div>
          <div class="col-12 mb-3">
            <label class="form-label small">
              <i class="bi bi-geo-alt me-1"></i>Letak / Nomor Rak
              <span class="text-muted" style="font-size:11px">— mempermudah pencarian di gudang</span>
            </label>
            <input name="lokasi_rak" class="form-control form-control-sm"
                   placeholder="mis. R-01, A2-B3, Rak Oli Bawah..."
                   value="<?= esc($edit['lokasi_rak'] ?? '') ?>"
                   data-testid="part-lokasi-rak">
          </div>
        </div>
        <button class="btn btn-sm btn-primary w-100" data-testid="part-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Sparepart' ?></button>
        <?php if ($edit): ?><a href="index.php?page=parts" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>

    <div class="card table-card mt-3"><div class="card-body">
      <h2 class="h6">Import Excel / CSV</h2>
      <p class="small text-muted mb-2">
        Format kolom: <code>kode, nama, kategori, harga_beli, harga_jual, stok, stok_min, barcode, lokasi_rak</code>.
      </p>
      <div class="mb-2">
        <label class="form-label small mb-1">Mode Import</label>
        <div class="btn-group btn-group-sm w-100" role="group" data-testid="import-mode-group">
          <input type="radio" class="btn-check" name="importMode" id="importModeBaru" value="baru" checked data-testid="import-mode-baru">
          <label class="btn btn-outline-success" for="importModeBaru" title="Hanya menambahkan sparepart baru. Kode yang sudah ada akan dilewati.">
            <i class="bi bi-plus-circle me-1"></i>Format Baru (tambah)
          </label>
          <input type="radio" class="btn-check" name="importMode" id="importModeUpgrade" value="upgrade" data-testid="import-mode-upgrade">
          <label class="btn btn-outline-warning" for="importModeUpgrade" title="Update data lama & tambahkan sparepart baru (upsert).">
            <i class="bi bi-arrow-repeat me-1"></i>Format Lama/Upgrade
          </label>
        </div>
        <div class="small text-muted mt-1" id="importModeHint" data-testid="import-mode-hint">
          Mode "Baru": hanya menambahkan sparepart baru, kode yang sudah ada akan dilewati.
        </div>
      </div>
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv" class="form-control form-control-sm mb-2" data-testid="import-file">
      <div id="importResult" class="small" data-testid="import-result"></div>
      <div class="d-grid gap-1 mt-1">
        <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadTemplate('baru')" data-testid="import-template-baru-btn">
          <i class="bi bi-download me-1"></i>Download Format Baru (Tambah Sparepart)
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning" onclick="downloadTemplate('upgrade')" data-testid="import-template-upgrade-btn">
          <i class="bi bi-download me-1"></i>Download Format Lama / Upgrade
        </button>
      </div>
    </div></div>

    <div class="card table-card mt-3" data-testid="import-history-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-clock-history me-1"></i>Riwayat Import</h2>
        <span class="badge bg-light text-muted" data-testid="import-history-count"><?= count($import_logs) ?> log</span>
      </div>
      <?php if (!$import_logs): ?>
        <div class="small text-muted" data-testid="import-history-empty">Belum ada riwayat import.</div>
      <?php else: ?>
      <div class="table-responsive" style="max-height:280px">
        <table class="table table-sm align-middle mb-0" data-testid="import-history-table">
          <thead><tr>
            <th class="small">Waktu</th>
            <th class="small">File</th>
            <th class="small">Mode</th>
            <th class="small text-end">Baru</th>
            <th class="small text-end">Update</th>
            <th class="small text-end">Skip</th>
            <th class="small">Oleh</th>
          </tr></thead>
          <tbody>
          <?php foreach ($import_logs as $lg): ?>
            <tr data-testid="import-history-row-<?= (int)$lg['id'] ?>">
              <td class="small text-muted"><?= esc(lokal($lg['created_at'])) ?></td>
              <td class="small"><?= esc($lg['filename']) ?></td>
              <td>
                <span class="badge bg-<?= $lg['mode'] === 'baru' ? 'success' : 'warning text-dark' ?>">
                  <?= $lg['mode'] === 'baru' ? 'Baru' : 'Upgrade' ?>
                </span>
              </td>
              <td class="text-end small text-success"><?= (int)$lg['inserted'] ?></td>
              <td class="text-end small text-warning"><?= (int)$lg['updated'] ?></td>
              <td class="text-end small text-muted"><?= (int)$lg['skipped'] ?></td>
              <td class="small text-muted"><?= esc($lg['user_nama'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-end gap-1 mb-2 flex-wrap" data-testid="parts-export-buttons">
        <?php
          // Query string untuk ekspor mengikuti filter & halaman aktif.
          $exp_qs_page = http_build_query(array_filter([
              'type' => 'parts', 'scope' => 'page',
              'q' => $q, 'filter' => $filter, 'p' => $cur_page, 'per_page' => $per_page,
          ], fn ($v) => $v !== '' && $v !== null));
          $exp_qs_all  = http_build_query(array_filter([
              'type' => 'parts', 'scope' => 'all',
              'q' => $q, 'filter' => $filter,
          ], fn ($v) => $v !== '' && $v !== null));
        ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="Ekspor halaman aktif" data-testid="parts-export-page-group">
          <span class="btn btn-sm btn-outline-secondary disabled" style="pointer-events:none">
            <i class="bi bi-file-earmark-arrow-down me-1"></i>Ekspor Halaman
          </span>
          <a class="btn btn-outline-danger" target="_blank" href="export.php?<?= esc($exp_qs_page) ?>&format=pdf" data-testid="parts-export-page-pdf" title="PDF halaman <?= $cur_page ?>"><i class="bi bi-file-earmark-pdf"></i></a>
          <a class="btn btn-outline-success" href="export.php?<?= esc($exp_qs_page) ?>&format=xls" data-testid="parts-export-page-xls" title="Excel halaman <?= $cur_page ?>"><i class="bi bi-file-earmark-excel"></i></a>
          <a class="btn btn-outline-primary" href="export.php?<?= esc($exp_qs_page) ?>&format=doc" data-testid="parts-export-page-doc" title="Word halaman <?= $cur_page ?>"><i class="bi bi-file-earmark-word"></i></a>
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Ekspor semua" data-testid="parts-export-all-group">
          <span class="btn btn-sm btn-outline-secondary disabled" style="pointer-events:none">
            <i class="bi bi-collection me-1"></i>Ekspor Semua
          </span>
          <a class="btn btn-outline-danger" target="_blank" href="export.php?<?= esc($exp_qs_all) ?>&format=pdf" data-testid="parts-export-pdf"><i class="bi bi-file-earmark-pdf"></i></a>
          <a class="btn btn-outline-success" href="export.php?<?= esc($exp_qs_all) ?>&format=xls" data-testid="parts-export-xls"><i class="bi bi-file-earmark-excel"></i></a>
          <a class="btn btn-outline-primary" href="export.php?<?= esc($exp_qs_all) ?>&format=doc" data-testid="parts-export-doc"><i class="bi bi-file-earmark-word"></i></a>
        </div>
      </div>
      <form class="d-flex mb-3 flex-wrap gap-2" method="get">
        <input type="hidden" name="page" value="parts">
        <input name="q" class="form-control form-control-sm" style="max-width:280px" placeholder="Cari nama / kode / barcode / rak..." value="<?= esc($q) ?>" data-testid="part-search">
        <button class="btn btn-sm btn-outline-primary" data-testid="part-search-btn"><i class="bi bi-search"></i></button>
        <a href="index.php?page=parts&filter=low" class="btn btn-sm btn-outline-danger text-nowrap" data-testid="part-low-filter"><i class="bi bi-exclamation-triangle me-1"></i>Stok Menipis</a>
        <div class="ms-auto d-flex align-items-center gap-1">
          <label class="small text-muted mb-0" for="perPageSelect">Tampilkan</label>
          <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()" data-testid="part-per-page">
            <?php foreach ($per_page_opts as $opt): ?>
              <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?>/hlm</option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="parts-table">
        <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Rak</th><th class="text-end">H. Beli</th><th class="text-end">H. Jual</th><th class="text-end">Stok</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">Belum ada data sparepart.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $low = $r['stok'] <= $r['stok_min']; ?>
          <tr class="<?= $low ? 'table-danger' : '' ?>">
            <td><?= esc($r['kode']) ?></td>
            <td><?= esc($r['nama']) ?><?php if ($r['barcode']): ?> <i class="bi bi-upc text-muted" title="<?= esc($r['barcode']) ?>"></i><?php endif; ?></td>
            <td><?= esc($r['kategori']) ?></td>
            <td data-testid="part-rak-<?= $r['id'] ?>">
              <?php if (!empty($r['lokasi_rak'])): ?>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-geo-alt me-1"></i><?= esc($r['lokasi_rak']) ?></span>
              <?php else: ?>
                <span class="text-muted small">-</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= rupiah($r['harga_beli']) ?></td>
            <td class="text-end"><?= rupiah($r['harga_jual']) ?></td>
            <td class="text-end"><span class="badge bg-<?= $low ? 'danger' : 'success' ?>" data-testid="part-stok-badge-<?= $r['id'] ?>"><?= $r['stok'] ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=parts&edit=<?= $r['id'] ?>" data-testid="part-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus sparepart ini?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="part-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php
        // --- Kontrol pagination ------------------------------------------
        $from_no = $total_rows ? ($offset + 1) : 0;
        $to_no   = min($offset + $per_page, $total_rows);
      ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2" data-testid="parts-pagination">
        <div class="small text-muted" data-testid="parts-pagination-info">
          Menampilkan <strong><?= $from_no ?>&ndash;<?= $to_no ?></strong> dari <strong><?= $total_rows ?></strong> sparepart
          <?php if ($q !== '' || $filter !== ''): ?>
            (hasil filter<?php if ($q !== ''): ?> pencarian "<?= esc($q) ?>"<?php endif; ?><?php if ($filter === 'low'): ?> stok menipis<?php endif; ?>)
          <?php endif; ?>
        </div>
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Pagination sparepart">
          <ul class="pagination pagination-sm mb-0" data-testid="parts-pagination-nav">
            <li class="page-item <?= $cur_page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $cur_page > 1 ? esc($paginate_url($cur_page - 1)) : '#' ?>" data-testid="parts-page-prev" aria-label="Sebelumnya">
                <i class="bi bi-chevron-left"></i>
              </a>
            </li>
            <?php
              // Tampilkan jendela halaman di sekitar halaman aktif (+/- 2)
              $start = max(1, $cur_page - 2);
              $end   = min($total_pages, $cur_page + 2);
              if ($start > 1):
            ?>
              <li class="page-item"><a class="page-link" href="<?= esc($paginate_url(1)) ?>" data-testid="parts-page-1">1</a></li>
              <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($i = $start; $i <= $end; $i++): ?>
              <li class="page-item <?= $i === $cur_page ? 'active' : '' ?>">
                <a class="page-link" href="<?= esc($paginate_url($i)) ?>" data-testid="parts-page-<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
              <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
              <li class="page-item"><a class="page-link" href="<?= esc($paginate_url($total_pages)) ?>" data-testid="parts-page-<?= $total_pages ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $cur_page >= $total_pages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $cur_page < $total_pages ? esc($paginate_url($cur_page + 1)) : '#' ?>" data-testid="parts-page-next" aria-label="Berikutnya">
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

<!-- Modal scan barcode via kamera HP -->
<div class="modal fade" id="scanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Scan Barcode</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div id="scanner" style="width:100%"></div></div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let scannerObj = null, scanTarget = null;
const scanModal = document.getElementById('scanModal');
scanModal.addEventListener('shown.bs.modal', e => {
  scanTarget = e.relatedTarget.dataset.scanTarget;
  scannerObj = new Html5Qrcode("scanner");
  scannerObj.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, text => {
    document.getElementById(scanTarget).value = text;
    bootstrap.Modal.getInstance(scanModal).hide();
  }, () => {});
});
scanModal.addEventListener('hidden.bs.modal', () => { if (scannerObj) scannerObj.stop().catch(()=>{}); });

// Import Excel/CSV di sisi browser (SheetJS), hasil dikirim JSON ke server.
// Alur: pilih file -> minta preview -> user klik "Konfirmasi Import" -> commit.
let importState = { rows: [], filename: '', mode: 'baru' };

document.getElementById('importFile').addEventListener('change', function() {
  const f = this.files[0]; if (!f) return;
  const mode = (document.querySelector('input[name="importMode"]:checked') || {}).value || 'baru';
  const reader = new FileReader();
  reader.onload = async e => {
    const wb = XLSX.read(e.target.result, { type: 'array' });
    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]]);
    importState = { rows, filename: f.name, mode };
    await requestPreview();
  };
  reader.readAsArrayBuffer(f);
});

async function requestPreview() {
  const el = document.getElementById('importResult');
  el.innerHTML = '<div class="alert alert-info py-2"><span class="spinner-border spinner-border-sm me-2"></span>Menyiapkan pratinjau...</div>';
  const res = await fetch('ajax/import_parts.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rows: importState.rows, mode: importState.mode, preview: 1, filename: importState.filename })
  });
  const data = await res.json();
  el.innerHTML = '';
  if (!data.ok || !data.preview) {
    el.innerHTML = '<div class="alert alert-danger py-2">' + (data.message || 'Gagal memuat pratinjau.') + '</div>';
    return;
  }
  renderPreviewModal(data);
}

function renderPreviewModal(data) {
  const s = data.summary || {};
  const badgeMap = {
    new:     '<span class="badge bg-success" data-testid="preview-badge-new">Baru</span>',
    update:  '<span class="badge bg-warning text-dark" data-testid="preview-badge-update">Update</span>',
    skip:    '<span class="badge bg-secondary" data-testid="preview-badge-skip">Dilewati</span>',
    invalid: '<span class="badge bg-danger" data-testid="preview-badge-invalid">Invalid</span>',
  };
  const bodyRows = (data.rows || []).map(function (r) {
    return '<tr data-testid="preview-row" data-status="' + r.status + '">'
      + '<td class="small text-muted">' + r.row + '</td>'
      + '<td><code>' + escHtml(r.kode) + '</code></td>'
      + '<td class="small">' + escHtml(r.nama) + '</td>'
      + '<td class="small">' + escHtml(r.kategori) + '</td>'
      + '<td class="text-end small">' + r.stok + '</td>'
      + '<td>' + (badgeMap[r.status] || r.status) + '</td>'
      + '<td class="small text-muted">' + escHtml(r.reason) + '</td>'
      + '</tr>';
  }).join('');
  const html =
    '<div class="modal fade" id="importPreviewModal" tabindex="-1" data-testid="import-preview-modal">'
    + '<div class="modal-dialog modal-xl modal-dialog-scrollable">'
    + '<div class="modal-content">'
    + '<div class="modal-header">'
    + '<h5 class="modal-title"><i class="bi bi-clipboard-data me-1"></i>Pratinjau Import — mode <strong>' + (data.mode === 'baru' ? 'Baru' : 'Upgrade') + '</strong></h5>'
    + '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
    + '</div>'
    + '<div class="modal-body">'
    + '<div class="d-flex gap-2 mb-2 flex-wrap" data-testid="import-preview-summary">'
    +   '<span class="badge bg-primary">Total: ' + (s.total||0) + '</span>'
    +   '<span class="badge bg-success" data-testid="preview-summary-new">Baru: ' + (s.new||0) + '</span>'
    +   '<span class="badge bg-warning text-dark" data-testid="preview-summary-update">Update: ' + (s.update||0) + '</span>'
    +   '<span class="badge bg-secondary" data-testid="preview-summary-skip">Dilewati: ' + (s.skip||0) + '</span>'
    +   '<span class="badge bg-danger" data-testid="preview-summary-invalid">Invalid: ' + (s.invalid||0) + '</span>'
    + '</div>'
    + '<div class="table-responsive" style="max-height:60vh">'
    + '<table class="table table-sm align-middle" data-testid="import-preview-table">'
    + '<thead><tr><th>#</th><th>Kode</th><th>Nama</th><th>Kategori</th><th class="text-end">Stok</th><th>Status</th><th>Catatan</th></tr></thead>'
    + '<tbody>' + bodyRows + '</tbody></table></div></div>'
    + '<div class="modal-footer">'
    + '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-testid="import-preview-cancel">Batal</button>'
    + '<button type="button" id="importPreviewConfirm" class="btn btn-primary" data-testid="import-preview-confirm">'
    +   '<i class="bi bi-check2-circle me-1"></i>Konfirmasi Import (' + ((s.new||0)+(s.update||0)) + ' baris)'
    + '</button>'
    + '</div></div></div></div>';
  // Hapus modal lama jika ada, lalu render baru dan tampilkan.
  const old = document.getElementById('importPreviewModal'); if (old) old.remove();
  document.body.insertAdjacentHTML('beforeend', html);
  const modalEl = document.getElementById('importPreviewModal');
  const modal = new bootstrap.Modal(modalEl);
  modalEl.querySelector('#importPreviewConfirm').addEventListener('click', function () { confirmImport(modal); });
  modal.show();
}

async function confirmImport(modal) {
  const el = document.getElementById('importResult');
  el.innerHTML = '<div class="alert alert-info py-2"><span class="spinner-border spinner-border-sm me-2"></span>Menyimpan import...</div>';
  const res = await fetch('ajax/import_parts.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rows: importState.rows, mode: importState.mode, filename: importState.filename })
  });
  const data = await res.json();
  el.innerHTML = '<div class="alert alert-' + (data.ok ? 'success' : 'danger') + ' py-2">' + data.message + '</div>';
  if (modal) modal.hide();
  if (data.ok) setTimeout(() => location.reload(), 1500);
}

function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

// Update hint teks sesuai mode terpilih
document.querySelectorAll('input[name="importMode"]').forEach(function (r) {
  r.addEventListener('change', function () {
    const hint = document.getElementById('importModeHint');
    if (!hint) return;
    hint.textContent = r.value === 'upgrade'
      ? 'Mode "Upgrade": kode yang sudah ada akan diperbarui datanya (nama, kategori, harga, stok), yang belum ada akan ditambahkan.'
      : 'Mode "Baru": hanya menambahkan sparepart baru, kode yang sudah ada akan dilewati.';
  });
});

function downloadTemplate(mode) {
  mode = mode || 'baru';
  const header = "kode,nama,kategori,harga_beli,harga_jual,stok,stok_min,barcode,lokasi_rak";
  let content, filename;
  if (mode === 'upgrade') {
    content = header + "\n"
      + "OLI-MPX,Oli MPX 0.8L (update),Oli,36000,48000,25,5,899123456001,R-01\n"
      + "KMP-BARU,Kampas Rem Baru,Kampas Rem,45000,65000,10,5,899123456999,R-05\n";
    filename = 'template_sparepart_upgrade.csv';
  } else {
    content = header + "\n"
      + "OLI-MPX,Oli MPX 0.8L,Oli,35000,45000,10,5,899123456001,R-01\n"
      + "BSI-01,Busi Standar NGK,Busi,15000,25000,20,5,899123456002,R-02\n";
    filename = 'template_sparepart_baru.csv';
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([content], { type: 'text/csv' }));
  a.download = filename; a.click();
}

// ============================================================
// Cari sparepart dari hargasukucadang.online -> isi Kode & Nama otomatis
// ============================================================
(function () {
  const q = document.getElementById('hscQuery');
  const btn = document.getElementById('hscSearchBtn');
  const box = document.getElementById('hscResults');
  const msg = document.getElementById('hscMsg');
  const filters = document.getElementById('hscFilters');
  const syncBtn = document.getElementById('hscSyncNowBtn');
  const lastSyncEl = document.getElementById('hscLastSync');
  if (!q || !btn) return;

  const form = document.querySelector('form[data-testid="part-form"]');
  const kodeInput = form ? form.querySelector('[name="kode"]') : null;
  const namaInput = form ? form.querySelector('[name="nama"]') : null;
  const katSelect = form ? form.querySelector('[name="kategori"]') : null;
  const hargaJualInput = form ? form.querySelector('[name="harga_jual"]') : null;

  let lastResults = [];      // hasil terakhir dari server
  let activeSumberFilter = ''; // filter sumber yang sedang aktif ('' = semua)

  function showMsg(t, cls) { msg.className = 'small mt-1 ' + (cls || 'text-muted'); msg.textContent = t; msg.style.display = t ? 'block' : 'none'; }
  function clearResults() { box.innerHTML = ''; box.style.display = 'none'; filters.innerHTML = ''; filters.style.display = 'none'; }
  function esc(s) { return (s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function sumberLabel(s) { return s === 'hcg' ? 'hondacengkareng.com' : 'hargasukucadang.online'; }
  function sumberBadge(s) { return s === 'hcg'
      ? '<span class="badge bg-danger" style="font-size:9px">HondaCengkareng</span>'
      : '<span class="badge bg-primary" style="font-size:9px">HargaSukuCadang</span>'; }

  function setKategori(val) {
    if (!katSelect || !val) return;
    var v = String(val).trim();
    if (!v) return;
    var found = false;
    for (var i = 0; i < katSelect.options.length; i++) {
      if (katSelect.options[i].value === v) { found = true; break; }
    }
    if (!found) {
      var opt = document.createElement('option');
      opt.value = v; opt.textContent = v + ' (online)';
      opt.setAttribute('data-hsc', '1');
      katSelect.appendChild(opt);
    }
    katSelect.value = v;
  }

  function renderFilters(rows) {
    // Filter berdasarkan SUMBER (hargasukucadang / hondacengkareng).
    const counts = new Map();
    rows.forEach(function (r) { const s = (r.sumber || 'hsc'); counts.set(s, (counts.get(s) || 0) + 1); });
    filters.innerHTML = '';
    if (rows.length === 0) { filters.style.display = 'none'; return; }
    const mkChip = function (label, val, count, isActive) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-sm ' + (isActive ? 'btn-dark' : 'btn-outline-secondary') + ' py-0 px-2';
      b.style.fontSize = '11px';
      b.setAttribute('data-testid', 'hsc-source-chip');
      b.setAttribute('data-sumber', val);
      b.innerHTML = esc(label) + ' <span class="badge bg-light text-dark ms-1" style="font-size:10px">' + count + '</span>';
      b.addEventListener('click', function () { activeSumberFilter = val; renderResults(); });
      return b;
    };
    filters.appendChild(mkChip('Semua Sumber', '', rows.length, activeSumberFilter === ''));
    filters.appendChild(mkChip('HargaSukuCadang', 'hsc', counts.get('hsc') || 0, activeSumberFilter === 'hsc'));
    filters.appendChild(mkChip('HondaCengkareng', 'hcg', counts.get('hcg') || 0, activeSumberFilter === 'hcg'));
    filters.style.display = 'flex';
  }

  function renderResults() {
    const rows = activeSumberFilter
      ? lastResults.filter(function (r) { return (r.sumber || 'hsc') === activeSumberFilter; })
      : lastResults;
    renderFilters(lastResults);
    box.innerHTML = '';
    if (!rows.length) {
      box.style.display = 'none';
      showMsg('Tidak ada hasil setelah filter sumber.', 'text-warning');
      return;
    }
    rows.forEach(function (it) {
      const st = (it.status || '').toUpperCase();
      const badge = (st === 'AKTIF' || st === 'TERSEDIA') ? 'success' : (st === 'STOP' || st === 'HABIS' ? 'secondary' : 'info');
      const el = document.createElement('button');
      el.type = 'button';
      el.className = 'list-group-item list-group-item-action py-1';
      el.setAttribute('data-testid', 'hsc-result-item');
      el.innerHTML =
        '<div class="d-flex justify-content-between align-items-center">' +
        '<span class="fw-semibold small">' + esc(it.kode) + '</span>' +
        '<span>' + sumberBadge(it.sumber) + ' <span class="badge bg-' + badge + '" style="font-size:10px">' + esc(it.status || '-') + '</span></span></div>' +
        '<div class="small">' + esc(it.nama) + '</div>' +
        '<div class="text-muted" style="font-size:10px">' + (it.tipe ? ('Kategori: ' + esc(it.tipe) + ' &bull; ') : '') + 'Harga: ' + esc(it.harga || '-') + '</div>';
      el.addEventListener('click', function () {
        if (kodeInput) kodeInput.value = it.kode;
        if (namaInput) namaInput.value = it.nama;
        setKategori(it.tipe);
        // Harga jual otomatis terisi dari harga referensi kedua sumber (bila tersedia).
        var hargaTxt = '';
        if (hargaJualInput && it.harga_num && parseInt(it.harga_num) > 0) {
          hargaJualInput.value = parseInt(it.harga_num);
          hargaTxt = ' [Harga jual: Rp ' + parseInt(it.harga_num).toLocaleString('id-ID') + ']';
        }
        clearResults();
        showMsg('Terisi dari ' + sumberLabel(it.sumber) + ': ' + it.kode + ' - ' + it.nama + hargaTxt, 'text-success');
        if (namaInput) namaInput.focus();
      });
      box.appendChild(el);
    });
    box.style.display = 'block';
  }

  async function doSearch() {
    const term = q.value.trim();
    if (term.length < 2) { showMsg('Kata kunci minimal 2 karakter.', 'text-danger'); clearResults(); return; }
    showMsg('Mencari di hargasukucadang.online & hondacengkareng.com...'); clearResults(); btn.disabled = true;
    try {
      const url = 'ajax/lookup_hsc.php?field=auto&q=' + encodeURIComponent(term);
      const r = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
      if (!r.ok) { btn.disabled = false; showMsg('Server sibuk / error (HTTP ' + r.status + '). Coba lagi atau hubungi admin.', 'text-danger'); return; }
      const data = await r.json();
      btn.disabled = false;
      if (data.error) { showMsg(data.error, 'text-danger'); return; }
      lastResults = data.results || [];
      activeSumberFilter = '';
      if (!lastResults.length) { showMsg('Tidak ada hasil untuk "' + term + '".', 'text-warning'); return; }
      if (data.offline) {
        showMsg('Situs sumber sedang offline. Menampilkan ' + lastResults.length + ' hasil dari katalog lokal.', 'text-warning');
      } else {
        showMsg(lastResults.length + ' hasil dari 2 sumber. Klik salah satu untuk memakainya, atau filter per sumber di atas.', 'text-success');
      }
      renderResults();
    } catch (e) {
      btn.disabled = false;
      showMsg('Gagal memuat hasil: ' + e.message + '. Periksa koneksi internet server.', 'text-danger');
    }
  }

  btn.addEventListener('click', doSearch);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

  // Sync Sekarang: refresh katalog dari KEDUA sumber on-demand.
  if (syncBtn) {
    syncBtn.addEventListener('click', async function () {
      const original = syncBtn.innerHTML;
      syncBtn.disabled = true;
      syncBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Sync berjalan...';
      try {
        const r = await fetch('ajax/sync_hsc.php', { method: 'POST' });
        const data = await r.json();
        if (data.ok) {
          const s = data.result || {};
          showMsg('Sync selesai: ' + (s.rows || 0) + ' baris katalog (HCG: ' + (s.hcg_rows || 0) + '). ' + (s.parts_inserted || 0) + ' sparepart baru masuk ke database. +' + (s.inserted || 0) + ' katalog baru.', 'text-success');
          setTimeout(function(){ location.reload(); }, 1800);
          if (lastSyncEl && data.last_sync) {
            const d = new Date(data.last_sync);
            lastSyncEl.textContent = d.toLocaleString('id-ID');
          }
        } else {
          showMsg('Sync gagal.', 'text-danger');
        }
      } catch (e) {
        showMsg('Sync gagal: ' + e.message, 'text-danger');
      } finally {
        syncBtn.disabled = false;
        syncBtn.innerHTML = original;
      }
    });
  }
})();
</script>
