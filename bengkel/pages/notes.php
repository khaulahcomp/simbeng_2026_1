<?php
// ============================================================
// Sticky Notes: catatan-catatan kecil untuk tim bengkel
// (hal yang perlu diingat: pesanan part, janji ke pelanggan, dll.)
// ============================================================
$db = db();
$action = $_POST['action'] ?? '';
$warna_ok = ['kuning', 'hijau', 'biru', 'pink', 'putih'];

if ($action === 'add') {
    $isi = trim($_POST['isi'] ?? '');
    $warna = in_array($_POST['warna'] ?? '', $warna_ok, true) ? $_POST['warna'] : 'kuning';
    if ($isi !== '') {
        $db->prepare("INSERT INTO notes (isi, warna) VALUES (?,?)")->execute([$isi, $warna]);
        set_flash('success', 'Catatan ditambahkan.');
    } else {
        set_flash('danger', 'Isi catatan tidak boleh kosong.');
    }
    header('Location: index.php?page=notes'); exit;
}
if ($action === 'save') {
    $id = (int)$_POST['id'];
    $isi = trim($_POST['isi'] ?? '');
    $warna = in_array($_POST['warna'] ?? '', $warna_ok, true) ? $_POST['warna'] : 'kuning';
    if ($isi !== '') {
        $db->prepare("UPDATE notes SET isi=?, warna=?, updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$isi, $warna, $id]);
        set_flash('success', 'Catatan diperbarui.');
    }
    header('Location: index.php?page=notes'); exit;
}
if ($action === 'delete') {
    $db->prepare("DELETE FROM notes WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Catatan dihapus.');
    header('Location: index.php?page=notes'); exit;
}

$notes = $db->query("SELECT * FROM notes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$warna_map = ['kuning' => '#fff3bf', 'hijau' => '#d3f9d8', 'biru' => '#dbe4ff', 'pink' => '#ffdeeb', 'putih' => '#ffffff'];
$warna_dot = ['kuning' => '#fab005', 'hijau' => '#40c057', 'biru' => '#4c6ef5', 'pink' => '#e64980', 'putih' => '#adb5bd'];

// Pemilih warna (dipakai form tambah & edit)
function warna_picker(string $aktif, array $warna_dot): string {
    $html = '<div class="d-flex gap-2 mb-2">';
    foreach ($warna_dot as $k => $hex) {
        $html .= '<label class="form-check-label" style="cursor:pointer" title="' . ucfirst($k) . '">'
               . '<input type="radio" name="warna" value="' . $k . '" class="form-check-input d-none" ' . ($k === $aktif ? 'checked' : '') . '>'
               . '<span class="d-inline-block rounded-circle border warna-dot" data-warna="' . $k . '" style="width:22px;height:22px;background:' . $hex . ';' . ($k === $aktif ? 'outline:2px solid #343a40;outline-offset:2px;' : '') . '"></span></label>';
    }
    return $html . '</div>';
}
?>
<div class="card table-card mb-3"><div class="card-body">
  <h2 class="h6 mb-2"><i class="bi bi-plus-square me-1"></i>Catatan Baru</h2>
  <form method="post" data-testid="note-form">
    <input type="hidden" name="action" value="add">
    <div class="mb-2"><textarea name="isi" class="form-control form-control-sm" rows="2" placeholder="Tulis hal yang perlu dicatat..." required data-testid="note-isi"></textarea></div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <?= warna_picker('kuning', $warna_dot) ?>
      <button class="btn btn-sm btn-primary" data-testid="note-submit"><i class="bi bi-plus-lg me-1"></i>Tambah Catatan</button>
    </div>
  </form>
</div></div>

<div class="row row-cols-1 row-cols-md-3 row-cols-xl-4 g-3" data-testid="notes-grid">
<?php if (!$notes): ?>
  <div class="col"><div class="alert alert-info mb-0">Belum ada catatan. Tambahkan catatan pertama di atas.</div></div>
<?php endif; ?>
<?php foreach ($notes as $i => $n): $bg = $warna_map[$n['warna']] ?? '#fff3bf'; ?>
  <div class="col" data-testid="note-card-<?= $n['id'] ?>">
    <div class="p-3 rounded shadow-sm h-100 d-flex flex-column" style="background:<?= $bg ?>;transform:rotate(<?= $i % 2 ? '0.8' : '-0.8' ?>deg);min-height:150px">
      <div class="flex-grow-1" style="white-space:pre-wrap;font-size:14px" data-testid="note-isi-<?= $n['id'] ?>"><?= esc($n['isi']) ?></div>
      <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-secondary border-opacity-25">
        <small class="text-muted"><?= esc(lokal($n['updated_at'])) ?></small>
        <div>
          <button class="btn btn-sm btn-outline-dark py-0 px-1" data-bs-toggle="collapse" data-bs-target="#edit-note-<?= $n['id'] ?>" title="Edit" data-testid="note-edit-toggle-<?= $n['id'] ?>"><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" onsubmit="return confirm('Hapus catatan ini?')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm btn-outline-dark py-0 px-1" title="Hapus" data-testid="note-delete-<?= $n['id'] ?>"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <div class="collapse mt-2" id="edit-note-<?= $n['id'] ?>">
        <form method="post" data-testid="note-edit-form-<?= $n['id'] ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $n['id'] ?>">
          <textarea name="isi" class="form-control form-control-sm mb-2" rows="3" required data-testid="note-edit-isi-<?= $n['id'] ?>"><?= esc($n['isi']) ?></textarea>
          <?= warna_picker($n['warna'], $warna_dot) ?>
          <button class="btn btn-sm btn-dark w-100" data-testid="note-save-<?= $n['id'] ?>">Simpan</button>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
// Klik dot warna: tandai pilihan secara visual
document.querySelectorAll('.warna-dot').forEach(dot => {
  dot.addEventListener('click', () => {
    dot.closest('form').querySelectorAll('.warna-dot').forEach(d => d.style.outline = 'none');
    dot.style.outline = '2px solid #343a40';
    dot.style.outlineOffset = '2px';
  });
});
</script>
