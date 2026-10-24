<?php
// ============================================================
// purchase_receipt.php - Faktur TAGIHAN SUPPLIER (barang masuk), cetak A4.
// Tagihan = jumlah yang harus dibayar bengkel kepada supplier.
// ============================================================
$db = db();
require_once __DIR__ . '/../includes/purchase.php';
$id = (int)($_GET['id'] ?? 0);

$st = $db->prepare("SELECT i.*, s.nama AS supplier_nama, s.telepon AS supplier_telepon, s.alamat AS supplier_alamat
    FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.id = ?");
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    set_flash('danger', 'Faktur tagihan supplier tidak ditemukan.');
    header('Location: index.php?page=suppliers'); exit;
}

// ---- Ubah status pembayaran + jatuh tempo (PRG) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $sb = ($_POST['status_bayar'] ?? 'belum') === 'lunas' ? 'lunas' : 'belum';
    $jt = trim($_POST['jatuh_tempo'] ?? '');
    if ($sb === 'lunas' || !_valid_date($jt)) $jt = '';
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE supplier_invoices SET status_bayar=?, jatuh_tempo=? WHERE id=?")
           ->execute([$sb, ($jt !== '' ? $jt : null), $id]);
        sync_purchase_debt($db, $id);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (function_exists('app_log')) app_log('error', 'Sinkron hutang faktur supplier gagal: ' . $e->getMessage());
    }
    header('Location: index.php?page=purchase_receipt&id=' . $id); exit;
}

