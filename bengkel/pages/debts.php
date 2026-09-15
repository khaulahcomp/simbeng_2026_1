<?php
// ============================================================
// Hutang Piutang — aktivitas keuangan bengkel dengan berbagai pihak.
//  - Piutang: pihak berhutang KE bengkel (bengkel akan menerima).
//  - Hutang : bengkel berhutang KE pihak (bengkel akan membayar).
// Mendukung pencatatan, pembayaran bertahap (cicilan), dan status lunas.
// ============================================================
$db = db();
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $jenis = ($_POST['jenis'] ?? 'piutang') === 'hutang' ? 'hutang' : 'piutang';
    $pihak = trim($_POST['pihak'] ?? '');
    $tel = trim($_POST['telepon'] ?? '');
    $ket = trim($_POST['keterangan'] ?? '');
    $jumlah = max(0, (float)($_POST['jumlah'] ?? 0));
    $tgl = _valid_date($_POST['tgl'] ?? '') ? $_POST['tgl'] : date('Y-m-d');
    $jt  = _valid_date($_POST['jatuh_tempo'] ?? '') ? $_POST['jatuh_tempo'] : null;
    if ($pihak === '' || $jumlah <= 0) {
        set_flash('danger', 'Nama pihak dan jumlah wajib diisi.');
    } else {
        $db->prepare("INSERT INTO debts (jenis, pihak, telepon, keterangan, jumlah, dibayar, status, tgl, jatuh_tempo)
                      VALUES (?,?,?,?,?,0,'belum_lunas',?,?)")
           ->execute([$jenis, $pihak, $tel, $ket, $jumlah, $tgl, $jt]);
        set_flash('success', 'Data ' . ($jenis === 'hutang' ? 'hutang' : 'piutang') . ' ditambahkan.');
    }
    header('Location: index.php?page=debts'); exit;
}

