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
function warna_picker(string $aktif, array $warna_dot, string $mb = 'mb-2'): string {
    $html = '<div class="d-flex gap-2 ' . $mb . '">';
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
    <div class="note-card p-3 rounded shadow-sm h-100 d-flex flex-column" data-rot="<?= $i % 2 ? '0.8' : '-0.8' ?>" style="background:<?= $bg ?>;transform:rotate(<?= $i % 2 ? '0.8' : '-0.8' ?>deg);min-height:150px">
      <!-- Mode LIHAT -->
      <div class="note-view flex-grow-1" data-testid="note-isi-<?= $n['id'] ?>"><?= esc($n['isi']) ?></div>
      <div class="note-footer d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-secondary border-opacity-25">
        <small class="text-muted"><?= esc(lokal($n['updated_at'])) ?></small>
        <div>
          <button type="button" class="btn btn-sm btn-outline-dark py-0 px-1" data-note-edit title="Edit" data-testid="note-edit-toggle-<?= $n['id'] ?>"><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" onsubmit="return confirm('Hapus catatan ini?')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm btn-outline-dark py-0 px-1" title="Hapus" data-testid="note-delete-<?= $n['id'] ?>"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <!-- Mode EDIT: tampil di dalam kartu yang sama (teks sama persis dengan mode lihat) -->
      <form method="post" class="note-edit flex-grow-1 flex-column" data-testid="note-edit-form-<?= $n['id'] ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $n['id'] ?>">
        <textarea name="isi" class="note-textarea flex-grow-1" required aria-label="Isi catatan" data-testid="note-edit-isi-<?= $n['id'] ?>"><?= esc($n['isi']) ?></textarea>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2 pt-2 border-top border-secondary border-opacity-25">
          <?= warna_picker($n['warna'], $warna_dot, 'mb-0') ?>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-dark" data-note-cancel data-testid="note-cancel-<?= $n['id'] ?>">Batal</button>
            <button class="btn btn-sm btn-dark" data-testid="note-save-<?= $n['id'] ?>"><i class="bi bi-check2 me-1"></i>Simpan</button>
          </div>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>

<style>
  /* Teks catatan: ukuran & gaya SAMA di mode lihat maupun mode edit. */
  .note-card .note-view,
  .note-card .note-textarea { font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; font-family: inherit; color: inherit; }
  .note-card .note-textarea {
    width: 100%; min-height: 150px; resize: none; overflow: hidden;
    padding: 6px 8px; border-radius: 6px;
    border: 1px dashed rgba(0,0,0,.35); background: rgba(255,255,255,.55);
  }
  .note-card .note-textarea:focus { outline: 2px solid rgba(0,0,0,.45); outline-offset: 1px; background: rgba(255,255,255,.75); }
  .note-card .note-edit { display: none; }
  .note-card.is-editing .note-edit { display: flex; }
  .note-card.is-editing .note-view,
  .note-card.is-editing .note-footer { display: none; }
  /* Saat mengetik, kartu dibuat lurus agar nyaman dibaca. */
  .note-card.is-editing { transform: none !important; }
</style>
<script>
(function () {
  function syncDots(form) {
    form.querySelectorAll('.warna-dot').forEach(function (d) {
      var on = d.parentNode.querySelector('input').checked;
      d.style.outline = on ? '2px solid #343a40' : 'none';
      d.style.outlineOffset = on ? '2px' : '';
    });
  }
  // Tinggi textarea mengikuti isi (tidak perlu scroll di kotak kecil).
  function autoGrow(ta, minPx) {
    ta.style.height = 'auto';
    ta.style.height = Math.max(ta.scrollHeight + 2, minPx || 150) + 'px';
  }

  // Klik dot warna: tandai pilihan secara visual
  document.querySelectorAll('.warna-dot').forEach(function (dot) {
    dot.addEventListener('click', function () {
      dot.parentNode.querySelector('input').checked = true;
      syncDots(dot.closest('form'));
    });
  });

  document.querySelectorAll('.note-card').forEach(function (card) {
    var form = card.querySelector('.note-edit');
    var ta = card.querySelector('.note-textarea');
    var view = card.querySelector('.note-view');
    var openBtn = card.querySelector('[data-note-edit]');
    var cancelBtn = card.querySelector('[data-note-cancel]');

    function open() {
      var h = view.offsetHeight;                // tinggi mode lihat -> textarea tidak lebih pendek
      card.classList.add('is-editing');
      autoGrow(ta, Math.max(h, 150));
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);
    }
    function close() {
      form.reset();                              // kembalikan isi & warna ke semula
      syncDots(form);
      card.classList.remove('is-editing');
    }
    openBtn.addEventListener('click', open);
    cancelBtn.addEventListener('click', close);
    ta.addEventListener('input', function () { autoGrow(ta, 150); });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (form.reportValidity()) form.submit(); }
    });
  });

  // Catatan baru: kotak ikut membesar saat mengetik
  var nt = document.querySelector('[data-testid="note-isi"]');
  if (nt) nt.addEventListener('input', function () { nt.style.height = 'auto'; nt.style.height = Math.max(nt.scrollHeight + 2, 60) + 'px'; });
})();
</script>
