<?php
// Faktur A4 Portrait — tampilan cetak (tanpa sidebar), border penuh pada item,
// dengan stempel LUNAS/BELUM LUNAS yang menimpa border faktur (anti pemalsuan).
$db = db();
$id = (int)($_GET['id'] ?? 0);
$t = $db->prepare("SELECT t.*, c.nama AS customer_nama, c.telepon, c.alamat AS customer_alamat, v.merek, v.model, v.plat_nomor
    FROM transactions t JOIN customers c ON c.id=t.customer_id LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.id=?");
$t->execute([$id]);
$t = $t->fetch(PDO::FETCH_ASSOC);
if (!$t) { echo '<p style="font-family:sans-serif">Transaksi tidak ditemukan. <a href="index.php">Kembali</a></p>'; return; }
$items = $db->prepare("SELECT ti.*, p.kode AS part_kode FROM transaction_items ti LEFT JOIN parts p ON p.id=ti.part_id WHERE ti.transaction_id=? ORDER BY CASE WHEN ti.tipe='jasa' THEN 0 ELSE 1 END, p.kode, ti.id");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// ---- Simpan status pembayaran (LUNAS / BELUM LUNAS) + jatuh tempo (PRG) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $sb = ($_POST['status_bayar'] ?? 'lunas') === 'belum' ? 'belum' : 'lunas';
    $jt = trim($_POST['jatuh_tempo'] ?? '');
    if ($sb === 'lunas') $jt = '';
    $db->prepare("UPDATE transactions SET status_bayar=?, jatuh_tempo=? WHERE id=?")
       ->execute([$sb, ($jt !== '' ? $jt : null), $id]);
    header("Location: index.php?page=receipt&id=$id"); exit;
}
$status_bayar = ($t['status_bayar'] ?? 'lunas') === 'belum' ? 'belum' : 'lunas';
$is_lunas     = $status_bayar === 'lunas';
$jatuh_tempo  = $t['jatuh_tempo'] ?? '';
$jatuh_tempo_fmt = $jatuh_tempo ? date('d/m/Y', strtotime($jatuh_tempo)) : '';
$metode = strtolower($t['metode_bayar'] ?? 'cash');

// ---- Nomor WhatsApp pelanggan ----
$wa_number = preg_replace('/\D+/', '', (string)($t['telepon'] ?? ''));
if ($wa_number !== '') {
    if (strncmp($wa_number, '0', 1) === 0)        $wa_number = '62' . substr($wa_number, 1);
    elseif (strncmp($wa_number, '62', 2) !== 0)   $wa_number = '62' . $wa_number;
}

