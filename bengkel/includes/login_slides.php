<?php
// ============================================================
// login_slides.php - Slider foto pada halaman login.
// Dikelola admin di menu Pengaturan (foto, teks utama, teks detail).
//
// Desain aman untuk instalasi yang sudah berjalan:
//  - TIDAK ada perubahan skema database / migrasi. Daftar slide disimpan
//    sebagai JSON pada tabel `settings` (key: login_slides), sehingga
//    Backup, Restore, dan Format Database tetap berjalan seperti biasa.
//  - Foto disimpan di uploads/slides/. Folder uploads/ sudah dilindungi
//    .htaccess (skrip tidak dapat dieksekusi), sama seperti logo & stempel.
//  - Foto besar (sisi terpanjang > 1920 px) dikecilkan otomatis (bila GD aktif)
//    agar halaman login tetap ringan di shared hosting.
// ============================================================

const LOGIN_SLIDES_MAX       = 10;                 // jumlah slide maksimal
const LOGIN_SLIDE_MAX_BYTES  = 5 * 1024 * 1024;    // 5 MB per foto
const LOGIN_SLIDE_MAX_SIDE   = 1920;               // sisi terpanjang foto (px); lebih besar dikecilkan
const LOGIN_SLIDE_TITLE_MAX  = 120;
const LOGIN_SLIDE_DETAIL_MAX = 300;
const LOGIN_SLIDES_DIR       = 'uploads/slides';

// ---------- Utilitas ----------

// Path foto yang sah (cegah path traversal / penghapusan file sembarang).
function login_slide_path_ok(string $p): bool {
    return (bool)preg_match('#^uploads/slides/slide_[a-f0-9]{12}\.(jpg|png|webp|gif)$#', $p);
}

// Rapikan teks: satu baris, spasi berlebih dibuang, dipotong sesuai batas.
function login_slide_cut($s, int $max): string {
    if (!is_string($s)) return '';
    $t = preg_replace('/\s+/u', ' ', $s);
    $t = trim($t === null ? $s : $t);
    return function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
}

function login_slide_root(): string { return dirname(__DIR__); }

function login_slide_delete_file(string $path): void {
    if (!login_slide_path_ok($path)) return;
    $f = login_slide_root() . '/' . $path;
    if (is_file($f)) @unlink($f);
}

// ---------- Baca / simpan daftar slide ----------

// Seluruh slide (aktif & nonaktif) dalam urutan tampil. Entri rusak dibuang.
function login_slides_all(): array {
    $raw = json_decode(setting('login_slides', '[]'), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $s) {
        if (!is_array($s)) continue;
        $img = (string)($s['image'] ?? '');
        $id  = (string)($s['id'] ?? '');
        if (!login_slide_path_ok($img) || !preg_match('/^[a-f0-9]{8}$/', $id)) continue;
        $out[] = [
            'id'     => $id,
            'image'  => $img,
            'title'  => login_slide_cut($s['title'] ?? '', LOGIN_SLIDE_TITLE_MAX),
            'detail' => login_slide_cut($s['detail'] ?? '', LOGIN_SLIDE_DETAIL_MAX),
            'active' => !empty($s['active']),
        ];
    }
    return array_slice($out, 0, LOGIN_SLIDES_MAX);
}

// Slide yang ditampilkan di halaman login: aktif dan berkas fotonya ada.
function login_slides_active(): array {
    $out = [];
    foreach (login_slides_all() as $s) {
        if ($s['active'] && is_file(login_slide_root() . '/' . $s['image'])) $out[] = $s;
    }
    return $out;
}

