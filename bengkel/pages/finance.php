<?php
// ============================================================
// Keuangan — Kas (buku kas masuk/keluar) + ringkasan Laba/Rugi.
// Laba/Rugi dihitung dari transaksi (pendapatan) - HPP sparepart
// terjual + pemasukan lain (kas masuk) - pengeluaran operasional (kas keluar).
// ============================================================
$db = db();
$action = $_POST['action'] ?? '';

// ---- Tambah entri kas (pemasukan / pengeluaran operasional) ----
if ($action === 'cash_add') {
    $tipe = ($_POST['tipe'] ?? 'keluar') === 'masuk' ? 'masuk' : 'keluar';
    $kategori = trim($_POST['kategori'] ?? '');
    $jumlah = max(0, (float)($_POST['jumlah'] ?? 0));
    $ket = trim($_POST['keterangan'] ?? '');
    $tgl = _valid_date($_POST['tgl'] ?? '') ? $_POST['tgl'] : date('Y-m-d');
    if ($jumlah <= 0) {
        set_flash('danger', 'Jumlah kas harus lebih dari 0.');
    } else {
        $db->prepare("INSERT INTO cash_entries (tipe, kategori, jumlah, keterangan, tgl) VALUES (?,?,?,?,?)")
           ->execute([$tipe, $kategori, $jumlah, $ket, $tgl]);
        set_flash('success', 'Entri kas ' . ($tipe === 'masuk' ? 'pemasukan' : 'pengeluaran') . ' tersimpan.');
    }
    header('Location: index.php?page=finance&' . http_build_query(array_intersect_key($_GET, array_flip(['periode','tanggal','bulan','tahun','dari','sampai'])))); exit;
}
if ($action === 'cash_delete') {
    $db->prepare("DELETE FROM cash_entries WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Entri kas dihapus.');
    header('Location: index.php?page=finance'); exit;
}

[$periode, $dari, $sampai, $label] = resolve_periode();

// Pendapatan dari transaksi kasir (berbasis WIB -> rentang UTC ber-index)
[$fin_su, $fin_eu] = wib_range_utc($dari, $sampai);
$t = $db->prepare("SELECT COALESCE(SUM(total_jasa),0) jasa, COALESCE(SUM(total_part),0) part,
    COALESCE(SUM(diskon),0) diskon, COALESCE(SUM(grand_total),0) grand, COUNT(*) n
    FROM transactions WHERE created_at >= ? AND created_at < ?");
$t->execute([$fin_su, $fin_eu]);
$trx = $t->fetch(PDO::FETCH_ASSOC);

// HPP (modal) sparepart terjual pada periode
$h = $db->prepare("SELECT COALESCE(SUM(ti.qty * COALESCE(p.harga_beli,0)),0)
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id
    LEFT JOIN parts p ON p.id = ti.part_id
    WHERE ti.tipe='part' AND t.created_at >= ? AND t.created_at < ?");
$h->execute([$fin_su, $fin_eu]);
$hpp = (float)$h->fetchColumn();

// Kas pada periode
$c = $db->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE 0 END),0) masuk,
    COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah ELSE 0 END),0) keluar
    FROM cash_entries WHERE tgl BETWEEN ? AND ?");
$c->execute([$dari, $sampai]);
$cash = $c->fetch(PDO::FETCH_ASSOC);

$pendapatan   = (float)$trx['grand'];
$labaKotor    = $pendapatan - $hpp;
$pemasukanLain= (float)$cash['masuk'];
$pengeluaran  = (float)$cash['keluar'];
$labaBersih   = $labaKotor + $pemasukanLain - $pengeluaran;

// Saldo kas kumulatif (semua waktu)
$saldoKas = (float)$db->query("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE -jumlah END),0) FROM cash_entries")->fetchColumn();

// Buku kas pada periode
$ledger = $db->prepare("SELECT * FROM cash_entries WHERE tgl BETWEEN ? AND ? ORDER BY tgl DESC, id DESC");
$ledger->execute([$dari, $sampai]);
$ledger = $ledger->fetchAll(PDO::FETCH_ASSOC);

