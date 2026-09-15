<?php
// ============================================================
// Pengaturan aplikasi (khusus admin):
// - Identitas bengkel: nama, NIB, pemilik, alamat, telepon
// - Tema warna gradasi: geser slider hue untuk mengubah warna
//   sidebar, halaman login, nota, dan laporan secara langsung
// ============================================================
require_admin();

// ---- Upload logo bengkel (JPG/PNG/WEBP/GIF, maks 2 MB) ----
if (($_POST['action'] ?? '') === 'upload_logo') {
    $err = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        // Tangani semua kode error upload (termasuk file >2MB yang ditolak PHP)
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            set_flash('danger', 'Ukuran logo melebihi batas 2 MB.');
        } elseif ($err === UPLOAD_ERR_NO_FILE) {
            set_flash('danger', 'Pilih file logo terlebih dahulu.');
        } else {
            set_flash('danger', 'Upload logo gagal. Coba lagi.');
        }
        header('Location: index.php?page=settings'); exit;
    }
    $f = $_FILES['logo'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($f['tmp_name']);
    if (!isset($allowed[$mime])) {
        set_flash('danger', 'Format logo harus JPG, PNG, WEBP, atau GIF.');
    } elseif ($f['size'] > 2 * 1024 * 1024) {
        set_flash('danger', 'Ukuran logo maksimal 2 MB.');
    } else {
        if (!is_dir(__DIR__ . '/../uploads')) mkdir(__DIR__ . '/../uploads', 0775, true);
        // Hapus file logo lama agar tidak menumpuk
        $lama = setting('logo');
        if ($lama && is_file(__DIR__ . '/../' . $lama)) unlink(__DIR__ . '/../' . $lama);
        $path = 'uploads/logo_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $path);
        set_setting('logo', $path);
        set_flash('success', 'Logo bengkel berhasil diunggah.');
    }
    header('Location: index.php?page=settings'); exit;
}
if (($_POST['action'] ?? '') === 'remove_logo') {
    $lama = setting('logo');
    if ($lama && is_file(__DIR__ . '/../' . $lama)) unlink(__DIR__ . '/../' . $lama);
    set_setting('logo', '');
    set_flash('success', 'Logo dihapus.');
    header('Location: index.php?page=settings'); exit;
}

