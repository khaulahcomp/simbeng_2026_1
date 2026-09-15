<?php
// Kwitansi / bukti pembayaran hutang-piutang (tampilan cetak, tanpa sidebar)
$db = db();
$pid = (int)($_GET['id'] ?? 0);
$p = $db->prepare("SELECT dp.*, d.jenis, d.pihak, d.telepon, d.jumlah AS total, d.dibayar, d.keterangan AS debt_ket
    FROM debt_payments dp JOIN debts d ON d.id = dp.debt_id WHERE dp.id = ?");
$p->execute([$pid]);
$p = $p->fetch(PDO::FETCH_ASSOC);
if (!$p) { echo '<p style="font-family:sans-serif">Kwitansi tidak ditemukan. <a href="index.php?page=debts">Kembali</a></p>'; return; }

$sisa = (float)$p['total'] - (float)$p['dibayar'];
$no_kwitansi = 'KW-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT);
$nama_bengkel = strtoupper(setting('nama_bengkel', 'BENGKEL MOTOR'));

// Nomor WA (klik-kirim) untuk konfirmasi pembayaran
$wa_number = preg_replace('/\D+/', '', (string)($p['telepon'] ?? ''));
if ($wa_number !== '') {
    if (strncmp($wa_number, '0', 1) === 0)      $wa_number = '62' . substr($wa_number, 1);
    elseif (strncmp($wa_number, '62', 2) !== 0) $wa_number = '62' . $wa_number;
}
$jenisLabel = $p['jenis'] === 'piutang' ? 'Piutang (pembayaran diterima)' : 'Hutang (pembayaran dibayarkan)';
$wa_lines = [
    "*$nama_bengkel*",
    ($p['jenis'] === 'piutang'
        ? "Terima kasih {$p['pihak']}, pembayaran Anda sebesar " . rupiah($p['jumlah']) . " telah kami terima."
        : "Konfirmasi pembayaran kepada {$p['pihak']} sebesar " . rupiah($p['jumlah']) . "."),
    "",
    "No. Kwitansi : $no_kwitansi",
    "Tanggal      : " . date('d/m/Y', strtotime($p['tgl'])),
    "Dibayar      : " . rupiah($p['jumlah']),
    "Sisa         : " . rupiah($sisa),
    "",
    "Simpan pesan ini sebagai bukti pembayaran. Terima kasih 🙏",
];
$wa_url = 'https://wa.me/' . $wa_number . '?text=' . rawurlencode(implode("\n", $wa_lines));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kwitansi <?= esc($no_kwitansi) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: #eee; font-family: Arial, sans-serif; }
  .kwitansi { max-width: 520px; margin: 20px auto; background: #fff; padding: 24px; border-radius: 6px; }
  .kwitansi h2 { margin: 0; font-size: 20px; }
  .kw-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
  .kw-amount { font-size: 22px; font-weight: bold; }
  @media print { .no-print { display: none; } body { background: #fff; } .kwitansi { margin: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="kwitansi" data-testid="debt-receipt">
  <div class="text-center mb-3">
    <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
    <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:56px;margin-bottom:6px" data-testid="kwitansi-logo"><br>
    <?php endif; ?>
    <h2><?= esc($nama_bengkel) ?></h2>
    <?php if (setting('alamat')): ?><div style="font-size:12px;color:#555"><?= esc(setting('alamat')) ?><?= setting('telepon') ? ' - Telp ' . esc(setting('telepon')) : '' ?></div><?php endif; ?>
    <hr>
    <div style="letter-spacing:2px;font-weight:bold">KWITANSI PEMBAYARAN</div>
  </div>
  <div class="kw-row"><span>No. Kwitansi</span><strong data-testid="kwitansi-no"><?= esc($no_kwitansi) ?></strong></div>
  <div class="kw-row"><span>Tanggal</span><span><?= esc(date('d/m/Y', strtotime($p['tgl']))) ?></span></div>
  <div class="kw-row"><span>Jenis</span><span><?= esc($jenisLabel) ?></span></div>
  <div class="kw-row"><span><?= $p['jenis'] === 'piutang' ? 'Diterima dari' : 'Dibayarkan kepada' ?></span><strong><?= esc($p['pihak']) ?></strong></div>
  <?php if ($p['debt_ket']): ?><div class="kw-row"><span>Keterangan</span><span><?= esc($p['debt_ket']) ?></span></div><?php endif; ?>
  <?php if ($p['keterangan']): ?><div class="kw-row"><span>Catatan bayar</span><span><?= esc($p['keterangan']) ?></span></div><?php endif; ?>
  <hr>
  <div class="kw-row"><span>Jumlah dibayar</span><span class="kw-amount text-success" data-testid="kwitansi-amount"><?= rupiah($p['jumlah']) ?></span></div>
  <div class="kw-row"><span>Total tagihan</span><span><?= rupiah($p['total']) ?></span></div>
  <div class="kw-row"><span>Sisa tagihan</span><strong class="<?= $sisa > 0 ? 'text-danger' : 'text-success' ?>" data-testid="kwitansi-sisa"><?= rupiah($sisa) ?><?= $sisa <= 0 ? ' (LUNAS)' : '' ?></strong></div>
  <hr>
  <div class="d-flex justify-content-between mt-4" style="font-size:13px">
    <div class="text-center">Penerima,<br><br><br>( <?= esc($p['pihak']) ?> )</div>
    <div class="text-center">Hormat kami,<br><br><br>( <?= esc(setting('pemilik') ?: $nama_bengkel) ?> )</div>
  </div>
  <div class="text-center mt-4 no-print">
    <button onclick="window.print()" class="btn btn-primary btn-sm" data-testid="kwitansi-print-btn"><i class="bi bi-printer"></i> Cetak Kwitansi</button>
    <?php if ($wa_number !== ''): ?>
    <a href="<?= esc($wa_url) ?>" target="_blank" rel="noopener" class="btn btn-success btn-sm" data-testid="kwitansi-wa-btn"><i class="bi bi-whatsapp"></i> Kirim WhatsApp</a>
    <?php else: ?>
    <button class="btn btn-success btn-sm" disabled title="Nomor WA pihak belum diisi" data-testid="kwitansi-wa-disabled"><i class="bi bi-whatsapp"></i> WhatsApp</button>
    <?php endif; ?>
    <a href="index.php?page=debts" class="btn btn-outline-secondary btn-sm" data-testid="kwitansi-back-btn">Kembali</a>
  </div>
</div>
</body>
</html>
