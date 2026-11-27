<?php
// ============================================================
// po_print.php - Cetak BON / PURCHASE ORDER ke supplier (A4, TANPA HARGA).
// Gaya mengikuti Faktur Tagihan Supplier (purchase_receipt.php). Khusus admin.
// ============================================================
require_admin();
require_once __DIR__ . '/../includes/purchase_order.php';
$db = db();
po_ensure_schema($db);
$id = (int)($_GET['id'] ?? 0);
$po = po_get($db, $id);
if (!$po) {
    set_flash('danger', 'Purchase Order tidak ditemukan.');
    header('Location: index.php?page=suppliers&tab=po'); exit;
}
$items = po_items($db, $id);
$tgl_fmt = _valid_date($po['tgl']) ? date('d/m/Y', strtotime($po['tgl'])) : lokal($po['created_at'], 'd/m/Y');
$total_qty = 0; foreach ($items as $r) $total_qty += (int)$r['qty'];
$stempel_img = setting('stempel');
$stempel_ok  = $stempel_img && is_file(__DIR__ . '/../' . $stempel_img);
$sup_nama = $po['supplier_nama'] ?? '-';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Order <?= esc($po['no_po']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#e9ecef; font-family: Arial, Helvetica, sans-serif; color:#111; }
  .faktur { width: 190mm; max-width: 100%; margin: 12px auto; background:#fff; padding: 8mm 9mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); position: relative; }
  .faktur .kop { border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 8px; }
  .faktur h1.brand { font-size: 19px; font-weight: 800; letter-spacing:1px; margin:0; text-transform:uppercase; }
  .faktur .kop small { font-size: 11px; }
  .meta-table td { padding: 1px 6px 1px 0; font-size: 11.5px; vertical-align: top; }
  .faktur-title { font-size: 15px; font-weight:700; letter-spacing:2px; text-transform:uppercase; }
  table.items { width:100%; border-collapse: collapse; font-size: 11.5px; margin-top: 5px; }
  table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; }
  table.items thead th { background:#f0f0f0; text-align:center; font-weight:700; }
  table.items td.ctr { text-align:center; }
  table.items tfoot td { font-weight:600; }
  .sign { font-size: 11.5px; text-align:center; }
  .sign .line { height: 18mm; }
  .stempel-img { width: 24mm; height: 24mm; object-fit: contain; opacity: .85; transform: rotate(-6deg); }
  @media print {
    @page { size: A4 portrait; margin: 7mm; }
    body { background:#fff; }
    .no-print { display:none !important; }
    .faktur { width:auto; margin:0; box-shadow:none; padding:0; }
  }
</style>
</head>
<body>
<div class="faktur" data-testid="po-print">
  <div class="kop d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
      <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:52px">
      <?php endif; ?>
      <div>
        <h1 class="brand"><?= esc(strtoupper(setting('nama_bengkel', 'BENGKEL MOTOR'))) ?></h1>
        <?php if (setting('alamat')): ?><small><?= esc(setting('alamat')) ?><?= setting('telepon') ? ' &middot; Telp ' . esc(setting('telepon')) : '' ?></small><?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <div class="faktur-title">Purchase Order</div>
      <div style="font-size:11.5px">Bon Belanja &middot; No. <strong><?= esc($po['no_po']) ?></strong></div>
    </div>
  </div>

  <div class="row g-0 mb-1">
    <div class="col-7">
      <table class="meta-table">
        <tr><td width="80">Kepada</td><td>: <strong><?= esc($sup_nama) ?></strong></td></tr>
        <?php if (!empty($po['supplier_telepon'])): ?><tr><td>Telepon</td><td>: <?= esc($po['supplier_telepon']) ?></td></tr><?php endif; ?>
        <?php if (!empty($po['supplier_alamat'])): ?><tr><td>Alamat</td><td>: <?= esc($po['supplier_alamat']) ?></td></tr><?php endif; ?>
      </table>
    </div>
    <div class="col-5">
      <table class="meta-table">
        <tr><td width="90">Tanggal</td><td>: <?= esc($tgl_fmt) ?></td></tr>
        <tr><td>No. PO</td><td>: <?= esc($po['no_po']) ?></td></tr>
        <tr><td>Dibuat oleh</td><td>: <?= esc($po['user_nama'] !== '' ? $po['user_nama'] : '-') ?></td></tr>
      </table>
    </div>
  </div>

  <div style="font-size:11.5px">Dengan hormat, mohon disediakan barang berikut:</div>
  <table class="items" data-testid="po-print-items">
    <thead><tr><th style="width:28px">No</th><th style="width:130px">Kode Part</th><th>Nama Part</th><th style="width:48px">Qty</th><th style="width:170px">Catatan</th></tr></thead>
    <tbody>
    <?php $no = 0; foreach ($items as $r): $no++; ?>
      <tr>
        <td class="ctr"><?= $no ?></td>
        <td><?= $r['kode'] !== '' ? esc($r['kode']) : '-' ?></td>
        <td><?= esc($r['nama']) ?></td>
        <td class="ctr"><?= (int)$r['qty'] ?></td>
        <td><?= esc($r['catatan']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" style="text-align:right">Total Barang (<?= count($items) ?> item)</td><td class="ctr"><?= $total_qty ?></td><td></td></tr>
    </tfoot>
  </table>

  <?php if (trim((string)$po['catatan']) !== ''): ?>
  <div style="font-size:11.5px; margin-top:6px"><strong>Catatan:</strong> <?= esc($po['catatan']) ?></div>
  <?php endif; ?>

  <div class="row g-0 mt-3">
    <div class="col-6 sign">Hormat kami,<div class="line d-flex align-items-center justify-content-center"><?php if ($stempel_ok): ?><img class="stempel-img" src="<?= esc($stempel_img) ?>" alt="Stempel"><?php endif; ?></div>
      ( <?= esc($po['user_nama'] !== '' ? $po['user_nama'] : 'Petugas') ?> )</div>
    <div class="col-6 sign">Supplier,<div class="line"></div>( <?= esc($sup_nama) ?> )</div>
  </div>
  <div style="font-size:10px; color:#555; margin-top:8px">Dicetak <?= date('d/m/Y H:i') ?> &middot; Dokumen ini adalah pesanan barang (Purchase Order) dan tidak memuat harga.</div>
</div>

<div class="faktur no-print" style="padding:14px">
  <div class="d-flex flex-wrap gap-2">
    <button onclick="window.print()" class="btn btn-primary btn-sm" data-testid="po-print-btn"><i class="bi bi-printer"></i> Cetak A4 Portrait</button>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=suppliers&amp;tab=po&amp;open=<?= (int)$po['id'] ?>"><i class="bi bi-arrow-left"></i> Riwayat Purchase Order</a>
  </div>
</div>
</body>
</html>
