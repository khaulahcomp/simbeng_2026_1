<?php
// Lembar bukti penerimaan klaim garansi (tampilan cetak, tanpa sidebar)
$db = db();
$id = (int)($_GET['id'] ?? 0);
$c = $db->prepare("SELECT w.*, t.no_nota, cu.nama AS customer_nama, cu.telepon, cu.alamat, p.nama AS replacement_nama
    FROM warranty_claims w
    JOIN transactions t ON t.id=w.transaction_id
    JOIN customers cu ON cu.id=w.customer_id
    LEFT JOIN parts p ON p.id=w.replacement_part_id
    WHERE w.id=?");
$c->execute([$id]);
$c = $c->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo '<p style="font-family:sans-serif">Klaim tidak ditemukan. <a href="index.php?page=warranty">Kembali</a></p>'; return; }
$label = ['pending'=>'Pending / Diajukan', 'diproses'=>'Sedang Diproses', 'disetujui'=>'Disetujui / Diganti Baru', 'ditolak'=>'Ditolak'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Bukti Klaim <?= esc($c['kode']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #eee; font-family: monospace; }
  .nota { max-width: 480px; margin: 20px auto; background: #fff; padding: 24px; }
  .nota table { width: 100%; font-size: 13px; }
  @media print { .no-print { display: none; } body { background: #fff; } .nota { margin: 0; } }
</style>
</head>
<body>
<div class="nota" data-testid="warranty-receipt">
  <div class="text-center">
    <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
    <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:56px;margin-bottom:6px" data-testid="warranty-logo"><br>
    <?php endif; ?>
    <strong style="font-size:16px"><?= esc(strtoupper(setting('nama_bengkel', 'BENGKEL MOTOR'))) ?></strong><br>
    <?php if (setting('alamat')): ?><span style="font-size:12px"><?= esc(setting('alamat')) ?><?= setting('telepon') ? ' - Telp ' . esc(setting('telepon')) : '' ?></span><br><?php endif; ?>
    <?php if (setting('nib')): ?><span style="font-size:11px">NIB: <?= esc(setting('nib')) ?></span><br><?php endif; ?>
    <strong style="font-size:13px">BUKTI PENERIMAAN KLAIM GARANSI</strong>
    <hr>
  </div>
  <table>
    <tr><td style="width:42%">Kode Klaim</td><td>: <strong><?= esc($c['kode']) ?></strong></td></tr>
    <tr><td>Tanggal Pengajuan</td><td>: <?= esc(lokal($c['created_at'])) ?> WIB</td></tr>
    <tr><td>Waktu Cetak</td><td>: <span id="waktuCetak" data-testid="waktu-cetak"></span></td></tr>
    <tr><td>Nota Terkait</td><td>: <?= esc($c['no_nota']) ?></td></tr>
    <tr><td>Pelanggan</td><td>: <?= esc($c['customer_nama']) ?> (<?= esc($c['telepon']) ?>)</td></tr>
    <tr><td>Item Digeransikan</td><td>: <?= esc($c['item_nama']) ?></td></tr>
    <tr><td>Tanggal Beli/Servis</td><td>: <?= esc($c['tgl_beli']) ?></td></tr>
    <tr><td>Garansi Berlaku s.d.</td><td>: <?= esc($c['tgl_berakhir']) ?></td></tr>
    <tr><td>Status Klaim</td><td>: <strong><?= $label[$c['status']] ?></strong></td></tr>
    <tr><td>Keluhan</td><td>: <?= esc($c['alasan']) ?></td></tr>
    <?php if ($c['catatan_teknisi']): ?><tr><td>Catatan Teknisi</td><td>: <?= esc($c['catatan_teknisi']) ?></td></tr><?php endif; ?>
    <?php if ($c['replacement_nama']): ?><tr><td>Part Pengganti</td><td>: <?= esc($c['replacement_nama']) ?></td></tr><?php endif; ?>
  </table>
  <hr>
  <table style="margin-top:30px">
    <tr>
      <td class="text-center">Pelanggan<br><br><br>( <?= esc($c['customer_nama']) ?> )</td>
      <td class="text-center">Petugas Bengkel<br><br><br>( ________________ )</td>
    </tr>
  </table>
  <div class="text-center mt-3 no-print">
    <button onclick="window.print()" class="btn btn-primary btn-sm" data-testid="warranty-print-btn"><i class="bi bi-printer"></i> Cetak</button>
    <a href="index.php?page=warranty&claim=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
  </div>
</div>
<script>
// Waktu cetak realtime mengikuti jam perangkat pengguna
function tickWaktu() {
  const el = document.getElementById('waktuCetak');
  if (el) el.textContent = new Date().toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
tickWaktu();
setInterval(tickWaktu, 1000);
</script>
</body>
</html>
