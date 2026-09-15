<?php
// Statistik ringkas dashboard
$db = db();
// "Hari ini" menurut WIB dikonversi ke rentang UTC -> memakai index created_at
// (menggantikan DATE(created_at + INTERVAL 7 HOUR) yang men-disable index).
[$today_su, $today_eu] = wib_today_utc();
$tst = $db->prepare("SELECT COALESCE(SUM(grand_total),0) AS pend, COUNT(*) AS cnt
                     FROM transactions WHERE created_at >= ? AND created_at < ?");
$tst->execute([$today_su, $today_eu]);
$row_today = $tst->fetch(PDO::FETCH_ASSOC);
$pendapatan_hari_ini = (float)($row_today['pend'] ?? 0);
$servis_hari_ini     = (int)($row_today['cnt'] ?? 0);
$servis_total        = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='selesai'")->fetchColumn();
// Stok menipis: pakai kolom terhitung ber-index (is_low_stock) bila tersedia
// agar cepat di data besar; fallback aman ke perbandingan kolom biasa.
try { $stok_menipis = (int)$db->query("SELECT COUNT(*) FROM parts WHERE is_low_stock=1")->fetchColumn(); }
catch (\Throwable $e) { $stok_menipis = (int)$db->query("SELECT COUNT(*) FROM parts WHERE stok <= stok_min")->fetchColumn(); }
$total_pelanggan     = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$garansi_aktif       = (int)$db->query("SELECT COUNT(*) FROM warranty_claims WHERE status IN ('pending','diproses')")->fetchColumn();

$recent = $db->query("SELECT t.*, c.nama AS customer_nama, v.plat_nomor
    FROM transactions t
    JOIN customers c ON c.id = t.customer_id
    LEFT JOIN vehicles v ON v.id = t.vehicle_id
    ORDER BY t.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

try { $low_parts = $db->query("SELECT kode, nama, stok, stok_min FROM parts WHERE is_low_stock=1 ORDER BY stok ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC); }
catch (\Throwable $e) { $low_parts = $db->query("SELECT kode, nama, stok, stok_min FROM parts WHERE stok <= stok_min ORDER BY stok ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC); }

// Pengingat jatuh tempo: hutang/piutang belum lunas yang lewat / mendekati (<= 7 hari).
$today = date('Y-m-d');
$soon  = date('Y-m-d', strtotime('+7 days'));
$due_debts = $db->prepare("SELECT * FROM debts WHERE status='belum_lunas' AND jatuh_tempo IS NOT NULL AND jatuh_tempo <> '' AND jatuh_tempo <= ? ORDER BY jatuh_tempo ASC LIMIT 20");
$due_debts->execute([$soon]);
$due_debts = $due_debts->fetchAll(PDO::FETCH_ASSOC);

// Ringkasan kas & laba bulan berjalan (khusus admin)
$is_admin = ((current_user()['role'] ?? '') === 'admin');
$saldo_kas = 0.0; $laba_bln = 0.0;
if ($is_admin) {
    $saldo_kas = (float)$db->query("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE -jumlah END),0) FROM cash_entries")->fetchColumn();
    $bln_dari = date('Y-m-01'); $bln_sampai = date('Y-m-d');
    [$m_su, $m_eu] = wib_range_utc($bln_dari, $bln_sampai);
    $pst = $db->prepare("SELECT COALESCE(SUM(grand_total),0) FROM transactions WHERE created_at >= ? AND created_at < ?");
    $pst->execute([$m_su, $m_eu]); $pend_bln = (float)$pst->fetchColumn();
    $hst = $db->prepare("SELECT COALESCE(SUM(ti.qty*COALESCE(pt.harga_beli,0)),0) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id LEFT JOIN parts pt ON pt.id=ti.part_id WHERE ti.tipe='part' AND t.created_at >= ? AND t.created_at < ?");
    $hst->execute([$m_su, $m_eu]); $hpp_bln = (float)$hst->fetchColumn();
    $cst = $db->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE 0 END),0) m, COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah ELSE 0 END),0) k FROM cash_entries WHERE tgl BETWEEN ? AND ?");
    $cst->execute([$bln_dari, $bln_sampai]); $ckb = $cst->fetch(PDO::FETCH_ASSOC);
    $laba_bln = ($pend_bln - $hpp_bln) + (float)$ckb['m'] - (float)$ckb['k'];
}
?>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
  <div class="col">
    <div class="card stat-card"><div class="card-body" data-testid="stat-pendapatan">
      <div class="text-muted small">Pendapatan Hari Ini</div>
      <div class="fs-4 fw-bold text-success"><?= rupiah($pendapatan_hari_ini) ?></div>
    </div></div>
  </div>
  <div class="col">
    <div class="card stat-card"><div class="card-body" data-testid="stat-servis">
      <div class="text-muted small">Servis Selesai</div>
      <div class="fs-4 fw-bold text-primary"><?= $servis_total ?> <span class="fs-6 text-muted fw-normal">(+<?= $servis_hari_ini ?> hari ini)</span></div>
    </div></div>
  </div>
  <div class="col">
    <div class="card stat-card"><div class="card-body" data-testid="stat-stok-menipis">
      <div class="text-muted small">Stok Menipis</div>
      <div class="fs-4 fw-bold <?= $stok_menipis ? 'text-danger' : 'text-success' ?>"><?= $stok_menipis ?> item</div>
    </div></div>
  </div>
  <div class="col">
    <div class="card stat-card"><div class="card-body" data-testid="stat-pelanggan">
      <div class="text-muted small">Total Pelanggan</div>
      <div class="fs-4 fw-bold text-info"><?= $total_pelanggan ?></div>
    </div></div>
  </div>
  <div class="col">
    <div class="card stat-card"><div class="card-body" data-testid="stat-garansi">
      <div class="text-muted small">Klaim Garansi Aktif</div>
      <div class="fs-4 fw-bold <?= $garansi_aktif ? 'text-warning' : 'text-success' ?>"><?= $garansi_aktif ?> klaim</div>
      <a href="index.php?page=warranty" class="small">Kelola garansi &raquo;</a>
    </div></div>
  </div>
</div>

<?php if ($is_admin): ?>
<div class="row g-3 mb-4" data-testid="admin-kas-summary">
  <div class="col-6 col-lg-3">
    <div class="card stat-card" data-testid="stat-saldo-kas"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-wallet2 me-1"></i>Saldo Kas</div>
      <div class="fs-4 fw-bold <?= $saldo_kas>=0?'text-success':'text-danger' ?>"><?= rupiah($saldo_kas) ?></div>
      <a href="index.php?page=finance" class="small">Buku kas &raquo;</a>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card" data-testid="stat-laba-bln"><div class="card-body">
      <div class="text-muted small"><i class="bi bi-graph-up-arrow me-1"></i><?= $laba_bln>=0?'Laba':'Rugi' ?> Bulan Ini</div>
      <div class="fs-4 fw-bold <?= $laba_bln>=0?'text-success':'text-danger' ?>"><?= rupiah($laba_bln) ?></div>
      <a href="index.php?page=finance" class="small">Laba/rugi &raquo;</a>
    </div></div>
  </div>
</div>
<?php endif; ?>

<?php if ($due_debts): ?>
<div class="card table-card mb-4 border-warning" data-testid="due-alert"><div class="card-body">
  <h2 class="h6 mb-3 text-warning"><i class="bi bi-alarm me-1"></i>Pengingat Jatuh Tempo Hutang / Piutang</h2>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0" data-testid="due-alert-table">
    <thead><tr><th>Jenis</th><th>Pihak</th><th class="text-end">Sisa</th><th>Jatuh Tempo</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($due_debts as $d): $sisa = (float)$d['jumlah'] - (float)$d['dibayar'];
      $diff = (int)floor((strtotime($d['jatuh_tempo']) - strtotime($today)) / 86400);
      $waNum = preg_replace('/\D+/', '', (string)($d['telepon'] ?? ''));
      if ($waNum !== '') { if (strncmp($waNum,'0',1)===0) $waNum='62'.substr($waNum,1); elseif (strncmp($waNum,'62',2)!==0) $waNum='62'.$waNum; }
      $waTxt = $d['jenis']==='piutang'
        ? 'Yth '.$d['pihak'].', kami dari '.setting('nama_bengkel','Bengkel Motor').' mengingatkan tagihan sebesar '.rupiah($sisa).' yang jatuh tempo '.date('d/m/Y',strtotime($d['jatuh_tempo'])).'. Mohon dapat diselesaikan. Terima kasih.'
        : 'Pengingat kewajiban pembayaran kepada '.$d['pihak'].' sebesar '.rupiah($sisa).' jatuh tempo '.date('d/m/Y',strtotime($d['jatuh_tempo'])).'.';
      $waUrl = 'https://wa.me/'.$waNum.'?text='.rawurlencode($waTxt); ?>
      <tr>
        <td><span class="badge bg-<?= $d['jenis']==='piutang'?'success':'danger' ?>"><?= strtoupper($d['jenis']) ?></span></td>
        <td><?= esc($d['pihak']) ?></td>
        <td class="text-end"><?= rupiah($sisa) ?></td>
        <td class="small"><?= esc(date('d/m/Y', strtotime($d['jatuh_tempo']))) ?></td>
        <td><?php if ($diff < 0): ?><span class="badge bg-danger">Lewat <?= abs($diff) ?> hari</span>
            <?php elseif ($diff === 0): ?><span class="badge bg-warning text-dark">Jatuh tempo hari ini</span>
            <?php else: ?><span class="badge bg-warning text-dark"><?= $diff ?> hari lagi</span><?php endif; ?></td>
        <td class="text-end"><?php if ($waNum !== ''): ?><a href="<?= esc($waUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0 px-1" title="Kirim pengingat WhatsApp" data-testid="due-wa-btn-<?= $d['id'] ?>"><i class="bi bi-whatsapp"></i> Ingatkan</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <a href="index.php?page=debts" class="small mt-2 d-inline-block" data-testid="due-alert-link">Kelola hutang piutang &raquo;</a>
</div></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3">Transaksi Terbaru</h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="recent-transactions">
        <thead><tr><th>Nota</th><th>Pelanggan</th><th>Plat</th><th class="text-end">Total</th><th>Tanggal</th></tr></thead>
        <tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="text-center text-muted">Belum ada transaksi.</td></tr><?php endif; ?>
        <?php foreach ($recent as $t): ?>
          <tr>
            <td><a href="index.php?page=receipt&id=<?= $t['id'] ?>"><?= esc($t['no_nota']) ?></a></td>
            <td><?= esc($t['customer_nama']) ?></td>
            <td><?= esc($t['plat_nomor'] ?? '-') ?></td>
            <td class="text-end"><?= rupiah($t['grand_total']) ?></td>
            <td><?= esc(lokal($t['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Peringatan Stok Menipis</h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="low-stock-list">
        <thead><tr><th>Kode</th><th>Barang</th><th class="text-end">Stok</th><th class="text-end">Min</th></tr></thead>
        <tbody>
        <?php if (!$low_parts): ?><tr><td colspan="4" class="text-center text-muted">Semua stok aman.</td></tr><?php endif; ?>
        <?php foreach ($low_parts as $p): ?>
          <tr>
            <td><?= esc($p['kode']) ?></td>
            <td><?= esc($p['nama']) ?></td>
            <td class="text-end"><span class="badge bg-danger"><?= $p['stok'] ?></span></td>
            <td class="text-end"><?= $p['stok_min'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>
