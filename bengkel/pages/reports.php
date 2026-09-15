<?php
// ============================================================
// Halaman Rekap & Laporan Transaksi
// Filter: harian / mingguan / bulanan / tahunan / rentang tanggal
// Unduh: PDF (cetak), Excel (.xls), Word (.doc) via export.php
// ============================================================
[$periode, $dari, $sampai, $label] = resolve_periode();
$rows = laporan_transaksi($dari, $sampai);
$tot_jasa = array_sum(array_column($rows, 'total_jasa'));
$tot_part = array_sum(array_column($rows, 'total_part'));
$tot_all  = array_sum(array_column($rows, 'grand_total'));
// Bawa hanya parameter filter yang relevan ke tautan export
$export_qs = http_build_query(array_merge(
    array_intersect_key($_GET, array_flip(['periode', 'tanggal', 'bulan', 'tahun', 'dari', 'sampai'])),
    ['type' => 'transactions']
));
?>
<div class="card table-card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end" data-testid="report-filter-form">
    <input type="hidden" name="page" value="reports">
    <div class="col-md-2">
      <label class="form-label small">Periode</label>
      <select name="periode" id="periode" class="form-select form-select-sm" data-testid="report-periode">
        <?php foreach (['harian'=>'Harian','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan','custom'=>'Dari - Sampai Tanggal'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $periode===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 periode-input" data-periode="harian mingguan">
      <label class="form-label small">Tanggal</label>
      <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= esc($_GET['tanggal'] ?? date('Y-m-d')) ?>" data-testid="report-tanggal">
    </div>
    <div class="col-md-2 periode-input" data-periode="bulanan">
      <label class="form-label small">Bulan</label>
      <input type="month" name="bulan" class="form-control form-control-sm" value="<?= esc($_GET['bulan'] ?? date('Y-m')) ?>" data-testid="report-bulan">
    </div>
    <div class="col-md-2 periode-input" data-periode="tahunan">
      <label class="form-label small">Tahun</label>
      <select name="tahun" class="form-select form-select-sm" data-testid="report-tahun">
        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
        <option value="<?= $y ?>" <?= ($_GET['tahun'] ?? date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2 periode-input" data-periode="custom">
      <label class="form-label small">Dari Tanggal</label>
      <input type="date" name="dari" class="form-control form-control-sm" value="<?= esc($_GET['dari'] ?? date('Y-m-d')) ?>" data-testid="report-dari">
    </div>
    <div class="col-md-2 periode-input" data-periode="custom">
      <label class="form-label small">Sampai Tanggal</label>
      <input type="date" name="sampai" class="form-control form-control-sm" value="<?= esc($_GET['sampai'] ?? date('Y-m-d')) ?>" data-testid="report-sampai">
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-primary w-100" data-testid="report-filter-btn"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
    </div>
  </form>
</div></div>

<div class="card table-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="h6 mb-0" data-testid="report-title">Laporan Transaksi — <?= esc($label) ?></h2>
    <div data-testid="report-export-buttons">
      <a class="btn btn-sm btn-outline-danger" target="_blank" href="export.php?<?= esc($export_qs) ?>&format=pdf" data-testid="report-export-pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      <a class="btn btn-sm btn-outline-success" href="export.php?<?= esc($export_qs) ?>&format=xls" data-testid="report-export-xls"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
      <a class="btn btn-sm btn-outline-primary" href="export.php?<?= esc($export_qs) ?>&format=doc" data-testid="report-export-doc"><i class="bi bi-file-earmark-word me-1"></i>Word</a>
    </div>
  </div>

  <div class="row g-2 mb-3" data-testid="report-summary">
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Jumlah Transaksi</div><div class="fw-bold" data-testid="summary-count"><?= count($rows) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Total Jasa</div><div class="fw-bold" data-testid="summary-jasa"><?= rupiah($tot_jasa) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Total Sparepart</div><div class="fw-bold" data-testid="summary-part"><?= rupiah($tot_part) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center bg-success text-white"><div class="small">Total Pendapatan</div><div class="fw-bold" data-testid="summary-total"><?= rupiah($tot_all) ?></div></div></div>
  </div>

  <div class="table-responsive">
  <table class="table table-sm align-middle" data-testid="report-table">
    <thead><tr><th>No</th><th>No. Nota</th><th>Tanggal</th><th>Pelanggan</th><th>Plat</th><th class="text-end">Jasa</th><th class="text-end">Sparepart</th><th class="text-end">Total</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">Tidak ada transaksi pada periode ini.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><a href="index.php?page=receipt&id=<?= $r['id'] ?>"><?= esc($r['no_nota']) ?></a></td>
        <td class="small"><?= esc(lokal($r['created_at'])) ?></td>
        <td><?= esc($r['customer_nama']) ?></td>
        <td><?= esc($r['plat_nomor'] ?? '-') ?></td>
        <td class="text-end"><?= rupiah($r['total_jasa']) ?></td>
        <td class="text-end"><?= rupiah($r['total_part']) ?></td>
        <td class="text-end fw-semibold"><?= rupiah($r['grand_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr class="fw-bold table-light"><td colspan="5">TOTAL</td><td class="text-end"><?= rupiah($tot_jasa) ?></td><td class="text-end"><?= rupiah($tot_part) ?></td><td class="text-end"><?= rupiah($tot_all) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div></div>

<script>
// Tampilkan input tanggal yang relevan sesuai pilihan periode
function togglePeriode() {
  const p = document.getElementById('periode').value;
  document.querySelectorAll('.periode-input').forEach(el => {
    el.style.display = el.dataset.periode.split(' ').includes(p) ? '' : 'none';
  });
}
document.getElementById('periode').addEventListener('change', togglePeriode);
togglePeriode();
</script>
