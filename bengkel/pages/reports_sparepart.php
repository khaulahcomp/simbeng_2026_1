<?php
// ============================================================
// Rekap & Laporan -> tab "Per Sparepart"
// Rekap penjualan sparepart per kode: semua faktur yang memuat
// sparepart yang sama dijumlahkan jadi satu baris. Klik baris untuk
// melihat faktur asalnya. Unduh Excel/PDF via export.php?type=parts_sales.
// Dipanggil dari pages/reports.php (bukan route tersendiri).
// HANYA MEMBACA data - lihat includes/rekap_sparepart.php.
// ============================================================
require_once __DIR__ . '/../includes/rekap_sparepart.php';

[$periode, $dari, $sampai, $label] = resolve_periode();
$f = rekap_sparepart_filter();
$rows = rekap_sparepart($dari, $sampai, $f);
$kategori_list = rekap_sparepart_kategori();

$sel_part = isset($_GET['part']) ? (int)$_GET['part'] : 0;
$rincian = $sel_part > 0 ? rekap_sparepart_rincian($dari, $sampai, $f, $sel_part) : [];

$tot_qty    = array_sum(array_column($rows, 'qty'));
$tot_diskon = array_sum(array_column($rows, 'diskon'));
$tot_all    = array_sum(array_column($rows, 'total'));
// Faktur unik yang memuat minimal satu sparepart dalam hasil
$tot_faktur = 0;
if ($rows) {
    [$w, $pr] = _rekap_sparepart_where($dari, $sampai, $f);
    $st = db()->prepare("SELECT COUNT(DISTINCT ti.transaction_id) FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transaction_id LEFT JOIN parts p ON p.id = ti.part_id WHERE $w");
    $st->execute($pr);
    $tot_faktur = (int)$st->fetchColumn();
}
$max_total = $rows ? max(1, max(array_map('floatval', array_column($rows, 'total')))) : 1;

// Pagination tampilan (default 50 baris/halaman). Ringkasan & TOTAL tetap
// dihitung dari SELURUH hasil, unduhan Excel/PDF juga memuat semua baris.
$per_page_opts = [25, 50, 100, 200];
$per_page = (int)($_GET['per_page'] ?? 50);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 50;
$total_rows  = count($rows);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$cur_page = max(1, (int)($_GET['p'] ?? 1));
if ($cur_page > $total_pages) $cur_page = $total_pages;
$offset = ($cur_page - 1) * $per_page;
$page_rows = array_slice($rows, $offset, $per_page);