// ---- Pesan WhatsApp: memuat RINCIAN item + harga ----
$nama_bengkel = strtoupper(setting('nama_bengkel', 'BENGKEL MOTOR'));
$garansi_lines = [];
$wa = [];
$wa[] = "*$nama_bengkel*";
$wa[] = "Terima kasih {$t['customer_nama']} atas kepercayaan Anda. Berikut rincian faktur servis Anda. 🙏";
$wa[] = "";
$wa[] = "No. Nota : {$t['no_nota']}";
$wa[] = "Tanggal  : " . lokal($t['created_at']) . " WIB";
if ($t['plat_nomor']) $wa[] = "Kendaraan: " . trim($t['merek'].' '.$t['model'].' / '.$t['plat_nomor']);
$wa[] = "";
$wa[] = "*Rincian Item:*";
$no = 0;
foreach ($items as $it) {
    $no++;
    $qty  = (int)$it['qty'];
    $unit = (float)$it['harga'];
    $lbl  = $it['nama'];
    if ($it['tipe'] === 'part') {
        $namakode = !empty($it['part_kode']) ? "{$it['part_kode']} - $lbl" : $lbl;
        $line = "$no. $namakode — {$qty} x " . rupiah($unit);
        if ((float)($it['special_price'] ?? 0) > 0) $line .= " (Special Price)";
    } else {
        $line = "$no. $lbl — " . rupiah($unit);
    }
    if ((float)($it['diskon'] ?? 0) > 0) {
        $line .= ($it['diskon_jenis'] ?? '') === 'persen'
            ? " - disc " . (0 + $it['diskon_nilai']) . "% (" . rupiah($it['diskon']) . ")"
            : " - disc " . rupiah($it['diskon']);
    }
    $line .= " = " . rupiah($it['subtotal']);
    $wa[] = $line;
    if ((int)$it['garansi_hari'] > 0) {
        $exp = date('d/m/Y', strtotime(lokal($t['created_at'], 'Y-m-d') . " +{$it['garansi_hari']} days"));
        $garansi_lines[] = "- {$it['nama']}: {$it['garansi_hari']} hari (s.d. $exp)";
    }
}
$wa[] = "";
$wa[] = "Total Jasa     : " . rupiah($t['total_jasa']);
$wa[] = "Total Sparepart: " . rupiah($t['total_part']);
if ((float)($t['diskon'] ?? 0) > 0) {
    $wa_disc = "Diskon Nota    : -" . rupiah($t['diskon']);
    if (($t['diskon_jenis'] ?? '') === 'persen') $wa_disc .= " (" . (0 + $t['diskon_nilai']) . "%)";
    $wa[] = $wa_disc;
}
$wa[] = "*GRAND TOTAL    : " . rupiah($t['grand_total']) . "*";
$wa[] = "Pembayaran     : " . ($metode === 'transfer' ? 'Transfer' : 'Cash');
$wa[] = "Status         : " . ($is_lunas ? 'LUNAS' : 'BELUM LUNAS');
if (!$is_lunas && $jatuh_tempo_fmt) $wa[] = "Jatuh Tempo    : $jatuh_tempo_fmt";
if ($garansi_lines) { $wa[] = ""; $wa[] = "*Info Garansi:*"; $wa = array_merge($wa, $garansi_lines); }
$wa[] = "";
$wa[] = "Faktur (gambar) terlampir. Simpan sebagai bukti yang sah. Sampai jumpa kembali 🙏";
$wa_text = implode("\n", $wa);
$wa_url  = 'https://wa.me/' . $wa_number . '?text=' . rawurlencode($wa_text);