if ($action === 'pay') {
    $id = (int)$_POST['id'];
    $bayar = max(0, (float)($_POST['bayar'] ?? 0));
    $ket = trim($_POST['keterangan'] ?? '');
    $d = $db->prepare("SELECT * FROM debts WHERE id=?");
    $d->execute([$id]);
    $row = $d->fetch(PDO::FETCH_ASSOC);
    if ($row && $bayar > 0) {
        $sisa = (float)$row['jumlah'] - (float)$row['dibayar'];
        $bayar = min($bayar, $sisa);
        $db->beginTransaction();
        $db->prepare("INSERT INTO debt_payments (debt_id, jumlah, keterangan, tgl) VALUES (?,?,?,?)")
           ->execute([$id, $bayar, $ket, date('Y-m-d')]);
        $payId = (int)$db->lastInsertId();
        $newDibayar = (float)$row['dibayar'] + $bayar;
        $status = $newDibayar >= (float)$row['jumlah'] ? 'lunas' : 'belum_lunas';
        $db->prepare("UPDATE debts SET dibayar=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
           ->execute([$newDibayar, $status, $id]);
        $db->commit();
        set_flash('success', 'Pembayaran dicatat: ' . rupiah($bayar) . ($status === 'lunas' ? ' — LUNAS.' : '.'));
        header('Location: index.php?page=debt_receipt&id=' . $payId); exit;
    } else {
        set_flash('danger', 'Nominal pembayaran tidak valid.');
    }
    header('Location: index.php?page=debts'); exit;
}

if ($action === 'delete') {
    $id = (int)$_POST['id'];
    $db->prepare("DELETE FROM debt_payments WHERE debt_id=?")->execute([$id]);
    $db->prepare("DELETE FROM debts WHERE id=?")->execute([$id]);
    set_flash('success', 'Data dihapus.');
    header('Location: index.php?page=debts'); exit;
}

$filter = $_GET['jenis'] ?? 'semua';
$where = ''; $params = [];
if ($filter === 'hutang' || $filter === 'piutang') { $where = "WHERE jenis=?"; $params = [$filter]; }
$q = $db->prepare("SELECT * FROM debts $where ORDER BY (status='belum_lunas') DESC, COALESCE(jatuh_tempo, tgl) ASC, id DESC");
$q->execute($params);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$sumPiutang = (float)$db->query("SELECT COALESCE(SUM(jumlah-dibayar),0) FROM debts WHERE jenis='piutang' AND status='belum_lunas'")->fetchColumn();
$sumHutang  = (float)$db->query("SELECT COALESCE(SUM(jumlah-dibayar),0) FROM debts WHERE jenis='hutang' AND status='belum_lunas'")->fetchColumn();
$today = date('Y-m-d');
?>
<div class="row g-3 mb-1">
  <div class="col-md-4">
    <div class="card stat-card h-100" data-testid="debt-sum-piutang"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-arrow-down-left-circle me-1 text-success"></i>Total Piutang (belum lunas)</div>
      <div class="h5 mb-0 mt-1 text-success"><?= rupiah($sumPiutang) ?></div>
      <div class="small text-muted">Akan diterima bengkel</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100" data-testid="debt-sum-hutang"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-arrow-up-right-circle me-1 text-danger"></i>Total Hutang (belum lunas)</div>
      <div class="h5 mb-0 mt-1 text-danger"><?= rupiah($sumHutang) ?></div>
      <div class="small text-muted">Akan dibayar bengkel</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100" data-testid="debt-net"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-scales me-1"></i>Posisi Bersih</div>
      <div class="h5 mb-0 mt-1 <?= ($sumPiutang-$sumHutang)>=0?'text-success':'text-danger' ?>"><?= rupiah($sumPiutang - $sumHutang) ?></div>
      <div class="small text-muted">Piutang − Hutang</div>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-1"></i>Tambah Hutang / Piutang</h2>
      <form method="post" data-testid="debt-add-form">
        <input type="hidden" name="action" value="add">
        <div class="mb-2"><label class="form-label small">Jenis</label>
          <select name="jenis" class="form-select form-select-sm" data-testid="debt-jenis">
            <option value="piutang">Piutang (pihak berhutang ke bengkel)</option>
            <option value="hutang">Hutang (bengkel berhutang ke pihak)</option>
          </select></div>
        <div class="mb-2"><label class="form-label small">Nama Pihak</label>
          <input name="pihak" class="form-control form-control-sm" placeholder="Nama pelanggan / supplier / pihak lain" required data-testid="debt-pihak"></div>
        <div class="mb-2"><label class="form-label small">No. WhatsApp / HP</label>
          <input name="telepon" class="form-control form-control-sm" placeholder="mis. 0812xxxx (untuk pengingat WA)" data-testid="debt-telepon"></div>
        <div class="mb-2"><label class="form-label small">Jumlah (Rp)</label>
          <input name="jumlah" type="number" min="0" step="100" class="form-control form-control-sm" required data-testid="debt-jumlah"></div>
        <div class="row g-2">
          <div class="col-6 mb-2"><label class="form-label small">Tanggal</label>
            <input name="tgl" type="date" value="<?= $today ?>" class="form-control form-control-sm" data-testid="debt-tgl"></div>
          <div class="col-6 mb-2"><label class="form-label small">Jatuh Tempo</label>
            <input name="jatuh_tempo" type="date" class="form-control form-control-sm" data-testid="debt-jatuh-tempo"></div>
        </div>
        <div class="mb-3"><label class="form-label small">Keterangan</label>
          <input name="keterangan" class="form-control form-control-sm" placeholder="mis. Servis belum dibayar, faktur sparepart..." data-testid="debt-keterangan"></div>
        <button class="btn btn-sm btn-primary w-100" data-testid="debt-submit"><i class="bi bi-save me-1"></i>Simpan</button>
      </form>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h6 mb-0">Daftar Hutang & Piutang</h2>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <div class="btn-group btn-group-sm" role="group" data-testid="debt-filter">
            <a href="index.php?page=debts&jenis=semua" class="btn btn-outline-secondary <?= $filter==='semua'?'active':'' ?>">Semua</a>
            <a href="index.php?page=debts&jenis=piutang" class="btn btn-outline-success <?= $filter==='piutang'?'active':'' ?>">Piutang</a>
            <a href="index.php?page=debts&jenis=hutang" class="btn btn-outline-danger <?= $filter==='hutang'?'active':'' ?>">Hutang</a>
          </div>
          <div class="btn-group btn-group-sm" role="group" data-testid="debt-export">
            <a href="export.php?type=debts&jenis=<?= esc($filter) ?>&format=pdf" target="_blank" class="btn btn-outline-danger" title="Unduh PDF" data-testid="debt-export-pdf"><i class="bi bi-file-earmark-pdf"></i></a>
            <a href="export.php?type=debts&jenis=<?= esc($filter) ?>&format=xls" target="_blank" class="btn btn-outline-success" title="Unduh Excel" data-testid="debt-export-xls"><i class="bi bi-file-earmark-excel"></i></a>
            <a href="export.php?type=debts&jenis=<?= esc($filter) ?>&format=doc" target="_blank" class="btn btn-outline-primary" title="Unduh Word" data-testid="debt-export-doc"><i class="bi bi-file-earmark-word"></i></a>
          </div>
        </div>
      </div>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="debt-table">
        <thead><tr><th>Jenis</th><th>Pihak / Keterangan</th><th class="text-end">Jumlah</th><th class="text-end">Sisa</th><th>Jatuh Tempo</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Belum ada data.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): $sisa = (float)$r['jumlah'] - (float)$r['dibayar'];
            $overdue = $r['status']==='belum_lunas' && $r['jatuh_tempo'] && $r['jatuh_tempo'] < $today;
            $waNum = preg_replace('/\D+/', '', (string)($r['telepon'] ?? ''));
            if ($waNum !== '') { if (strncmp($waNum,'0',1)===0) $waNum='62'.substr($waNum,1); elseif (strncmp($waNum,'62',2)!==0) $waNum='62'.$waNum; }
            $waTxt = $r['jenis']==='piutang'
              ? 'Yth '.$r['pihak'].', kami dari '.setting('nama_bengkel','Bengkel Motor').' mengingatkan tagihan sebesar '.rupiah($sisa).($r['jatuh_tempo']?' yang jatuh tempo '.date('d/m/Y',strtotime($r['jatuh_tempo'])):'').'. Mohon dapat diselesaikan. Terima kasih.'
              : 'Pengingat kewajiban pembayaran kepada '.$r['pihak'].' sebesar '.rupiah($sisa).($r['jatuh_tempo']?' jatuh tempo '.date('d/m/Y',strtotime($r['jatuh_tempo'])):'').'.';
            $waUrl = 'https://wa.me/'.$waNum.'?text='.rawurlencode($waTxt); ?>
          <tr data-testid="debt-row-<?= $r['id'] ?>">
            <td><span class="badge bg-<?= $r['jenis']==='piutang'?'success':'danger' ?>"><?= strtoupper($r['jenis']) ?></span></td>
            <td><strong><?= esc($r['pihak']) ?></strong><?php if ($r['keterangan']): ?><div class="small text-muted"><?= esc($r['keterangan']) ?></div><?php endif; ?></td>
            <td class="text-end"><?= rupiah($r['jumlah']) ?></td>
            <td class="text-end <?= $sisa>0?'fw-semibold':'' ?>"><?= rupiah($sisa) ?></td>
            <td class="small"><?= $r['jatuh_tempo'] ? esc(date('d/m/Y', strtotime($r['jatuh_tempo']))) : '-' ?>
              <?php if ($overdue): ?><span class="badge bg-warning text-dark">Jatuh tempo</span><?php endif; ?></td>
            <td><span class="badge bg-<?= $r['status']==='lunas'?'secondary':'primary' ?>"><?= $r['status']==='lunas'?'Lunas':'Belum lunas' ?></span></td>
            <td class="text-end text-nowrap">
              <?php if ($r['status'] !== 'lunas'): ?>
              <?php if ($waNum !== ''): ?>
              <a href="<?= esc($waUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0 px-1" title="Kirim pengingat WhatsApp" data-testid="debt-wa-btn-<?= $r['id'] ?>"><i class="bi bi-whatsapp"></i></a>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-primary py-0 px-1 debt-pay-btn" data-testid="debt-pay-btn-<?= $r['id'] ?>"
                      data-id="<?= $r['id'] ?>" data-pihak="<?= esc($r['pihak']) ?>" data-sisa="<?= $sisa ?>"><i class="bi bi-cash"></i> Bayar</button>
              <?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus data ini beserta riwayat pembayarannya?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" data-testid="debt-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
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