function login_slides_save(array $slides): void {
    set_setting('login_slides', json_encode(array_values($slides), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------- Proses foto ----------

// Kecilkan foto lebar. Return true bila $dest berhasil ditulis; false bila
// tidak perlu / tidak bisa (pemanggil lalu memindahkan berkas asli apa adanya).
function login_slide_downscale(string $src, string $mime, string $dest): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    $w = (int)$info[0]; $h = (int)$info[1];
    // Sudah cukup kecil, atau terlalu besar untuk diproses aman di memori hosting.
    if (max($w, $h) <= LOGIN_SLIDE_MAX_SIDE || $w * $h > 25000000) return false;

    $img = null;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($src);
    elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($src);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($src);
    if (!$img) return false;

    // Foto HP menyimpan arah rotasi di EXIF; GD membuangnya saat simpan ulang,
    // jadi putar dulu agar tidak tampil miring. Tanpa EXIF reader: jangan ubah.
    if ($mime === 'image/jpeg') {
        if (!function_exists('exif_read_data')) return false;
        $exif = @exif_read_data($src);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) {
            $r = @imagerotate($img, $rot, 0);
            if ($r) $img = $r;
        }
    }

    $sw = imagesx($img); $sh = imagesy($img);
    $scale = min(1, LOGIN_SLIDE_MAX_SIDE / max($sw, $sh));
    $nw = max(1, (int)round($sw * $scale));
    $nh = max(1, (int)round($sh * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime !== 'image/jpeg') {            // pertahankan transparansi PNG/WEBP
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
    $img = null;                              // lepas memori sedini mungkin

    $ok = false;
    if ($mime === 'image/jpeg') { imageinterlace($dst, true); $ok = @imagejpeg($dst, $dest, 82); }
    elseif ($mime === 'image/png') { $ok = @imagepng($dst, $dest, 6); }
    elseif ($mime === 'image/webp' && function_exists('imagewebp')) { $ok = @imagewebp($dst, $dest, 82); }
    $dst = null;
    if (!$ok && is_file($dest)) @unlink($dest);
    return (bool)$ok;
}

// Validasi + simpan foto unggahan. Return [path, ''] bila sukses, ['', pesan] bila gagal.
function login_slide_store_image($f): array {
    $f = is_array($f) ? $f : [];
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'Ukuran foto melebihi batas 5 MB.'];
    if ($err === UPLOAD_ERR_NO_FILE) return ['', 'Pilih file foto terlebih dahulu.'];
    if ($err !== UPLOAD_ERR_OK) return ['', 'Upload foto gagal. Coba lagi.'];

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return ['', 'Upload foto gagal. Coba lagi.'];
    if ((int)($f['size'] ?? 0) > LOGIN_SLIDE_MAX_BYTES) return ['', 'Ukuran foto maksimal 5 MB.'];

    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $info = @getimagesize($tmp);                       // memastikan berkas benar-benar gambar
    $mime = $info ? (string)($info['mime'] ?? '') : '';
    if (!isset($ext[$mime])) return ['', 'Format foto harus JPG, PNG, WEBP, atau GIF.'];
    if (function_exists('mime_content_type') && !isset($ext[(string)@mime_content_type($tmp)])) {
        return ['', 'Format foto harus JPG, PNG, WEBP, atau GIF.'];
    }
    if ((int)$info[0] < 1 || (int)$info[1] < 1 || (int)$info[0] * (int)$info[1] > 60000000) {
        return ['', 'Dimensi foto tidak valid atau terlalu besar.'];
    }

    $dir = login_slide_root() . '/' . LOGIN_SLIDES_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['', 'Folder uploads/slides tidak dapat dibuat. Periksa izin folder uploads.'];
    }
    $name = 'slide_' . bin2hex(random_bytes(6)) . '.' . $ext[$mime];
    $dest = $dir . '/' . $name;
    if (!($mime !== 'image/gif' && login_slide_downscale($tmp, $mime, $dest))) {
        if (!move_uploaded_file($tmp, $dest)) return ['', 'Foto tidak dapat disimpan. Periksa izin folder uploads.'];
    }
    return [LOGIN_SLIDES_DIR . '/' . $name, ''];
}

// ---------- Handler POST (dipanggil dari pages/settings.php) ----------

function login_slides_handle_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || strpos($action, 'slide_') !== 0) return;

    $back = function () { header('Location: index.php?page=settings#slider-login'); exit; };
    $post = function (string $k): string { return is_string($_POST[$k] ?? null) ? $_POST[$k] : ''; };

    $slides = login_slides_all();
    $id = $post('id');
    $idx = null;
    foreach ($slides as $i => $s) { if ($s['id'] === $id) { $idx = $i; break; } }

    if ($action === 'slide_add') {
        if (count($slides) >= LOGIN_SLIDES_MAX) {
            set_flash('danger', 'Jumlah slide sudah mencapai batas maksimal (' . LOGIN_SLIDES_MAX . '). Hapus salah satu terlebih dahulu.');
            $back();
        }
        [$path, $err] = login_slide_store_image($_FILES['foto'] ?? []);
        if ($err !== '') { set_flash('danger', $err); $back(); }
        $slides[] = [
            'id'     => bin2hex(random_bytes(4)),
            'image'  => $path,
            'title'  => login_slide_cut($post('title'), LOGIN_SLIDE_TITLE_MAX),
            'detail' => login_slide_cut($post('detail'), LOGIN_SLIDE_DETAIL_MAX),
            'active' => true,
        ];
        try { login_slides_save($slides); }
        catch (\Throwable $e) {
            login_slide_delete_file($path);
            if (function_exists('app_log')) app_log('error', 'Simpan slide login gagal: ' . $e->getMessage());
            set_flash('danger', 'Slide gagal disimpan. Coba lagi.');
            $back();
        }
        set_flash('success', 'Slide berhasil ditambahkan.');
        $back();
    }

    if ($idx === null) { set_flash('danger', 'Slide tidak ditemukan.'); $back(); }

    if ($action === 'slide_update') {
        $newImage = null;
        if (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {   // ganti foto (opsional)
            [$path, $err] = login_slide_store_image($_FILES['foto']);
            if ($err !== '') { set_flash('danger', $err); $back(); }
            $newImage = $path;
        }
        $old = $slides[$idx]['image'];
        $slides[$idx]['title']  = login_slide_cut($post('title'), LOGIN_SLIDE_TITLE_MAX);
        $slides[$idx]['detail'] = login_slide_cut($post('detail'), LOGIN_SLIDE_DETAIL_MAX);
        if ($newImage !== null) $slides[$idx]['image'] = $newImage;
        try { login_slides_save($slides); }
        catch (\Throwable $e) {
            if ($newImage !== null) login_slide_delete_file($newImage);
            if (function_exists('app_log')) app_log('error', 'Ubah slide login gagal: ' . $e->getMessage());
            set_flash('danger', 'Perubahan slide gagal disimpan. Coba lagi.');
            $back();
        }
        if ($newImage !== null) login_slide_delete_file($old);
        set_flash('success', 'Slide berhasil diperbarui.');
        $back();
    }

    if ($action === 'slide_toggle') {
        $slides[$idx]['active'] = !$slides[$idx]['active'];
        login_slides_save($slides);
        set_flash('success', $slides[$idx]['active'] ? 'Slide diaktifkan.' : 'Slide dinonaktifkan (tidak tampil di halaman login).');
        $back();
    }

    if ($action === 'slide_move') {
        $dir = $post('dir');
        $j = $dir === 'up' ? $idx - 1 : ($dir === 'down' ? $idx + 1 : -1);
        if ($j >= 0 && $j < count($slides)) {
            [$slides[$j], $slides[$idx]] = [$slides[$idx], $slides[$j]];
            login_slides_save($slides);
            set_flash('success', 'Urutan slide diperbarui.');
        }
        $back();
    }

    if ($action === 'slide_delete') {
        $old = $slides[$idx]['image'];
        array_splice($slides, $idx, 1);
        login_slides_save($slides);
        login_slide_delete_file($old);
        set_flash('success', 'Slide dihapus.');
        $back();
    }
}