// ---- Upload gambar stempel / tanda tangan faktur (PNG transparan disarankan) ----
if (($_POST['action'] ?? '') === 'upload_stempel') {
    $err = $_FILES['stempel']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            set_flash('danger', 'Ukuran gambar stempel melebihi batas 2 MB.');
        } elseif ($err === UPLOAD_ERR_NO_FILE) {
            set_flash('danger', 'Pilih file gambar stempel terlebih dahulu.');
        } else {
            set_flash('danger', 'Upload stempel gagal. Coba lagi.');
        }
        header('Location: index.php?page=settings'); exit;
    }
    $f = $_FILES['stempel'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($f['tmp_name']);
    if (!isset($allowed[$mime])) {
        set_flash('danger', 'Format stempel harus JPG, PNG, WEBP, atau GIF (PNG transparan disarankan).');
    } elseif ($f['size'] > 2 * 1024 * 1024) {
        set_flash('danger', 'Ukuran gambar maksimal 2 MB.');
    } else {
        if (!is_dir(__DIR__ . '/../uploads')) mkdir(__DIR__ . '/../uploads', 0775, true);
        $lama = setting('stempel');
        if ($lama && is_file(__DIR__ . '/../' . $lama)) unlink(__DIR__ . '/../' . $lama);
        $path = 'uploads/stempel_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $path);
        set_setting('stempel', $path);
        set_flash('success', 'Gambar stempel faktur berhasil diunggah.');
    }
    header('Location: index.php?page=settings'); exit;
}
if (($_POST['action'] ?? '') === 'remove_stempel') {
    $lama = setting('stempel');
    if ($lama && is_file(__DIR__ . '/../' . $lama)) unlink(__DIR__ . '/../' . $lama);
    set_setting('stempel', '');
    set_flash('success', 'Gambar stempel dihapus.');
    header('Location: index.php?page=settings'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    foreach (['nama_bengkel', 'nib', 'pemilik', 'alamat', 'telepon'] as $k) {
        set_setting($k, trim($_POST[$k] ?? ''));
    }
    // Hue dibatasi 0-359 derajat
    set_setting('theme_h1', (string)min(359, max(0, (int)($_POST['theme_h1'] ?? 210))));
    set_setting('theme_h2', (string)min(359, max(0, (int)($_POST['theme_h2'] ?? 232))));
    $mp = $_POST['menu_position'] ?? 'top';
    if (!in_array($mp, ['top', 'left', 'right'], true)) $mp = 'top';
    set_setting('menu_position', $mp);
    set_flash('success', 'Pengaturan berhasil disimpan.');
    header('Location: index.php?page=settings'); exit;
}

// ---- Ubah urutan susunan MENU (tombol naik/turun, tersimpan global di DB) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'menu_order_move') {
    $move = $_POST['move'] ?? '';
    [$key, $dir] = array_pad(explode(':', $move, 2), 2, '');
    $keys = array_column(bengkel_nav_items(), 0);
    // Bangun urutan saat ini (hanya key valid), lalu tambahkan key baru di akhir
    $ordered = [];
    foreach (array_filter(array_map('trim', explode(',', setting('menu_order', '')))) as $k) {
        if (in_array($k, $keys, true) && !in_array($k, $ordered, true)) $ordered[] = $k;
    }
    foreach ($keys as $k) if (!in_array($k, $ordered, true)) $ordered[] = $k;
    $idx = array_search($key, $ordered, true);
    if ($idx !== false) {
        if ($dir === 'up' && $idx > 0) {
            [$ordered[$idx - 1], $ordered[$idx]] = [$ordered[$idx], $ordered[$idx - 1]];
        } elseif ($dir === 'down' && $idx < count($ordered) - 1) {
            [$ordered[$idx + 1], $ordered[$idx]] = [$ordered[$idx], $ordered[$idx + 1]];
        }
        set_setting('menu_order', implode(',', $ordered));
        set_flash('success', 'Urutan menu diperbarui.');
    }
    header('Location: index.php?page=settings'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'menu_order_reset') {
    set_setting('menu_order', '');
    set_flash('success', 'Urutan menu dikembalikan ke bawaan.');
    header('Location: index.php?page=settings'); exit;
}

$h1 = (int)setting('theme_h1', '210');
$h2 = (int)setting('theme_h2', '232');
?>
<form method="post" data-testid="settings-form">
<input type="hidden" name="action" value="save">
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-shop me-1"></i>Identitas Bengkel</h2>
      <p class="small text-muted">Informasi ini tampil pada sidebar, halaman login, struk nota, bukti garansi, dan laporan.</p>
      <div class="mb-2"><label class="form-label small">Nama Aplikasi / Bengkel</label>
        <input name="nama_bengkel" class="form-control form-control-sm" value="<?= esc(setting('nama_bengkel')) ?>" required data-testid="setting-nama"></div>
      <div class="mb-2"><label class="form-label small">NIB (Nomor Induk Berusaha)</label>
        <input name="nib" class="form-control form-control-sm" value="<?= esc(setting('nib')) ?>" placeholder="Opsional" data-testid="setting-nib"></div>
      <div class="mb-2"><label class="form-label small">Nama Pemilik</label>
        <input name="pemilik" class="form-control form-control-sm" value="<?= esc(setting('pemilik')) ?>" data-testid="setting-pemilik"></div>
      <div class="mb-2"><label class="form-label small">Alamat</label>
        <textarea name="alamat" class="form-control form-control-sm" rows="2" data-testid="setting-alamat"><?= esc(setting('alamat')) ?></textarea></div>
      <div class="mb-3"><label class="form-label small">Telepon / WA</label>
        <input name="telepon" class="form-control form-control-sm" value="<?= esc(setting('telepon')) ?>" data-testid="setting-telepon"></div>
      <div class="mb-2"><label class="form-label small"><i class="bi bi-layout-sidebar me-1"></i>Posisi Menu</label>
        <select name="menu_position" class="form-select form-select-sm" data-testid="setting-menu-position">
          <option value="top" <?= setting('menu_position','top')==='top'?'selected':'' ?>>Atas (Top) — Rekomendasi, area data lebih luas</option>
          <option value="left" <?= setting('menu_position','top')==='left'?'selected':'' ?>>Kiri (Left)</option>
          <option value="right" <?= setting('menu_position','top')==='right'?'selected':'' ?>>Kanan (Right)</option>
        </select>
        <div class="form-text small">Posisi <strong>Atas</strong> membuat area data jauh lebih luas dan tidak terlalu mepet.</div>
      </div>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-palette me-1"></i>Tema Warna Gradasi</h2>
      <p class="small text-muted">Geser slider untuk mengubah warna dasar aplikasi. Perubahan langsung terlihat pada preview di bawah.</p>
      <div class="mb-3">
        <label class="form-label small d-flex justify-content-between">Warna Utama <span class="badge bg-secondary" id="h1Val" data-testid="h1-value"><?= $h1 ?>°</span></label>
        <input type="range" class="form-range" min="0" max="359" id="theme_h1" name="theme_h1" value="<?= $h1 ?>" data-testid="theme-h1-slider">
      </div>
      <div class="mb-3">
        <label class="form-label small d-flex justify-content-between">Warna Kedua (Gradasi) <span class="badge bg-secondary" id="h2Val" data-testid="h2-value"><?= $h2 ?>°</span></label>
        <input type="range" class="form-range" min="0" max="359" id="theme_h2" name="theme_h2" value="<?= $h2 ?>" data-testid="theme-h2-slider">
      </div>
      <div id="themePreview" class="rounded p-3 text-white mb-3" data-testid="theme-preview"
           style="background:linear-gradient(165deg, hsl(<?= $h1 ?> 60% 18%), hsl(<?= $h2 ?> 65% 32%));min-height:90px">
        <strong><?= esc(setting('nama_bengkel', 'Bengkel Motor')) ?></strong>
        <div class="small mt-1"><span class="badge rounded-pill" style="background:rgba(255,255,255,.25)">Preview Sidebar & Login</span></div>
      </div>
      <div class="d-flex flex-wrap gap-1 mb-2">
        <?php
        // Preset tema cepat: [label, hue1, hue2]
        foreach ([['Biru Gelap',210,232],['Hijau',150,170],['Merah Marun',350,15],['Ungu',265,285],['Oranye',20,40],['Toska',175,195]] as $p): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPreset(<?= $p[1] ?>,<?= $p[2] ?>)" data-testid="preset-<?= $p[1] ?>"><?= $p[0] ?></button>
        <?php endforeach; ?>
      </div>
    </div></div>
  </div>
</div>
<button class="btn btn-primary mt-3" data-testid="settings-submit"><i class="bi bi-check2-circle me-1"></i>Simpan Pengaturan</button>
</form>

<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-list-ol me-1"></i>Urutan Susunan Menu</h2>
        <form method="post" onsubmit="return confirm('Kembalikan urutan menu ke bawaan?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="menu_order_reset">
          <button class="btn btn-sm btn-outline-secondary" data-testid="menu-order-reset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset ke Bawaan</button>
        </form>
      </div>
      <p class="small text-muted mb-3">Atur urutan menu navigasi dengan tombol naik/turun. Perubahan berlaku untuk semua pengguna.</p>
      <?php $menus = bengkel_nav_items_ordered(); $lastIdx = count($menus) - 1; ?>
      <ul class="list-group" data-testid="menu-order-list">
        <?php foreach ($menus as $i => $m): ?>
        <li class="list-group-item d-flex align-items-center justify-content-between py-2" data-testid="menu-order-item-<?= $m[0] ?>">
          <span class="d-flex align-items-center">
            <span class="badge bg-light text-muted me-2" style="width:26px"><?= $i + 1 ?></span>
            <i class="bi <?= esc($m[2]) ?> me-2"></i>
            <span class="fw-semibold"><?= esc($m[3]) ?></span>
            <?php if ($m[4]): ?><span class="badge bg-secondary ms-2">Admin</span><?php endif; ?>
          </span>
          <span class="btn-group">
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="menu_order_move">
              <input type="hidden" name="move" value="<?= esc($m[0]) ?>:up">
              <button class="btn btn-sm btn-outline-primary" <?= $i === 0 ? 'disabled' : '' ?> title="Naik" data-testid="menu-up-<?= $m[0] ?>"><i class="bi bi-arrow-up"></i></button>
            </form>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="menu_order_move">
              <input type="hidden" name="move" value="<?= esc($m[0]) ?>:down">
              <button class="btn btn-sm btn-outline-primary" <?= $i === $lastIdx ? 'disabled' : '' ?> title="Turun" data-testid="menu-down-<?= $m[0] ?>"><i class="bi bi-arrow-down"></i></button>
            </form>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-image me-1"></i>Logo Bengkel</h2>
      <p class="small text-muted">Logo tampil pada sidebar, halaman login, struk nota, dan bukti klaim garansi. Format JPG/PNG/WEBP/GIF, maks 2 MB.</p>
      <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
      <div class="mb-2"><img src="<?= esc($logo) ?>" alt="Logo Bengkel" class="border rounded p-2" style="max-height:90px" data-testid="logo-preview"></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2" data-testid="logo-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo">
        <input type="file" name="logo" accept="image/*" class="form-control form-control-sm" required data-testid="logo-file">
        <button class="btn btn-sm btn-primary text-nowrap" data-testid="logo-upload-btn"><i class="bi bi-upload me-1"></i>Upload</button>
      </form>
      <?php if ($logo): ?>
      <form method="post" class="mt-2" onsubmit="return confirm('Hapus logo saat ini?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_logo">
        <button class="btn btn-sm btn-outline-danger" data-testid="logo-remove-btn"><i class="bi bi-trash me-1"></i>Hapus Logo</button>
      </form>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-patch-check me-1"></i>Stempel / Tanda Tangan Faktur</h2>
      <p class="small text-muted">Gambar stempel bengkel (mis. stempel basah / tanda tangan) tampil menimpa border faktur di area tanda tangan agar makin otentik & anti pemalsuan. Disarankan PNG latar transparan, maks 2 MB.</p>
      <?php $stempel = setting('stempel'); if ($stempel && is_file(__DIR__ . '/../' . $stempel)): ?>
      <div class="mb-2"><img src="<?= esc($stempel) ?>" alt="Stempel Bengkel" class="border rounded p-2" style="max-height:90px" data-testid="stempel-preview"></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2" data-testid="stempel-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_stempel">
        <input type="file" name="stempel" accept="image/*" class="form-control form-control-sm" required data-testid="stempel-file">
        <button class="btn btn-sm btn-primary text-nowrap" data-testid="stempel-upload-btn"><i class="bi bi-upload me-1"></i>Upload</button>
      </form>
      <?php if ($stempel): ?>
      <form method="post" class="mt-2" onsubmit="return confirm('Hapus gambar stempel saat ini?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_stempel">
        <button class="btn btn-sm btn-outline-danger" data-testid="stempel-remove-btn"><i class="bi bi-trash me-1"></i>Hapus Stempel</button>
      </form>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-database-gear me-1"></i>Backup, Restore & Format Database</h2>

      <div class="mb-4">
        <h3 class="h6 small fw-bold text-uppercase text-muted"><i class="bi bi-database-down me-1"></i>Backup</h3>
        <p class="small text-muted mb-2">Unduh cadangan seluruh data (pelanggan, sparepart, transaksi, garansi, pengaturan, dll) dalam berkas <code>.sql</code>. Simpan di tempat aman; dapat di-import kembali di sini atau via phpMyAdmin.</p>
        <a href="backup.php" class="btn btn-sm btn-primary" data-testid="backup-db-btn"><i class="bi bi-download me-1"></i>Unduh Backup (.sql)</a>
      </div>

      <div class="mb-4 border-top pt-3">
        <h3 class="h6 small fw-bold text-uppercase text-muted"><i class="bi bi-database-up me-1"></i>Restore</h3>
        <p class="small text-muted mb-2">Impor berkas <code>.sql</code> (hasil backup aplikasi ini atau ekspor phpMyAdmin). <strong class="text-danger">Data saat ini akan ditimpa</strong> oleh isi berkas.</p>
        <form method="post" action="db_admin.php" enctype="multipart/form-data" onsubmit="return confirm('Yakin restore database? Seluruh data saat ini akan ditimpa oleh isi berkas .sql.');" data-testid="restore-db-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restore">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="file" name="sqlfile" accept=".sql" required class="form-control form-control-sm" style="max-width:320px" data-testid="restore-file">
            <button class="btn btn-sm btn-warning" data-testid="restore-db-btn"><i class="bi bi-upload me-1"></i>Restore dari Berkas</button>
          </div>
        </form>
      </div>

      <div class="border-top pt-3">
        <h3 class="h6 small fw-bold text-uppercase text-danger"><i class="bi bi-exclamation-octagon me-1"></i>Format Database (Zona Berbahaya)</h3>
        <p class="small text-muted mb-2">Mengosongkan <strong>seluruh data</strong> (pelanggan, sparepart, transaksi, hutang/piutang, kas, dll) untuk memulai instalasi baru. Akun pengguna &amp; pengaturan <strong>tetap dipertahankan</strong>. Tindakan ini <strong class="text-danger">tidak dapat dibatalkan</strong> — unduh backup dahulu.</p>
        <form method="post" action="db_admin.php" onsubmit="return confirm('PERINGATAN: Seluruh data akan dihapus permanen. Lanjutkan format database?');" data-testid="format-db-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="format">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="text" name="confirm" placeholder="Ketik FORMAT untuk konfirmasi" required class="form-control form-control-sm" style="max-width:260px" data-testid="format-confirm-input">
            <button class="btn btn-sm btn-danger" data-testid="format-db-btn"><i class="bi bi-trash3 me-1"></i>Format / Kosongkan Database</button>
          </div>
        </form>
      </div>
    </div></div>
  </div>
</div>

<script>
// Live preview gradasi saat slider digeser
const h1 = document.getElementById('theme_h1'), h2 = document.getElementById('theme_h2');
const prev = document.getElementById('themePreview');
function updatePreview() {
  prev.style.background = `linear-gradient(165deg, hsl(${h1.value} 60% 18%), hsl(${h2.value} 65% 32%))`;
  document.getElementById('h1Val').textContent = h1.value + '°';
  document.getElementById('h2Val').textContent = h2.value + '°';
}
function setPreset(a, b) { h1.value = a; h2.value = b; updatePreview(); }
h1.addEventListener('input', updatePreview);
h2.addEventListener('input', updatePreview);
</script>