// Warna stempel
$stamp_color = $is_lunas ? '#198754' : '#dc3545';
// Gambar stempel/tanda tangan bengkel (diunggah via Pengaturan)
$stempel_img = setting('stempel');
$stempel_ok  = $stempel_img && is_file(__DIR__ . '/../' . $stempel_img);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Faktur <?= esc($t['no_nota']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#e9ecef; font-family: Arial, Helvetica, sans-serif; color:#111; }
  .faktur { width: 190mm; max-width: 100%; margin: 12px auto; background:#fff; padding: 8mm 9mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); position: relative; }
  .faktur .kop { border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 8px; }
  .faktur h1.brand { font-size: 19px; font-weight: 800; letter-spacing:1px; margin:0; text-transform:uppercase; }
  .faktur .kop small { font-size: 11px; }
  .meta-table td { padding: 1px 6px 1px 0; font-size: 11.5px; vertical-align: top; }
  .faktur-title { font-size: 16px; font-weight:700; letter-spacing:3px; text-transform:uppercase; }
  table.items { width:100%; border-collapse: collapse; font-size: 11.5px; margin-top: 5px; }
  table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; }
  table.items thead th { background:#f0f0f0; text-align:center; font-weight:700; }
  table.items td.num { text-align:right; white-space:nowrap; }
  table.items td.ctr { text-align:center; }
  table.items tfoot td { border:1px solid #000; font-weight:600; }
  /* ---- Stempel LUNAS / BELUM LUNAS menimpa border faktur (seperti stempel asli) ---- */
  .stamp-wrap { position: relative; }
  .stamp {
    position: absolute; left: 6mm; top: -9mm; z-index: 5;
    display:inline-block; transform: rotate(-7deg);
    border: 3px double <?= $stamp_color ?>; color: <?= $stamp_color ?>;
    font-weight: 800; padding: 4px 18px; border-radius: 8px;
    letter-spacing: 3px; font-size: 17px; text-transform: uppercase;
    background: rgba(255,255,255,.55); opacity: .88;
    box-shadow: 0 0 0 2px rgba(255,255,255,.35) inset;
  }
  .stamp-sub { font-size: 10px; letter-spacing: 1px; font-weight: 700; }
  /* Gambar stempel/tanda tangan bengkel menimpa border faktur (anti pemalsuan) */
  .stempel-img {
    width: 24mm; height: 24mm; object-fit: contain;
    position: absolute; right: 27mm; top: -8mm; z-index: 4;
    transform: rotate(-6deg); opacity: .85;
  }
  @media print {
    @page { size: A4 portrait; margin: 7mm; }
    body { background:#fff; }
    .no-print { display:none !important; }
    .faktur { width:auto; margin:0; box-shadow:none; padding:0; }
  }
</style>
</head>
<body>
<div class="faktur" id="fakturArea" data-testid="receipt">
  <!-- KOP FAKTUR (identitas bengkel — tidak diubah) -->
  <div class="kop d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
      <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:52px" data-testid="receipt-logo">
      <?php endif; ?>
      <div>
        <h1 class="brand"><?= esc(strtoupper(setting('nama_bengkel', 'BENGKEL MOTOR'))) ?></h1>
        <?php if (setting('alamat')): ?><small><?= esc(setting('alamat')) ?><?= setting('telepon') ? ' &middot; Telp ' . esc(setting('telepon')) : '' ?></small><br><?php endif; ?>
        <?php if (setting('nib')): ?><small>NIB: <?= esc(setting('nib')) ?></small><?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <div class="faktur-title">FAKTUR</div>
      <div style="font-size:11.5px">No. <strong><?= esc($t['no_nota']) ?></strong></div>
    </div>
  </div>

  <!-- IDENTITAS FAKTUR (2 kolom, hemat ruang) -->
  <div class="row g-0">
    <div class="col-6">
      <table class="meta-table">
        <tr><td width="78">Pelanggan</td><td>: <?= esc($t['customer_nama']) ?></td></tr>
        <?php if (!empty($t['customer_alamat'])): ?><tr><td>Alamat</td><td>: <?= esc($t['customer_alamat']) ?></td></tr><?php endif; ?>
        <?php if ($t['plat_nomor']): ?><tr><td>Kendaraan</td><td>: <?= esc(trim($t['merek'].' '.$t['model'].' / '.$t['plat_nomor'])) ?></td></tr><?php endif; ?>
      </table>
    </div>
    <div class="col-6">
      <table class="meta-table" style="float:right">
        <tr><td width="80">Tanggal</td><td>: <?= esc(lokal($t['created_at'])) ?> WIB</td></tr>
        <tr><td>Waktu Cetak</td><td>: <span id="waktuCetak" data-testid="waktu-cetak"></span></td></tr>
        <tr><td>Pembayaran</td><td>: <?= $metode === 'transfer' ? 'Transfer' : 'Cash' ?></td></tr>
      </table>
    </div>
  </div>

  <!-- TABEL ITEM dengan garis all-border (kolom ringkas agar muat A4 portrait) -->
  <table class="items" data-testid="receipt-items">
    <thead>
      <tr>
        <th style="width:30px">No</th>
        <th>Nama Item</th>
        <th style="width:44px">Qty</th>
        <th style="width:104px">Harga Satuan</th>
        <th style="width:92px">Diskon</th>
        <th style="width:112px">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      <?php $no = 0; foreach ($items as $it): $no++; ?>
      <tr>
        <td class="ctr"><?= $no ?></td>
        <td>
          <?php if ($it['tipe'] === 'part' && !empty($it['part_kode'])): ?>
            <span style="font-weight:700"><?= esc($it['part_kode']) ?></span> — <?= esc($it['nama']) ?>
          <?php else: ?>
            <?= esc($it['nama']) ?>
          <?php endif; ?>
          <?php if ($it['tipe'] === 'part' && (float)($it['special_price'] ?? 0) > 0): ?>
            <span class="badge bg-warning text-dark" style="font-size:9px">Special Price</span>
          <?php endif; ?>
          <?php if ((int)$it['garansi_hari'] > 0): ?>
            <div style="font-size:9.5px;color:#555">Garansi <?= (int)$it['garansi_hari'] ?> hari s.d. <?= date('d/m/Y', strtotime(lokal($t['created_at'], 'Y-m-d') . " +{$it['garansi_hari']} days")) ?></div>
          <?php endif; ?>
        </td>
        <td class="ctr"><?= (int)$it['qty'] ?></td>
        <td class="num"><?= number_format((float)$it['harga'], 0, ',', '.') ?></td>
        <td class="num"><?php
          if ((float)($it['diskon'] ?? 0) > 0) {
              if (($it['diskon_jenis'] ?? '') === 'persen') {
                  echo rtrim(rtrim(number_format((float)$it['diskon_nilai'], 2, ',', '.'), '0'), ',') . '%';
              } else {
                  echo '-' . number_format((float)$it['diskon'], 0, ',', '.');
              }
          } else echo '-';
        ?></td>
        <td class="num"><?= number_format((float)$it['subtotal'], 0, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="6" class="ctr text-muted">Tidak ada item.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5" class="num">Total Jasa</td><td class="num">Rp <?= number_format((float)$t['total_jasa'], 0, ',', '.') ?></td></tr>
      <tr><td colspan="5" class="num">Total Sparepart</td><td class="num">Rp <?= number_format((float)$t['total_part'], 0, ',', '.') ?></td></tr>
      <?php if ((float)($t['diskon'] ?? 0) > 0): ?>
      <tr><td colspan="5" class="num">Diskon Nota<?= ($t['diskon_jenis'] ?? '') === 'persen' ? ' (' . rtrim(rtrim(number_format((float)$t['diskon_nilai'], 2, ',', '.'), '0'), ',') . '%)' : '' ?></td><td class="num">- Rp <?= number_format((float)$t['diskon'], 0, ',', '.') ?></td></tr>
      <?php endif; ?>
      <tr><td colspan="5" class="num" style="font-size:13px;background:#f0f0f0">GRAND TOTAL</td><td class="num" style="font-size:13px;background:#f0f0f0">Rp <?= number_format((float)$t['grand_total'], 0, ',', '.') ?></td></tr>
    </tfoot>
  </table>

  <!-- Stempel (menimpa border bawah tabel) + tanda tangan -->
  <div class="row g-0 mt-4 stamp-wrap">
    <div class="col-7" data-testid="receipt-status">
      <span class="stamp"><?= $is_lunas ? 'LUNAS' : 'BELUM LUNAS' ?><?php if (!$is_lunas && $jatuh_tempo_fmt): ?><div class="stamp-sub">Jatuh Tempo: <?= esc($jatuh_tempo_fmt) ?></div><?php endif; ?></span>
    </div>
    <?php if ($stempel_ok): ?>
    <img src="<?= esc($stempel_img) ?>" alt="Stempel <?= esc(setting('nama_bengkel', 'Bengkel')) ?>" class="stempel-img" data-testid="receipt-stempel">
    <?php endif; ?>
    <div class="col-5 text-center" style="font-size:11.5px">
      Hormat kami,<br><br><br>
      <div style="border-top:1px solid #000;display:inline-block;padding-top:3px;min-width:150px">( .......................... )</div>
    </div>
  </div>
  <p class="mt-2 mb-0" style="font-size:10.5px;color:#555">Terima kasih atas kepercayaan Anda. Simpan faktur ini sebagai bukti yang sah.</p>
</div>

<!-- Panel kontrol (tidak dicetak) -->
<div class="faktur no-print" style="padding:14px">
  <form method="post" action="index.php?page=receipt&id=<?= (int)$id ?>" class="border rounded p-2 bg-light mb-3" data-testid="receipt-status-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_status">
    <div class="fw-semibold mb-1"><i class="bi bi-cash-coin me-1"></i>Status Pembayaran (Faktur)</div>
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <div class="btn-group btn-group-sm" role="group">
          <input type="radio" class="btn-check" name="status_bayar" id="stLunas" value="lunas" <?= $is_lunas ? 'checked' : '' ?> data-testid="receipt-status-lunas">
          <label class="btn btn-outline-success" for="stLunas">LUNAS</label>
          <input type="radio" class="btn-check" name="status_bayar" id="stBelum" value="belum" <?= !$is_lunas ? 'checked' : '' ?> data-testid="receipt-status-belum">
          <label class="btn btn-outline-danger" for="stBelum">BELUM LUNAS</label>
        </div>
      </div>
      <div class="col-auto" id="jtWrap" style="<?= $is_lunas ? 'display:none' : '' ?>">
        <label class="form-label mb-0" style="font-size:12px">Jatuh Tempo (opsional)</label>
        <input type="date" name="jatuh_tempo" value="<?= esc($jatuh_tempo) ?>" class="form-control form-control-sm" data-testid="receipt-jatuh-tempo-input">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm" data-testid="receipt-status-save"><i class="bi bi-check2 me-1"></i>Simpan Status</button>
      </div>
    </div>
  </form>
  <div class="d-flex flex-wrap gap-2">
    <button onclick="window.print()" class="btn btn-primary btn-sm" data-testid="print-btn"><i class="bi bi-printer"></i> Cetak A4 Portrait</button>
    <button id="downloadJpgBtn" class="btn btn-warning btn-sm" data-testid="download-jpg-btn"><i class="bi bi-file-earmark-image"></i> Unduh Faktur JPG</button>
    <?php if ($wa_number !== ''): ?>
    <button id="waShareBtn" class="btn btn-success btn-sm" data-testid="wa-btn"><i class="bi bi-whatsapp"></i> Kirim WhatsApp (rincian + gambar faktur)</button>
    <?php else: ?>
    <button class="btn btn-success btn-sm" disabled title="Nomor HP pelanggan belum diisi." data-testid="wa-btn-disabled"><i class="bi bi-whatsapp"></i> Kirim WhatsApp</button>
    <?php endif; ?>
    <a href="index.php?page=pos" class="btn btn-outline-secondary btn-sm" data-testid="new-trx-btn">Transaksi Baru</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
  </div>
  <p class="text-muted mt-2 mb-0" style="font-size:11px">
    <i class="bi bi-info-circle me-1"></i>Tombol WhatsApp mengirim <strong>rincian + gambar faktur sekaligus</strong> (di HP, gambar otomatis terlampir lewat menu bagikan). Pada perangkat yang tidak mendukung, gambar faktur otomatis diunduh lalu chat WhatsApp terbuka — lampirkan file tersebut pada chat.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
function tickWaktu() {
  const el = document.getElementById('waktuCetak');
  if (el) el.textContent = new Date().toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
tickWaktu(); setInterval(tickWaktu, 1000);

(function () {
  const wrap = document.getElementById('jtWrap');
  document.querySelectorAll('input[name="status_bayar"]').forEach(r => {
    r.addEventListener('change', () => { if (wrap) wrap.style.display = (document.getElementById('stBelum').checked) ? '' : 'none'; });
  });
})();

// Unduh faktur sebagai gambar JPG (untuk dilampirkan manual ke WhatsApp).
document.getElementById('downloadJpgBtn').addEventListener('click', async function () {
  const btn = this; const original = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Menyiapkan...';
  try {
    const area = document.getElementById('fakturArea');
    const canvas = await html2canvas(area, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
    const url = canvas.toDataURL('image/jpeg', 0.95);
    const a = document.createElement('a');
    a.href = url; a.download = 'Faktur_<?= preg_replace('/[^A-Za-z0-9_\-]/','', $t['no_nota']) ?>.jpg';
    document.body.appendChild(a); a.click(); a.remove();
  } catch (e) {
    alert('Gagal membuat gambar faktur: ' + e.message);
  } finally {
    btn.disabled = false; btn.innerHTML = original;
  }
});

// Kirim WhatsApp: gambar faktur + rincian teks terkirim SEKALIGUS via Web Share API
// (di HP: menu bagikan terbuka -> pilih WhatsApp -> gambar otomatis terlampir).
// Fallback di desktop/browser lama: gambar diunduh otomatis + chat WA berisi rincian dibuka.
const WA_TEXT = <?= json_encode($wa_text) ?>;
const WA_URL = <?= json_encode($wa_url) ?>;
async function renderFakturBlob() {
  const area = document.getElementById('fakturArea');
  const canvas = await html2canvas(area, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
  return await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.95));
}
document.getElementById('waShareBtn')?.addEventListener('click', async function () {
  const btn = this; const original = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Menyiapkan gambar...';
  try {
    const blob = await renderFakturBlob();
    const file = new File([blob], 'Faktur_<?= preg_replace('/[^A-Za-z0-9_\-]/','', $t['no_nota']) ?>.jpg', { type: 'image/jpeg' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], text: WA_TEXT, title: 'Faktur <?= esc($t['no_nota']) ?>' });
    } else {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = file.name;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
      window.open(WA_URL, '_blank', 'noopener');
      alert('Gambar faktur sudah diunduh. Lampirkan file tersebut pada chat WhatsApp yang terbuka.');
    }
  } catch (e) {
    if (e.name !== 'AbortError') alert('Gagal menyiapkan gambar faktur: ' + e.message);
  } finally {
    btn.disabled = false; btn.innerHTML = original;
  }
});
</script>
</body>
</html>