<!-- Modal pembayaran -->
<div class="modal fade" id="payModal" tabindex="-1" data-testid="debt-pay-modal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="pay">
        <input type="hidden" name="id" id="payId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i>Catat Pembayaran</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2 small">Pihak: <strong id="payPihak"></strong></p>
          <div class="mb-2"><label class="form-label small">Sisa tagihan</label>
            <input type="text" id="paySisa" class="form-control form-control-sm" readonly></div>
          <div class="mb-2"><label class="form-label small">Nominal bayar (Rp)</label>
            <input type="number" name="bayar" id="payBayar" min="0" step="100" class="form-control form-control-sm" required data-testid="debt-pay-amount"></div>
          <div class="mb-1"><label class="form-label small">Keterangan</label>
            <input name="keterangan" class="form-control form-control-sm" placeholder="mis. cicilan 1"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm btn-primary" data-testid="debt-pay-confirm">Simpan Pembayaran</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modalEl = document.getElementById('payModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  document.querySelectorAll('.debt-pay-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sisa = parseFloat(btn.getAttribute('data-sisa')) || 0;
      document.getElementById('payId').value = btn.getAttribute('data-id');
      document.getElementById('payPihak').textContent = btn.getAttribute('data-pihak');
      document.getElementById('paySisa').value = 'Rp ' + sisa.toLocaleString('id-ID');
      var bayar = document.getElementById('payBayar');
      bayar.value = sisa; bayar.max = sisa;
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
  });
});
</script>