// Query string periode untuk tombol ekspor
$expqs = 'type=finance&' . http_build_query(array_intersect_key($_GET, array_flip(['periode','tanggal','bulan','tahun','dari','sampai'])));

// Grafik arus kas (khusus admin): pemasukan vs pengeluaran per hari (rentang pendek) / per bulan (rentang panjang)
$is_admin = ((current_user()['role'] ?? '') === 'admin');
$chart_labels = []; $chart_masuk = []; $chart_keluar = [];
if ($is_admin) {
    $byDay = ((int)floor((strtotime($sampai) - strtotime($dari)) / 86400)) <= 62;
    if ($byDay) {
        $cf = $db->prepare("SELECT tgl bucket, SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE 0 END) masuk,
            SUM(CASE WHEN tipe='keluar' THEN jumlah ELSE 0 END) keluar
            FROM cash_entries WHERE tgl BETWEEN ? AND ? GROUP BY tgl ORDER BY tgl");
    } else {
        $cf = $db->prepare("SELECT DATE_FORMAT(tgl,'%Y-%m') bucket, SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE 0 END) masuk,
            SUM(CASE WHEN tipe='keluar' THEN jumlah ELSE 0 END) keluar
            FROM cash_entries WHERE tgl BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket");
    }
    $cf->execute([$dari, $sampai]);
    foreach ($cf->fetchAll(PDO::FETCH_ASSOC) as $rc) {
        $chart_labels[] = $byDay ? date('d/m', strtotime($rc['bucket'])) : date('m/Y', strtotime($rc['bucket'] . '-01'));
        $chart_masuk[]  = (float)$rc['masuk'];
        $chart_keluar[] = (float)$rc['keluar'];
    }
}
?>
<div class="card table-card mb-3" data-testid="finance-period-card"><div class="card-body">
  <form method="get" class="row g-2 align-items-end" data-testid="finance-period-form">
    <input type="hidden" name="page" value="finance">
    <div class="col-auto">
      <label class="form-label small mb-1">Periode</label>
      <select name="periode" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="finance-periode">
        <?php foreach (['harian'=>'Harian','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan','custom'=>'Custom'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $periode===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($periode==='harian' || $periode==='mingguan'): ?>
    <div class="col-auto"><label class="form-label small mb-1">Tanggal</label>
      <input type="date" name="tanggal" value="<?= esc($_GET['tanggal'] ?? date('Y-m-d')) ?>" class="form-control form-control-sm" data-testid="finance-tanggal"></div>
    <?php elseif ($periode==='bulanan'): ?>
    <div class="col-auto"><label class="form-label small mb-1">Bulan</label>
      <input type="month" name="bulan" value="<?= esc($_GET['bulan'] ?? date('Y-m')) ?>" class="form-control form-control-sm" data-testid="finance-bulan"></div>
    <?php elseif ($periode==='tahunan'): ?>
    <div class="col-auto"><label class="form-label small mb-1">Tahun</label>
      <input type="number" name="tahun" value="<?= esc($_GET['tahun'] ?? date('Y')) ?>" class="form-control form-control-sm" style="width:110px" data-testid="finance-tahun"></div>
    <?php else: ?>
    <div class="col-auto"><label class="form-label small mb-1">Dari</label>
      <input type="date" name="dari" value="<?= esc($_GET['dari'] ?? date('Y-m-01')) ?>" class="form-control form-control-sm" data-testid="finance-dari"></div>
    <div class="col-auto"><label class="form-label small mb-1">Sampai</label>
      <input type="date" name="sampai" value="<?= esc($_GET['sampai'] ?? date('Y-m-d')) ?>" class="form-control form-control-sm" data-testid="finance-sampai"></div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-sm btn-primary" data-testid="finance-apply"><i class="bi bi-funnel me-1"></i>Terapkan</button></div>
    <div class="col-auto ms-auto d-flex gap-1">
      <a href="export.php?<?= $expqs ?>&format=pdf" target="_blank" class="btn btn-sm btn-outline-danger" title="Unduh PDF" data-testid="finance-export-pdf"><i class="bi bi-file-earmark-pdf"></i></a>
      <a href="export.php?<?= $expqs ?>&format=xls" target="_blank" class="btn btn-sm btn-outline-success" title="Unduh Excel" data-testid="finance-export-xls"><i class="bi bi-file-earmark-excel"></i></a>
      <a href="export.php?<?= $expqs ?>&format=doc" target="_blank" class="btn btn-sm btn-outline-primary" title="Unduh Word" data-testid="finance-export-doc"><i class="bi bi-file-earmark-word"></i></a>
    </div>
    <div class="col-auto text-muted small align-self-end">Periode: <strong><?= esc($label) ?></strong></div>
  </form>
</div></div>

<?php if ($is_admin): ?>
<div class="card table-card mb-3" data-testid="finance-chart-card"><div class="card-body">
  <h2 class="h6 mb-3"><i class="bi bi-bar-chart-line me-1"></i>Grafik Arus Kas — <?= esc($label) ?></h2>
  <?php if ($chart_labels): ?>
  <canvas id="cashFlowChart" height="90" data-testid="finance-chart"></canvas>
  <?php else: ?>
  <p class="text-muted small mb-0" data-testid="finance-chart-empty">Belum ada data kas pada periode ini untuk ditampilkan pada grafik.</p>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-1">
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100" data-testid="fin-pendapatan"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-cash-stack me-1"></i>Pendapatan (nota)</div>
      <div class="h5 mb-0 mt-1 text-success"><?= rupiah($pendapatan) ?></div>
      <div class="small text-muted"><?= (int)$trx['n'] ?> transaksi</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100" data-testid="fin-hpp"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-box-seam me-1"></i>HPP Sparepart</div>
      <div class="h5 mb-0 mt-1 text-danger"><?= rupiah($hpp) ?></div>
      <div class="small text-muted">Modal part terjual</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100" data-testid="fin-laba-kotor"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-graph-up-arrow me-1"></i>Laba Kotor</div>
      <div class="h5 mb-0 mt-1"><?= rupiah($labaKotor) ?></div>
      <div class="small text-muted">Pendapatan − HPP</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100" data-testid="fin-laba-bersih"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-wallet2 me-1"></i><?= $labaBersih>=0 ? 'Laba Bersih' : 'Rugi Bersih' ?></div>
      <div class="h5 mb-0 mt-1 <?= $labaBersih>=0 ? 'text-success' : 'text-danger' ?>"><?= rupiah($labaBersih) ?></div>
      <div class="small text-muted">Saldo kas: <?= rupiah($saldoKas) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-5">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-calculator me-1"></i>Ringkasan Laba / Rugi</h2>
      <table class="table table-sm mb-3" data-testid="finance-pnl-table">
        <tbody>
          <tr><td>Pendapatan jasa</td><td class="text-end"><?= rupiah($trx['jasa']) ?></td></tr>
          <tr><td>Pendapatan sparepart</td><td class="text-end"><?= rupiah($trx['part']) ?></td></tr>
          <tr><td>Diskon diberikan</td><td class="text-end text-danger">- <?= rupiah($trx['diskon']) ?></td></tr>
          <tr class="border-top"><td><strong>Total pendapatan</strong></td><td class="text-end"><strong><?= rupiah($pendapatan) ?></strong></td></tr>
          <tr><td>HPP sparepart terjual</td><td class="text-end text-danger">- <?= rupiah($hpp) ?></td></tr>
          <tr class="border-top"><td><strong>Laba kotor</strong></td><td class="text-end"><strong><?= rupiah($labaKotor) ?></strong></td></tr>
          <tr><td>Pemasukan lain (kas)</td><td class="text-end text-success">+ <?= rupiah($pemasukanLain) ?></td></tr>
          <tr><td>Pengeluaran operasional (kas)</td><td class="text-end text-danger">- <?= rupiah($pengeluaran) ?></td></tr>
          <tr class="border-top"><td><strong><?= $labaBersih>=0?'Laba bersih':'Rugi bersih' ?></strong></td>
              <td class="text-end"><strong class="<?= $labaBersih>=0?'text-success':'text-danger' ?>"><?= rupiah($labaBersih) ?></strong></td></tr>
        </tbody>
      </table>

      <h2 class="h6 mb-2 mt-4"><i class="bi bi-journal-plus me-1"></i>Catat Kas</h2>
      <form method="post" class="row g-2" data-testid="finance-cash-form">
        <input type="hidden" name="action" value="cash_add">
        <div class="col-5"><label class="form-label small mb-1">Jenis</label>
          <select name="tipe" class="form-select form-select-sm" data-testid="cash-tipe">
            <option value="keluar">Pengeluaran</option>
            <option value="masuk">Pemasukan</option>
          </select></div>
        <div class="col-7"><label class="form-label small mb-1">Kategori</label>
          <input name="kategori" class="form-control form-control-sm" placeholder="mis. Listrik, Gaji, Sewa" data-testid="cash-kategori"></div>
        <div class="col-6"><label class="form-label small mb-1">Jumlah (Rp)</label>
          <input name="jumlah" type="number" min="0" step="100" class="form-control form-control-sm" required data-testid="cash-jumlah"></div>
        <div class="col-6"><label class="form-label small mb-1">Tanggal</label>
          <input name="tgl" type="date" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm" data-testid="cash-tgl"></div>
        <div class="col-12"><label class="form-label small mb-1">Keterangan</label>
          <input name="keterangan" class="form-control form-control-sm" data-testid="cash-keterangan"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary w-100" data-testid="cash-submit"><i class="bi bi-plus-lg me-1"></i>Simpan Entri Kas</button></div>
      </form>
    </div></div>
  </div>

  <div class="col-lg-7">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-cash-coin me-1"></i>Buku Kas — <?= esc($label) ?></h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="finance-cash-table">
        <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th></th></tr></thead>
        <tbody>
          <?php if (!$ledger): ?><tr><td colspan="6" class="text-center text-muted">Belum ada entri kas pada periode ini.</td></tr><?php endif; ?>
          <?php foreach ($ledger as $e): ?>
          <tr data-testid="cash-row-<?= $e['id'] ?>">
            <td class="small"><?= esc(date('d/m/Y', strtotime($e['tgl']))) ?></td>
            <td><?= esc($e['kategori'] ?: '-') ?></td>
            <td class="small text-muted"><?= esc($e['keterangan']) ?></td>
            <td class="text-end text-success"><?= $e['tipe']==='masuk' ? rupiah($e['jumlah']) : '-' ?></td>
            <td class="text-end text-danger"><?= $e['tipe']==='keluar' ? rupiah($e['jumlah']) : '-' ?></td>
            <td class="text-end">
              <form method="post" onsubmit="return confirm('Hapus entri kas ini?')" class="d-inline">
                <input type="hidden" name="action" value="cash_delete">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" data-testid="cash-delete-<?= $e['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="border-top">
            <th colspan="3" class="text-end">Total periode</th>
            <th class="text-end text-success"><?= rupiah($pemasukanLain) ?></th>
            <th class="text-end text-danger"><?= rupiah($pengeluaran) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
      </div>
    </div></div>
  </div>
</div>

<?php if ($is_admin && $chart_labels): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var ctx = document.getElementById('cashFlowChart');
  if (!ctx || typeof Chart === 'undefined') return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [
        { label: 'Pemasukan', data: <?= json_encode($chart_masuk) ?>, backgroundColor: 'rgba(25,135,84,.8)' },
        { label: 'Pengeluaran', data: <?= json_encode($chart_keluar) ?>, backgroundColor: 'rgba(220,53,69,.8)' }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': Rp ' + c.parsed.y.toLocaleString('id-ID'); } } }
      },
      scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + v.toLocaleString('id-ID'); } } } }
    }
  });
})();
</script>
<?php endif; ?>