$it = $db->prepare("SELECT * FROM supplier_invoice_items WHERE invoice_id=? ORDER BY id");
$it->execute([$id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);
$debt = null;
$ds = $db->prepare("SELECT id, jumlah, dibayar, status FROM debts WHERE purchase_id=? LIMIT 1");
$ds->execute([$id]);
$debt = $ds->fetch(PDO::FETCH_ASSOC) ?: null;

$is_lunas = ($inv['status_bayar'] === 'lunas');
$stamp_color = $is_lunas ? '#198754' : '#dc3545';
$tgl_fmt = _valid_date($inv['tgl']) ? date('d/m/Y', strtotime($inv['tgl'])) : lokal($inv['created_at'], 'd/m/Y');
$jt_fmt  = (!empty($inv['jatuh_tempo']) && _valid_date($inv['jatuh_tempo'])) ? date('d/m/Y', strtotime($inv['jatuh_tempo'])) : '';
$total_qty = 0; foreach ($items as $r) $total_qty += (int)$r['qty'];
$sisa = $debt ? ((float)$debt['jumlah'] - (float)$debt['dibayar']) : ($is_lunas ? 0 : (float)$inv['total']);
$stempel_img = setting('stempel');
$stempel_ok  = $stempel_img && is_file(__DIR__ . '/../' . $stempel_img);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faktur Tagihan <?= esc($inv['no_faktur']) ?></title>
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
  table.items td.num { text-align:right; white-space:nowrap; }
  table.items td.ctr { text-align:center; }
  table.items tfoot td { font-weight:600; }
  .stamp-wrap { position: relative; }
  .stamp {
    position: absolute; left: 6mm; top: -9mm; z-index: 5;
    display:inline-block; transform: rotate(-7deg);
    border: 3px double <?= $stamp_color ?>; color: <?= $stamp_color ?>;
    font-weight: 800; padding: 4px 18px; border-radius: 8px;
    letter-spacing: 3px; font-size: 17px; text-transform: uppercase;
    background: rgba(255,255,255,.55); opacity: .88;
  }
  .stamp-sub { font-size: 10px; letter-spacing: 1px; font-weight: 700; }
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
<div class="faktur" data-testid="purchase-receipt">
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
      <div class="faktur-title">Faktur Tagihan Supplier</div>
      <div style="font-size:11.5px">No. <strong><?= esc($inv['no_faktur']) ?></strong></div>
    </div>
  </div>

  <div class="row g-0 mb-1">
    <div class="col-7">
      <table class="meta-table">
        <tr><td width="92">Supplier</td><td>: <strong><?= esc($inv['supplier_nama']) ?></strong></td></tr>
        <?php if (!empty($inv['supplier_telepon'])): ?><tr><td>Telepon</td><td>: <?= esc($inv['supplier_telepon']) ?></td></tr><?php endif; ?>
        <?php if (!empty($inv['supplier_alamat'])): ?><tr><td>Alamat</td><td>: <?= esc($inv['supplier_alamat']) ?></td></tr><?php endif; ?>
      </table>
    </div>
    <div class="col-5">
      <table class="meta-table">
        <tr><td width="100">Tanggal</td><td>: <?= esc($tgl_fmt) ?></td></tr>
        <tr><td>No. Faktur Supplier</td><td>: <?= $inv['no_faktur_supplier'] !== '' ? esc($inv['no_faktur_supplier']) : '-' ?></td></tr>
        <?php if (!$is_lunas && $jt_fmt): ?><tr><td>Jatuh Tempo</td><td>: <strong><?= esc($jt_fmt) ?></strong></td></tr><?php endif; ?>
      </table>
    </div>
  </div>

  <div class="stamp-wrap">
    <div class="stamp" data-testid="purchase-stamp"><?= $is_lunas ? 'LUNAS' : 'BELUM LUNAS' ?><?php if (!$is_lunas && $jt_fmt): ?><div class="stamp-sub">Jatuh tempo <?= esc($jt_fmt) ?></div><?php endif; ?></div>
    <table class="items" data-testid="purchase-receipt-items">
      <thead><tr><th style="width:28px">No</th><th style="width:110px">Kode</th><th>Nama Sparepart</th><th style="width:48px">Qty</th><th style="width:100px">Harga Beli</th><th style="width:110px">Subtotal</th></tr></thead>
      <tbody>
      <?php $no = 0; foreach ($items as $r): $no++; ?>
        <tr>
          <td class="ctr"><?= $no ?></td>
          <td><?= esc($r['kode']) ?></td>
          <td><?= esc($r['nama']) ?></td>
          <td class="ctr"><?= (int)$r['qty'] ?></td>
          <td class="num"><?= rupiah($r['harga_beli']) ?></td>
          <td class="num"><?= rupiah($r['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right">Total Barang</td><td class="ctr"><?= $total_qty ?></td><td style="text-align:right">TOTAL TAGIHAN</td><td class="num"><?= rupiah($inv['total']) ?></td></tr>
        <?php if ($debt && (float)$debt['dibayar'] > 0): ?>
        <tr><td colspan="5" style="text-align:right">Sudah dibayar</td><td class="num"><?= rupiah($debt['dibayar']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="text-align:right"><?= $is_lunas ? 'Sisa tagihan' : 'SISA YANG HARUS DIBAYAR' ?></td><td class="num"><?= rupiah($sisa) ?></td></tr>
      </tfoot>
    </table>
  </div>

  <?php if (trim((string)$inv['catatan']) !== ''): ?>
  <div style="font-size:11.5px; margin-top:6px"><strong>Catatan:</strong> <?= esc($inv['catatan']) ?></div>
  <?php endif; ?>

  <div class="row g-0 mt-3">
    <div class="col-6 sign">Diterima / dibuat oleh,<div class="line d-flex align-items-center justify-content-center"><?php if ($stempel_ok): ?><img class="stempel-img" src="<?= esc($stempel_img) ?>" alt="Stempel"><?php endif; ?></div>
      ( <?= esc($inv['user_nama'] !== '' ? $inv['user_nama'] : 'Petugas') ?> )</div>
    <div class="col-6 sign">Supplier,<div class="line"></div>( <?= esc($inv['supplier_nama']) ?> )</div>
  </div>
  <div style="font-size:10px; color:#555; margin-top:8px">Dicetak <?= date('d/m/Y H:i') ?> &middot; Dokumen ini adalah rekap tagihan barang masuk dari supplier kepada bengkel.</div>
</div>

<!-- PANEL KONTROL (tidak ikut tercetak) -->
<div class="faktur no-print" style="padding:14px">
  <form method="post" action="index.php?page=purchase_receipt&id=<?= (int)$id ?>" class="border rounded p-2 bg-light mb-3" data-testid="purchase-status-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_status">
    <div class="fw-semibold mb-1"><i class="bi bi-cash-coin me-1"></i>Status Pembayaran ke Supplier</div>
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <div class="btn-group btn-group-sm" role="group">
          <input type="radio" class="btn-check" name="status_bayar" id="pLunas" value="lunas" <?= $is_lunas ? 'checked' : '' ?>>
          <label class="btn btn-outline-success" for="pLunas">LUNAS</label>
          <input type="radio" class="btn-check" name="status_bayar" id="pBelum" value="belum" <?= !$is_lunas ? 'checked' : '' ?>>
          <label class="btn btn-outline-danger" for="pBelum">BELUM LUNAS</label>
        </div>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Jatuh tempo</label>
        <input type="date" name="jatuh_tempo" value="<?= esc((string)($inv['jatuh_tempo'] ?? '')) ?>" class="form-control form-control-sm" data-testid="purchase-status-jt">
      </div>
      <div class="col-auto"><button class="btn btn-primary btn-sm" data-testid="purchase-status-save"><i class="bi bi-check2 me-1"></i>Simpan Status</button></div>
    </div>
    <?php if (!$is_lunas && $debt): ?>
    <div class="small mt-2 text-danger"><i class="bi bi-journal-check me-1"></i>Tercatat otomatis di <a href="index.php?page=debts&amp;jenis=hutang">Hutang Piutang</a> sebagai hutang ke <strong><?= esc($inv['supplier_nama']) ?></strong> &mdash; sisa <?= rupiah($sisa) ?>.</div>
    <?php endif; ?>
  </form>
  <div class="d-flex flex-wrap gap-2">
    <button onclick="window.print()" class="btn btn-primary btn-sm" data-testid="purchase-print-btn"><i class="bi bi-printer"></i> Cetak A4 Portrait</button>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=stock"><i class="bi bi-arrow-left"></i> Input Barang Masuk</a>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=suppliers&amp;supplier_id=<?= (int)$inv['supplier_id'] ?>"><i class="bi bi-truck"></i> Faktur Supplier Ini</a>
  </div>
</div>
</body>
</html>
