<?php
// ============================================================
// racks.php - Halaman "Transfer Rak" (fitur Multi Rak).
// Pindahkan stok sparepart antar rak (Rak Utama <-> rak lain, atau
// antar rak lain), lihat rincian lokasi per sparepart, dan riwayat
// perpindahan (manual & otomatis saat stok keluar).
// ============================================================
$db = db();
$uid = (int)(current_user()['id'] ?? 0);

$part_id = (int)($_GET['part_id'] ?? 0);
$part = null;
if ($part_id) {
    $s = $db->prepare("SELECT * FROM parts WHERE id=?"); $s->execute([$part_id]);
    $part = $s->fetch(PDO::FETCH_ASSOC);
    if (!$part) $part_id = 0;
}
$racks = $part_id ? rack_list($db, $part_id) : [];

// Riwayat transfer (50 terakhir, filter opsional per sparepart bila dipilih)
$histParams = [];
$histWhere = '';
if ($part_id) { $histWhere = 'WHERE rtl.part_id=?'; $histParams[] = $part_id; }
$hist = $db->prepare("SELECT rtl.*, p.kode, p.nama AS part_nama, u.nama AS user_nama
    FROM rack_transfer_logs rtl
    JOIN parts p ON p.id = rtl.part_id
    LEFT JOIN users u ON u.id = rtl.user_id
    $histWhere
    ORDER BY rtl.id DESC LIMIT 50");
$hist->execute($histParams);
$history = $hist->fetchAll(PDO::FETCH_ASSOC);

// Daftar sparepart yang sudah Multi Rak aktif (untuk panel ringkasan)
$multiParts = $db->query("SELECT id, kode, nama, stok, lokasi_rak FROM parts WHERE multi_rak_aktif=1 ORDER BY nama LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
  .rak-search-wrap { position: relative; }
  .rak-search-list {
    position: absolute; left: 0; right: 0; top: 100%;
    background: #fff; border: 1px solid #d0d5dd; border-top: none;
    border-radius: 0 0 .375rem .375rem; max-height: 260px; overflow-y: auto;
    z-index: 30; box-shadow: 0 8px 20px rgba(15, 23, 42, .12); display: none;
  }
  .rak-search-list.is-open { display: block; }
  .rak-search-item { padding: .5rem .625rem; cursor: pointer; border-bottom: 1px solid #f1f3f7; font-size: .8125rem; }
  .rak-search-item:hover { background: #fff8e6; }
  .rak-chip-utama { background:#0d6efd; color:#fff; }
  .rak-chip-lain { background:#ffc107; color:#212529; }
</style>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><i class="bi bi-diagram-3 me-1 text-warning"></i>Transfer Rak</h2>
      <p class="small text-muted">Pindahkan stok yang <strong>sudah ada secara fisik</strong> di satu rak ke rak lain (Rak Utama maupun rak tambahan). Total stok sparepart tidak berubah — hanya lokasinya. Rak asal harus punya stok cukup, kalau tidak akan ditolak dengan notifikasi merah.</p>
      <p class="small text-muted">Ingin <strong>menambahkan stok baru</strong> ke rak lain (mis. gudang) tanpa mengurangi Rak Utama? Gunakan panel "Tambah Rak" di halaman <a href="index.php?page=parts">Sparepart</a> (form edit).</p>

      <div class="mb-2 rak-search-wrap" data-testid="rak-part-search-wrap">
        <label class="form-label small">Cari Sparepart (kode / nama)</label>
        <input type="text" id="rakPartSearch" class="form-control form-control-sm" autocomplete="off"
               placeholder="Ketik kode part atau nama..." value="<?= $part ? esc($part['kode'] . ' - ' . $part['nama']) : '' ?>" data-testid="rak-part-search">
        <div class="rak-search-list" id="rakPartList"></div>
      </div>

      <div id="rakPanel" data-testid="rak-panel" <?= $part ? '' : 'style="display:none"' ?>>
        <div class="mb-2">
          <strong id="rakPartLabel"><?= $part ? esc($part['kode'] . ' - ' . $part['nama']) : '' ?></strong>
          <div class="small text-muted">Total stok: <span id="rakPartStok"><?= $part ? (int)$part['stok'] : 0 ?></span></div>
        </div>

        <div id="rakNotActive" class="alert alert-warning py-2 px-2 small" data-testid="rak-not-active" style="<?= ($part && empty($part['multi_rak_aktif'])) ? '' : 'display:none' ?>">
          Multi Rak belum aktif untuk sparepart ini.
          <button type="button" class="btn btn-sm btn-warning mt-1 d-block" id="rakActivateBtn" data-testid="rak-activate-btn">
            <i class="bi bi-diagram-3 me-1"></i>Aktifkan Multi Rak Sekarang
          </button>
        </div>

        <div id="rakActiveBox" style="<?= ($part && !empty($part['multi_rak_aktif'])) ? '' : 'display:none' ?>">
          <div class="small text-muted mb-1">Rincian lokasi saat ini:</div>
          <div id="rakChipsView" class="d-flex flex-wrap gap-1 mb-3" data-testid="rak-chips-view">
            <?php foreach ($racks as $rk): ?>
              <span class="badge <?= $rk['is_utama'] ? 'rak-chip-utama' : 'rak-chip-lain' ?>">
                <?php if ($rk['is_utama']): ?><i class="bi bi-star-fill me-1"></i><?php else: ?><i class="bi bi-box-seam me-1"></i><?php endif; ?>
                <?= esc($rk['nama_rak']) ?>: <?= (int)$rk['stok'] ?>
              </span>
            <?php endforeach; ?>
          </div>

          <form id="rakTransferForm" data-testid="rak-transfer-form">
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small">Rak Asal</label>
                <select id="rakAsal" class="form-select form-select-sm" data-testid="rak-asal"></select>
              </div>
              <div class="col-6">
                <label class="form-label small">Rak Tujuan</label>
                <select id="rakTujuan" class="form-select form-select-sm" data-testid="rak-tujuan" onchange="rakToggleBaru()">
                  <option value="__BARU__">+ Rak baru...</option>
                </select>
              </div>
              <div class="col-12" id="rakTujuanBaruWrap">
                <label class="form-label small">Nama Rak Baru</label>
                <input type="text" id="rakTujuanBaru" class="form-control form-control-sm" placeholder="mis. Rak C / Gudang Belakang" data-testid="rak-tujuan-baru">
              </div>
              <div class="col-6">
                <label class="form-label small">Jumlah</label>
                <input type="number" min="1" id="rakJumlah" class="form-control form-control-sm" data-testid="rak-jumlah">
              </div>
              <div class="col-6 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-primary w-100" onclick="rakSubmitTransfer()" data-testid="rak-transfer-submit">
                  <i class="bi bi-arrow-left-right me-1"></i>Transfer
                </button>
              </div>
            </div>
          </form>
          <div id="rakTransferMsg" class="small mt-2" style="display:none"></div>
        </div>
      </div>
    </div></div>

    <div class="card table-card mt-3"><div class="card-body">
      <h2 class="h6">Sparepart dengan Multi Rak Aktif</h2>
      <?php if (!$multiParts): ?>
        <div class="small text-muted" data-testid="rak-multi-empty">Belum ada sparepart yang mengaktifkan Multi Rak.</div>
      <?php else: ?>
      <div class="list-group list-group-flush" style="max-height:280px;overflow:auto" data-testid="rak-multi-list">
        <?php foreach ($multiParts as $mp): ?>
          <a href="index.php?page=racks&part_id=<?= $mp['id'] ?>" class="list-group-item list-group-item-action py-1 px-1 small">
            <span class="badge bg-warning text-dark me-1"><i class="bi bi-diagram-3"></i></span>
            <?= esc($mp['kode']) ?> - <?= esc($mp['nama']) ?> <span class="text-muted">(stok <?= (int)$mp['stok'] ?>)</span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-7">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-clock-history me-1"></i>Riwayat Transfer Rak<?= $part ? ' - ' . esc($part['kode']) : '' ?></h2>
        <?php if ($part): ?><a href="index.php?page=racks" class="small">Lihat semua sparepart</a><?php endif; ?>
      </div>
      <?php if (!$history): ?>
        <div class="small text-muted" data-testid="rak-history-empty">Belum ada riwayat transfer rak.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle" data-testid="rak-history-table">
          <thead><tr><th>Waktu</th><th>Sparepart</th><th>Dari</th><th>Ke</th><th class="text-end">Jml</th><th>Tipe</th><th>Oleh</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="small text-muted"><?= esc(lokal($h['created_at'])) ?></td>
              <td class="small"><?= esc($h['kode']) ?> - <?= esc($h['part_nama']) ?></td>
              <td class="small"><i class="bi bi-box-seam me-1 text-muted"></i><?= esc($h['rak_asal']) ?></td>
              <td class="small"><i class="bi bi-box-seam-fill me-1 text-warning"></i><?= esc($h['rak_tujuan']) ?></td>
              <td class="text-end small"><?= (int)$h['jumlah'] ?></td>
              <td><span class="badge bg-<?= $h['tipe'] === 'otomatis' ? 'danger' : 'secondary' ?>"><?= $h['tipe'] === 'otomatis' ? 'Otomatis' : 'Manual' ?></span></td>
              <td class="small text-muted"><?= esc($h['user_nama'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<script>
const RAK_UTAMA_KEY = '__UTAMA__';
let rakCurrentPartId = <?= $part_id ?: 0 ?>;
let rakCurrentRacks = <?= json_encode($racks) ?>;

function rakEscHtml(s) { return (s == null ? '' : String(s)).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

function rakToggleBaru() {
  const sel = document.getElementById('rakTujuan');
  document.getElementById('rakTujuanBaruWrap').style.display = (sel.value === '__BARU__') ? '' : 'none';
}

function rakRenderSelects() {
  const asal = document.getElementById('rakAsal');
  const tujuan = document.getElementById('rakTujuan');
  if (!asal || !tujuan) return;
  asal.innerHTML = '';
  tujuan.innerHTML = '<option value="__BARU__">+ Rak baru...</option>';
  rakCurrentRacks.forEach(r => {
    const val = r.is_utama == 1 ? RAK_UTAMA_KEY : r.nama_rak;
    const label = (r.is_utama == 1 ? '⭐ ' : '📦 ') + r.nama_rak + ' (stok ' + r.stok + ')';
    asal.innerHTML += '<option value="' + rakEscHtml(val) + '">' + rakEscHtml(label) + '</option>';
    tujuan.innerHTML += '<option value="' + rakEscHtml(val) + '">' + rakEscHtml(label) + '</option>';
  });
  rakToggleBaru();
}
rakRenderSelects();

function rakRenderChips() {
  const box = document.getElementById('rakChipsView');
  if (!box) return;
  box.innerHTML = rakCurrentRacks.map(r => {
    const cls = r.is_utama == 1 ? 'rak-chip-utama' : 'rak-chip-lain';
    const icon = r.is_utama == 1 ? '<i class="bi bi-star-fill me-1"></i>' : '<i class="bi bi-box-seam me-1"></i>';
    return '<span class="badge ' + cls + '">' + icon + rakEscHtml(r.nama_rak) + ': ' + r.stok + '</span>';
  }).join('');
}

function rakMsg(text, ok) {
  const el = document.getElementById('rakTransferMsg');
  el.style.display = text ? 'block' : 'none';
  el.className = 'small mt-2 py-2 px-2 rounded ' + (ok ? 'text-success bg-success-subtle border border-success' : 'text-danger fw-semibold bg-danger-subtle border border-danger');
  el.innerHTML = (ok ? '<i class="bi bi-check-circle-fill me-1"></i>' : '<i class="bi bi-exclamation-triangle-fill me-1"></i>') + rakEscHtml(text || '');
}

function rakLoadPanel(part) {
  rakCurrentPartId = part.id;
  document.getElementById('rakPanel').style.display = '';
  document.getElementById('rakPartLabel').textContent = part.kode + ' - ' + part.nama;
  document.getElementById('rakPartStok').textContent = part.stok;
  fetch('ajax/rak.php?action=list&part_id=' + part.id)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      const aktif = d.part.multi_rak_aktif == 1;
      document.getElementById('rakNotActive').style.display = aktif ? 'none' : '';
      document.getElementById('rakActiveBox').style.display = aktif ? '' : 'none';
      rakCurrentRacks = d.racks;
      rakRenderChips();
      rakRenderSelects();
    });
}

document.getElementById('rakActivateBtn')?.addEventListener('click', function () {
  const fd = new FormData();
  fd.append('action', 'toggle'); fd.append('part_id', rakCurrentPartId); fd.append('aktif', '1');
  fetch('ajax/rak.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
    if (!d.ok) { alert(d.error || 'Gagal mengaktifkan Multi Rak.'); return; }
    document.getElementById('rakNotActive').style.display = 'none';
    document.getElementById('rakActiveBox').style.display = '';
    rakCurrentRacks = d.racks;
    rakRenderChips(); rakRenderSelects();
  });
});

function rakSubmitTransfer() {
  const asal = document.getElementById('rakAsal').value;
  let tujuan = document.getElementById('rakTujuan').value;
  const jumlah = parseInt(document.getElementById('rakJumlah').value, 10);
  if (!jumlah || jumlah < 1) { rakMsg('Jumlah harus lebih dari 0.', false); return; }
  const fd = new FormData();
  fd.append('action', 'transfer'); fd.append('part_id', rakCurrentPartId);
  fd.append('rak_asal', asal); fd.append('rak_tujuan', tujuan);
  if (tujuan === '__BARU__') fd.append('rak_tujuan_baru', document.getElementById('rakTujuanBaru').value.trim());
  fd.append('jumlah', jumlah);
  fetch('ajax/rak.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { rakMsg(d.error || 'Transfer gagal.', false); return; }
      rakMsg('Transfer berhasil.', true);
      rakCurrentRacks = d.racks;
      rakRenderChips(); rakRenderSelects();
      document.getElementById('rakJumlah').value = '';
    })
    .catch(() => rakMsg('Gagal menghubungi server.', false));
}

// ---- Pencarian sparepart ----
(function () {
  const input = document.getElementById('rakPartSearch');
  const list = document.getElementById('rakPartList');
  let debounce = null;
  input.addEventListener('input', function () {
    const q = input.value.trim();
    clearTimeout(debounce);
    if (q.length < 1) { list.classList.remove('is-open'); return; }
    debounce = setTimeout(function () {
      fetch('ajax/lookup.php?action=search_parts&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(rows => {
          if (!rows.length) { list.innerHTML = '<div class="rak-search-item text-muted">Tidak ada hasil.</div>'; list.classList.add('is-open'); return; }
          list.innerHTML = rows.map(p =>
            '<div class="rak-search-item" data-id="' + p.id + '" data-kode="' + rakEscHtml(p.kode) + '" data-nama="' + rakEscHtml(p.nama) + '" data-stok="' + p.stok + '">' +
            '<strong>' + rakEscHtml(p.kode) + '</strong> — ' + rakEscHtml(p.nama) +
            (p.multi_rak_aktif == 1 ? ' <span class="badge bg-warning text-dark">Multi Rak</span>' : '') +
            '</div>'
          ).join('');
          list.classList.add('is-open');
        });
    }, 200);
  });
  list.addEventListener('mousedown', function (e) {
    const el = e.target.closest('.rak-search-item');
    if (!el || !el.dataset.id) return;
    input.value = el.dataset.kode + ' - ' + el.dataset.nama;
    list.classList.remove('is-open');
    rakLoadPanel({ id: el.dataset.id, kode: el.dataset.kode, nama: el.dataset.nama, stok: el.dataset.stok });
  });
  document.addEventListener('click', function (e) { if (!input.contains(e.target) && !list.contains(e.target)) list.classList.remove('is-open'); });
})();
</script>
