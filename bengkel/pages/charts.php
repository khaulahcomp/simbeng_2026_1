<?php
// ============================================================
// Grafik Pelanggan: peringkat pelanggan berdasarkan total
// belanja & frekuensi transaksi. Filter: bulanan / tahunan /
// rentang tanggal (dari - sampai).
// ============================================================
if (empty($_GET['periode'])) $_GET['periode'] = 'bulanan';
[$periode, $dari, $sampai, $label] = resolve_periode();
// Samakan label untuk konteks halaman ini
$label = str_replace(['Mingguan', 'Harian'], 'Periode', $label);

[$ch_su, $ch_eu] = wib_range_utc($dari, $sampai);
$stmt = db()->prepare("SELECT c.nama, COUNT(t.id) AS jml, COALESCE(SUM(t.grand_total),0) AS total
    FROM transactions t
    JOIN customers c ON c.id = t.customer_id
    WHERE t.created_at >= ? AND t.created_at < ?
    GROUP BY t.customer_id
    ORDER BY total DESC, jml DESC
    LIMIT 10");
$stmt->execute([$ch_su, $ch_eu]);
$top = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_semua = array_sum(array_column($top, 'total'));
?>
<div class="card table-card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end" data-testid="chart-filter-form">
    <input type="hidden" name="page" value="charts">
    <div class="col-md-3">
      <label class="form-label small">Periode</label>
      <select name="periode" id="chartPeriode" class="form-select form-select-sm" data-testid="chart-periode">
        <?php foreach (['bulanan'=>'Bulanan','tahunan'=>'Tahunan','custom'=>'Dari - Sampai Tanggal'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $periode===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 chart-input" data-periode="bulanan">
      <label class="form-label small">Bulan</label>
      <input type="month" name="bulan" class="form-control form-control-sm" value="<?= esc($_GET['bulan'] ?? date('Y-m')) ?>" data-testid="chart-bulan">
    </div>
    <div class="col-md-2 chart-input" data-periode="tahunan">
      <label class="form-label small">Tahun</label>
      <select name="tahun" class="form-select form-select-sm" data-testid="chart-tahun">
        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
        <option value="<?= $y ?>" <?= ($_GET['tahun'] ?? date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2 chart-input" data-periode="custom">
      <label class="form-label small">Dari Tanggal</label>
      <input type="date" name="dari" class="form-control form-control-sm" value="<?= esc($_GET['dari'] ?? date('Y-m-01')) ?>" data-testid="chart-dari">
    </div>
    <div class="col-md-2 chart-input" data-periode="custom">
      <label class="form-label small">Sampai Tanggal</label>
      <input type="date" name="sampai" class="form-control form-control-sm" value="<?= esc($_GET['sampai'] ?? date('Y-m-d')) ?>" data-testid="chart-sampai">
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-primary w-100" data-testid="chart-filter-btn"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
    </div>
  </form>
</div></div>

<?php if (!$top): ?>
<div class="alert alert-info" data-testid="chart-empty">Tidak ada transaksi pada periode <strong><?= esc($label) ?></strong>.</div>
<?php else: ?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3">Total Belanja per Pelanggan — <?= esc($label) ?></h2>
      <canvas id="chartBelanja" data-testid="chart-belanja" height="260"></canvas>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card table-card h-100"><div class="card-body">
      <h2 class="h6 mb-3">Frekuensi Transaksi</h2>
      <canvas id="chartFrekuensi" data-testid="chart-frekuensi" height="260"></canvas>
    </div></div>
  </div>
</div>

<div class="card table-card"><div class="card-body">
  <h2 class="h6 mb-3">Peringkat Pelanggan</h2>
  <div class="table-responsive">
  <table class="table table-sm align-middle" data-testid="chart-table">
    <thead><tr><th>#</th><th>Pelanggan</th><th class="text-end">Jml Transaksi</th><th class="text-end">Total Belanja</th><th class="text-end">Kontribusi</th></tr></thead>
    <tbody>
    <?php foreach ($top as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="fw-semibold"><?= esc($r['nama']) ?></td>
        <td class="text-end"><?= $r['jml'] ?>x</td>
        <td class="text-end"><?= rupiah($r['total']) ?></td>
        <td class="text-end"><?= $total_semua ? round($r['total'] / $total_semua * 100, 1) : 0 ?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const DATA = <?= json_encode($top, JSON_UNESCAPED_UNICODE) ?>;
const warna = DATA.map((_, i) => `hsl(${(i * 37) % 360} 70% 55%)`);
const rupiahSingkat = n => n >= 1e6 ? 'Rp ' + (n/1e6).toLocaleString('id-ID') + ' jt' : 'Rp ' + Math.round(n/1000) + ' rb';

new Chart(document.getElementById('chartBelanja'), {
  type: 'bar',
  data: {
    labels: DATA.map(d => d.nama),
    datasets: [{ label: 'Total Belanja (Rp)', data: DATA.map(d => d.total), backgroundColor: warna, borderRadius: 6 }]
  },
  options: {
    indexAxis: 'y',
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => 'Rp ' + c.raw.toLocaleString('id-ID') } } },
    scales: { x: { ticks: { callback: rupiahSingkat } } }
  }
});

new Chart(document.getElementById('chartFrekuensi'), {
  type: 'doughnut',
  data: {
    labels: DATA.map(d => d.nama),
    datasets: [{ data: DATA.map(d => d.jml), backgroundColor: warna }]
  },
  options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.raw + 'x transaksi' } } } }
});
</script>
<?php endif; ?>

<script>
// Tampilkan input sesuai periode yang dipilih
function toggleChartPeriode() {
  const p = document.getElementById('chartPeriode').value;
  document.querySelectorAll('.chart-input').forEach(el => {
    el.style.display = el.dataset.periode.split(' ').includes(p) ? '' : 'none';
  });
}
document.getElementById('chartPeriode').addEventListener('change', toggleChartPeriode);
toggleChartPeriode();
</script>