// ---------- Kartu pengelolaan slider di halaman Pengaturan ----------

function login_slides_render_admin_card(): void {
    $slides = login_slides_all();
    $total  = count($slides);
    $full   = $total >= LOGIN_SLIDES_MAX;
    $aktif  = count(login_slides_active());
    ?>
<div class="row g-3 mt-1" id="slider-login">
  <div class="col-12">
    <div class="card table-card" data-testid="slides-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
        <h2 class="h6 mb-0"><i class="bi bi-images me-1"></i>Slider Halaman Login</h2>
        <span class="badge bg-secondary" data-testid="slides-count"><?= $aktif ?> aktif dari <?= $total ?> / <?= LOGIN_SLIDES_MAX ?> slide</span>
      </div>
      <p class="small text-muted">Foto &amp; teks yang tampil bergantian dengan animasi lambat di halaman login. Gunakan foto landscape (disarankan &ge; 1600&times;900 px). Format JPG/PNG/WEBP/GIF, maks 5 MB; foto dengan sisi terpanjang di atas <?= LOGIN_SLIDE_MAX_SIDE ?> px dikecilkan otomatis. Bila tidak ada slide aktif, login memakai tampilan gradasi bawaan.</p>

      <form method="post" enctype="multipart/form-data" class="border rounded p-3 mb-3 bg-light" data-testid="slide-add-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="slide_add">
        <div class="row g-2">
          <div class="col-lg-4">
            <label class="form-label small mb-1">Foto <span class="text-danger">*</span></label>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control form-control-sm" required <?= $full ? 'disabled' : '' ?> data-testid="slide-file">
          </div>
          <div class="col-lg-3">
            <label class="form-label small mb-1">Teks Utama</label>
            <input type="text" name="title" maxlength="<?= LOGIN_SLIDE_TITLE_MAX ?>" class="form-control form-control-sm" placeholder="mis. Servis Cepat &amp; Terpercaya" <?= $full ? 'disabled' : '' ?> data-testid="slide-title">
          </div>
          <div class="col-lg-5">
            <label class="form-label small mb-1">Teks Detail</label>
            <textarea name="detail" maxlength="<?= LOGIN_SLIDE_DETAIL_MAX ?>" rows="2" class="form-control form-control-sm" placeholder="Keterangan singkat di bawah teks utama" <?= $full ? 'disabled' : '' ?> data-testid="slide-detail"></textarea>
          </div>
        </div>
        <button class="btn btn-sm btn-primary mt-2" <?= $full ? 'disabled' : '' ?> data-testid="slide-add-btn"><i class="bi bi-plus-circle me-1"></i>Tambah Slide</button>
        <?php if ($full): ?><span class="small text-danger ms-2">Batas maksimal tercapai. Hapus slide lama untuk menambah.</span><?php endif; ?>
      </form>

      <?php if (!$slides): ?>
      <div class="text-center text-muted small py-3" data-testid="slides-empty"><i class="bi bi-image fs-3 d-block mb-1"></i>Belum ada slide. Halaman login memakai tampilan gradasi bawaan.</div>
      <?php else: ?>
      <ul class="list-group" data-testid="slides-list">
        <?php foreach ($slides as $i => $s):
            $exists = is_file(login_slide_root() . '/' . $s['image']); ?>
        <li class="list-group-item" data-testid="slide-item-<?= esc($s['id']) ?>">
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="badge bg-light text-muted" style="width:26px"><?= $i + 1 ?></span>
            <?php if ($exists): ?>
            <img src="<?= esc($s['image']) ?>" alt="" class="rounded border" style="width:96px;height:56px;object-fit:cover" data-testid="slide-thumb-<?= esc($s['id']) ?>">
            <?php else: ?>
            <div class="rounded border bg-light d-flex align-items-center justify-content-center text-muted small" style="width:96px;height:56px">Foto hilang</div>
            <?php endif; ?>
            <div class="flex-grow-1" style="min-width:180px">
              <div class="fw-semibold"><?= $s['title'] !== '' ? esc($s['title']) : '<span class="text-muted fst-italic">(tanpa teks utama)</span>' ?></div>
              <?php if ($s['detail'] !== ''): ?><div class="small text-muted"><?= esc($s['detail']) ?></div><?php endif; ?>
              <span class="badge <?= $s['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $s['active'] ? 'Aktif' : 'Nonaktif' ?></span>
            </div>
            <div class="d-flex flex-wrap gap-1">
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="slide_move"><input type="hidden" name="id" value="<?= esc($s['id']) ?>"><input type="hidden" name="dir" value="up">
                <button class="btn btn-sm btn-outline-primary" <?= $i === 0 ? 'disabled' : '' ?> title="Naik" data-testid="slide-up-<?= esc($s['id']) ?>"><i class="bi bi-arrow-up"></i></button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="slide_move"><input type="hidden" name="id" value="<?= esc($s['id']) ?>"><input type="hidden" name="dir" value="down">
                <button class="btn btn-sm btn-outline-primary" <?= $i === $total - 1 ? 'disabled' : '' ?> title="Turun" data-testid="slide-down-<?= esc($s['id']) ?>"><i class="bi bi-arrow-down"></i></button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="slide_toggle"><input type="hidden" name="id" value="<?= esc($s['id']) ?>">
                <button class="btn btn-sm btn-outline-secondary" title="<?= $s['active'] ? 'Nonaktifkan' : 'Aktifkan' ?>" data-testid="slide-toggle-<?= esc($s['id']) ?>"><i class="bi <?= $s['active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
              </form>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#slide-edit-<?= esc($s['id']) ?>" title="Ubah" data-testid="slide-edit-btn-<?= esc($s['id']) ?>"><i class="bi bi-pencil-square"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus slide ini beserta fotonya?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="slide_delete"><input type="hidden" name="id" value="<?= esc($s['id']) ?>">
                <button class="btn btn-sm btn-outline-danger" title="Hapus" data-testid="slide-delete-<?= esc($s['id']) ?>"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>
          <div class="collapse mt-3" id="slide-edit-<?= esc($s['id']) ?>">
            <form method="post" enctype="multipart/form-data" class="border rounded p-3 bg-light" data-testid="slide-edit-form-<?= esc($s['id']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="slide_update">
              <input type="hidden" name="id" value="<?= esc($s['id']) ?>">
              <div class="row g-2">
                <div class="col-lg-4">
                  <label class="form-label small mb-1">Ganti Foto <span class="text-muted">(opsional)</span></label>
                  <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control form-control-sm">
                </div>
                <div class="col-lg-3">
                  <label class="form-label small mb-1">Teks Utama</label>
                  <input type="text" name="title" maxlength="<?= LOGIN_SLIDE_TITLE_MAX ?>" value="<?= esc($s['title']) ?>" class="form-control form-control-sm" data-testid="slide-edit-title-<?= esc($s['id']) ?>">
                </div>
                <div class="col-lg-5">
                  <label class="form-label small mb-1">Teks Detail</label>
                  <textarea name="detail" maxlength="<?= LOGIN_SLIDE_DETAIL_MAX ?>" rows="2" class="form-control form-control-sm" data-testid="slide-edit-detail-<?= esc($s['id']) ?>"><?= esc($s['detail']) ?></textarea>
                </div>
              </div>
              <button class="btn btn-sm btn-primary mt-2" data-testid="slide-save-<?= esc($s['id']) ?>"><i class="bi bi-check2-circle me-1"></i>Simpan Perubahan</button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Untuk melihat hasilnya, buka halaman login di tab penyamaran (incognito) atau setelah keluar (logout).</p>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php
}