// Parameter filter yang dibawa ke tautan (baris, export, pagination)
$base_qs = array_merge(
    array_intersect_key($_GET, array_flip(['periode', 'tanggal', 'bulan', 'tahun', 'dari', 'sampai'])),
    array_filter(['q' => $f['q'], 'kategori' => $f['kategori'], 'urut' => $f['urut'] !== 'total' ? $f['urut'] : ''], 'strlen')
);
$export_qs = http_build_query(array_merge($base_qs, ['type' => 'parts_sales']));
$page_url = function (int $p, array $extra = []) use ($base_qs, $per_page): string {
    $qs = array_merge(['page' => 'reports', 'tab' => 'sparepart'], $base_qs, ['per_page' => $per_page, 'p' => $p], $extra);
    return 'index.php?' . http_build_query($qs);
};
$row_url = function (int $pid) use ($page_url, $cur_page, $sel_part): string {
    // klik baris yang sedang terbuka = tutup
    return $page_url($cur_page, $pid !== $sel_part ? ['part' => $pid] : []) . '#sp-' . $pid;
};
?>
<style>
  .sp-row { cursor: pointer; }
  .sp-row:hover td { background: #f6f9ff; }
  .sp-row.sp-open td { background: #eef4ff; }
  .sp-bar { height: 5px; border-radius: 3px; background: #ffe0c7; margin-top: 4px; max-width: 220px; }
  .sp-bar span { display: block; height: 100%; border-radius: 3px; background: #ff7a1a; }
  .sp-chev { display: inline-block; width: 12px; color: #6c757d; transition: transform .2s; }
  .sp-open .sp-chev { transform: rotate(90deg); }
  .sp-detail > td { background: #fbfcfe !important; padding: 4px 8px 12px 24px; }
  /* Tampilan ringkas: tidak melebar penuh di layar lebar */
  .sp-wrap { max-width: 1180px; }
  .sp-table { font-size: .875rem; }
  .sp-table th, .sp-table td { white-space: nowrap; }
  .sp-table td.sp-nama { white-space: normal; min-width: 220px; max-width: 360px; }
  .sp-kat { font-size: .72rem; color: #5b6b7c; }
  /* Faktur BELUM LUNAS di rincian: border & stabilo merah muda (seperti Riwayat Transaksi) */
  .sp-belum td { background: #fff3f6 !important; border-top: 1.5px solid #ff7a9a !important; border-bottom: 1.5px solid #ff7a9a !important; }
  .sp-belum td:first-child { border-left: 1.5px solid #ff7a9a !important; }
  .sp-belum td:last-child { border-right: 1.5px solid #ff7a9a !important; }
  mark.nota-belum { color: inherit; font-weight: 600; padding: 1px 6px; border-radius: 4px;
    background: linear-gradient(100deg, rgba(255,70,110,.18) 0%, rgba(255,70,110,.5) 8%, rgba(255,70,110,.42) 92%, rgba(255,70,110,.2) 100%); }
</style>
<div class="sp-wrap">

<div class="card table-card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end" data-testid="sp-filter-form">
    <input type="hidden" name="page" value="reports">
    <input type="hidden" name="tab" value="sparepart">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <div class="col-md-2">
      <label class="form-label small">Periode</label>
      <select name="periode" id="periode" class="form-select form-select-sm" data-testid="sp-periode">
        <?php foreach (['harian'=>'Harian','mingguan'=>'Mingguan','bulanan'=>'Bulanan','tahunan'=>'Tahunan','custom'=>'Dari - Sampai Tanggal'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $periode===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 periode-input" data-periode="harian mingguan">
      <label class="form-label small">Tanggal</label>
      <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= esc($_GET['tanggal'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2 periode-input" data-periode="bulanan">
      <label class="form-label small">Bulan</label>
      <input type="month" name="bulan" class="form-control form-control-sm" value="<?= esc($_GET['bulan'] ?? date('Y-m')) ?>">
    </div>
    <div class="col-md-2 periode-input" data-periode="tahunan">
      <label class="form-label small">Tahun</label>
      <select name="tahun" class="form-select form-select-sm">
        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
        <option value="<?= $y ?>" <?= ($_GET['tahun'] ?? date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-2 periode-input" data-periode="custom">
      <label class="form-label small">Dari Tanggal</label>
      <input type="date" name="dari" class="form-control form-control-sm" value="<?= esc($_GET['dari'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2 periode-input" data-periode="custom">
      <label class="form-label small">Sampai Tanggal</label>
      <input type="date" name="sampai" class="form-control form-control-sm" value="<?= esc($_GET['sampai'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small">Kategori</label>
      <select name="kategori" class="form-select form-select-sm" data-testid="sp-kategori">
        <option value="">Semua kategori</option>
        <?php foreach ($kategori_list as $k): ?>
        <option value="<?= esc($k) ?>" <?= $f['kategori'] === $k ? 'selected' : '' ?>><?= esc($k) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">Cari kode / nama</label>
      <input type="search" name="q" class="form-control form-control-sm" value="<?= esc($f['q']) ?>" placeholder="mis. 06455 atau kampas" data-testid="sp-q">
    </div>
    <div class="col-md-2">
      <label class="form-label small">Urutkan</label>
      <select name="urut" class="form-select form-select-sm" data-testid="sp-urut">
        <option value="total" <?= $f['urut']==='total'?'selected':'' ?>>Total penjualan terbesar</option>
        <option value="qty" <?= $f['urut']==='qty'?'selected':'' ?>>Qty terbanyak</option>
        <option value="kode" <?= $f['urut']==='kode'?'selected':'' ?>>Kode A-Z</option>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-primary w-100" data-testid="sp-filter-btn"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
    </div>
  </form>
</div></div>

<div class="card table-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="h6 mb-0" data-testid="sp-title">Rekap Penjualan Sparepart — <?= esc($label) ?></h2>
    <div data-testid="sp-export-buttons">
      <a class="btn btn-sm btn-outline-danger" target="_blank" href="export.php?<?= esc($export_qs) ?>&format=pdf" data-testid="sp-export-pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      <a class="btn btn-sm btn-outline-success" href="export.php?<?= esc($export_qs) ?>&format=xls" data-testid="sp-export-xls"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
  </div>

  <div class="row g-2 mb-3" data-testid="sp-summary">
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Jenis Sparepart Terjual</div><div class="fw-bold" data-testid="sp-sum-jenis"><?= count($rows) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Total Qty</div><div class="fw-bold" data-testid="sp-sum-qty"><?= (int)$tot_qty ?> pcs</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Faktur Terlibat</div><div class="fw-bold" data-testid="sp-sum-faktur"><?= $tot_faktur ?></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center bg-success text-white"><div class="small">Total Penjualan Sparepart</div><div class="fw-bold" data-testid="sp-sum-total"><?= rupiah($tot_all) ?></div></div></div>
  </div>

  <div class="table-responsive">
  <table class="table table-sm align-middle sp-table" data-testid="sp-table">
    <thead><tr><th>Kode</th><th>Nama Sparepart</th><th class="text-end">Faktur</th><th class="text-end">Qty</th><th class="text-end">Harga Rata²</th><th class="text-end">Diskon</th><th class="text-end">Total</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Tidak ada penjualan sparepart pada periode / filter ini.</td></tr><?php endif; ?>
    <?php foreach ($page_rows as $r): $pid = (int)$r['part_id']; $open = $pid === $sel_part; ?>
      <tr id="sp-<?= $pid ?>" class="sp-row <?= $open ? 'sp-open' : '' ?>" data-href="<?= esc($row_url($pid)) ?>" data-testid="sp-row-<?= $pid ?>">
        <td class="text-nowrap"><span class="sp-chev">›</span><a href="<?= esc($row_url($pid)) ?>" class="text-decoration-none text-reset"><code><?= esc($r['kode'] !== '' ? $r['kode'] : '-') ?></code></a></td>
        <td class="sp-nama"><?= esc($r['nama']) ?><?php if ($r['kategori'] !== ''): ?> <span class="sp-kat">· <?= esc($r['kategori']) ?></span><?php endif; ?><div class="sp-bar"><span style="width:<?= round((float)$r['total'] / $max_total * 100) ?>%"></span></div></td>
        <td class="text-end"><?= (int)$r['jml_faktur'] ?></td>
        <td class="text-end"><?= (int)$r['qty'] ?></td>
        <td class="text-end text-nowrap"><?= rupiah($r['harga_rata']) ?></td>
        <td class="text-end text-nowrap"><?= (float)$r['diskon'] > 0 ? rupiah($r['diskon']) : '-' ?></td>
        <td class="text-end text-nowrap fw-semibold"><?= rupiah($r['total']) ?></td>
      </tr>
      <?php if ($open): ?>
      <tr class="sp-detail" data-testid="sp-detail-<?= $pid ?>"><td colspan="7">
        <table class="table table-sm mb-0 small sp-rincian">
          <thead><tr class="text-muted"><th>No. Nota</th><th>Tanggal</th><th>Pelanggan</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Diskon</th><th class="text-end">Subtotal</th></tr></thead>
          <tbody>
          <?php foreach ($rincian as $d): $belum = (($d['status_bayar'] ?? 'lunas') === 'belum'); ?>
            <tr class="<?= $belum ? 'sp-belum' : '' ?>" <?= $belum ? 'title="Faktur belum lunas"' : '' ?>>
              <td><a href="index.php?page=receipt&id=<?= (int)$d['transaction_id'] ?>" target="_blank" class="text-reset"><?php if ($belum): ?><mark class="nota-belum" data-testid="sp-nota-belum-<?= (int)$d['transaction_id'] ?>"><?= esc($d['no_nota']) ?></mark><?php else: ?><span class="text-primary"><?= esc($d['no_nota']) ?></span><?php endif; ?></a></td>
              <td class="text-nowrap"><?= esc(lokal($d['created_at'])) ?></td>
              <td><?= esc($d['customer_nama'] ?? '-') ?></td>
              <td class="text-end"><?= (int)$d['qty'] ?></td>
              <td class="text-end text-nowrap"><?= rupiah($d['harga']) ?></td>
              <td class="text-end text-nowrap"><?= (float)$d['diskon'] > 0 ? rupiah($d['diskon']) : '-' ?></td>
              <td class="text-end text-nowrap"><?= rupiah($d['subtotal']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="small text-muted mt-1"><mark class="nota-belum">TRX</mark> = faktur belum lunas</div>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr class="fw-bold table-light"><td colspan="2">TOTAL<?= $total_pages > 1 ? ' (semua halaman)' : '' ?></td><td class="text-end"><?= $tot_faktur ?></td><td class="text-end"><?= (int)$tot_qty ?></td><td></td><td class="text-end text-nowrap"><?= rupiah($tot_diskon) ?></td><td class="text-end text-nowrap"><?= rupiah($tot_all) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
  <?php $from_no = $total_rows ? ($offset + 1) : 0; $to_no = min($offset + $per_page, $total_rows); ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 my-2" data-testid="sp-pagination">
    <div class="d-flex align-items-center gap-2 small text-muted">
      <span data-testid="sp-pagination-info">Menampilkan <strong><?= $from_no ?>&ndash;<?= $to_no ?></strong> dari <strong><?= $total_rows ?></strong> sparepart</span>
      <select class="form-select form-select-sm" style="width:auto" onchange="location.href=this.value" data-testid="sp-per-page" aria-label="Baris per halaman">
        <?php foreach ($per_page_opts as $opt): ?>
        <option value="<?= esc('index.php?' . http_build_query(array_merge(['page' => 'reports', 'tab' => 'sparepart'], $base_qs, ['per_page' => $opt]))) ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?>/hlm</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Halaman rekap sparepart">
      <ul class="pagination pagination-sm mb-0" data-testid="sp-pagination-nav">
        <li class="page-item <?= $cur_page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $cur_page > 1 ? esc($page_url($cur_page - 1)) : '#' ?>" aria-label="Sebelumnya" data-testid="sp-page-prev"><i class="bi bi-chevron-left"></i></a></li>
        <?php $start = max(1, $cur_page - 2); $end = min($total_pages, $cur_page + 2); ?>
        <?php if ($start > 1): ?>
          <li class="page-item"><a class="page-link" href="<?= esc($page_url(1)) ?>">1</a></li>
          <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
          <li class="page-item <?= $i === $cur_page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($page_url($i)) ?>" data-testid="sp-page-<?= $i ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <?php if ($end < $total_pages): ?>
          <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
          <li class="page-item"><a class="page-link" href="<?= esc($page_url($total_pages)) ?>"><?= $total_pages ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $cur_page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $cur_page < $total_pages ? esc($page_url($cur_page + 1)) : '#' ?>" aria-label="Berikutnya" data-testid="sp-page-next"><i class="bi bi-chevron-right"></i></a></li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <div class="small text-muted">Klik baris untuk melihat faktur asal. Total sudah dipotong diskon per item; diskon nota (keseluruhan faktur) tidak dibagi ke sparepart.</div>
</div></div>
</div><!-- /.sp-wrap -->

<script>
function togglePeriode() {
  const p = document.getElementById('periode').value;
  document.querySelectorAll('.periode-input').forEach(el => {
    el.style.display = el.dataset.periode.split(' ').includes(p) ? '' : 'none';
  });
}
document.getElementById('periode').addEventListener('change', togglePeriode);
togglePeriode();
// Klik di mana saja pada baris rekap = buka/tutup rincian faktur
document.querySelectorAll('.sp-row').forEach(tr => tr.addEventListener('click', e => {
  if (e.target.closest('a')) return;
  window.location.href = tr.dataset.href;
}));
</script>
