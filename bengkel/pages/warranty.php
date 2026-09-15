<?php
// ============================================================
// Modul Klaim Garansi
// - Cari nota lama (no nota / plat / nama pelanggan)
// - Ajukan klaim atas item pada nota tersebut
// - Ubah status klaim; jika disetujui + ganti part baru,
//   stok part pengganti otomatis berkurang (ref_type='garansi')
// ============================================================
$db = db();
$action = $_POST['action'] ?? '';

// ---- Pengajuan klaim baru ----
if ($action === 'create') {
    $item_id = (int)$_POST['transaction_item_id'];
    $alasan = trim($_POST['alasan'] ?? '');
    $stmt = $db->prepare("SELECT ti.*, t.customer_id, t.created_at AS tgl_beli, t.id AS trx_id
        FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE ti.id=?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item && $alasan !== '') {
        $garansi = (int)$item['garansi_hari'];
        // Tanggal beli & akhir garansi dihitung dari tanggal WIB (bukan UTC mentah)
        $tgl_beli_wib = lokal($item['tgl_beli'], 'Y-m-d');
        $tgl_berakhir = date('Y-m-d', strtotime($tgl_beli_wib . " +$garansi days"));
        // Validasi server-side: item tanpa garansi / garansi kedaluwarsa tidak bisa diklaim
        if ($garansi <= 0) {
            set_flash('danger', 'Item ini tidak memiliki masa garansi.');
        } elseif ($tgl_berakhir < date('Y-m-d')) {
            set_flash('danger', "Masa garansi item ini sudah berakhir pada $tgl_berakhir.");
        } else {
            // Cegah klaim ganda untuk item transaksi yang sama
            $dup = $db->prepare("SELECT COUNT(*) FROM warranty_claims WHERE transaction_item_id=? AND status != 'ditolak'");
            $dup->execute([$item_id]);
            if ((int)$dup->fetchColumn() > 0) {
                set_flash('danger', 'Item ini sudah memiliki klaim garansi aktif.');
            } else {
                $kode = next_kode('GRS', 'warranty_claims', 'kode');
                $db->prepare("INSERT INTO warranty_claims (kode, transaction_id, transaction_item_id, customer_id, item_nama, tgl_beli, tgl_berakhir, status, alasan)
                    VALUES (?,?,?,?,?,?,?, 'pending', ?)")
                   ->execute([$kode, $item['trx_id'], $item_id, $item['customer_id'], $item['nama'],
                              $tgl_beli_wib, $tgl_berakhir,
                              $alasan]);
                set_flash('success', "Klaim garansi $kode berhasil diajukan.");
            }
        }
    } else {
        set_flash('danger', 'Lengkapi item yang diklaim dan alasan kerusakan.');
    }
    header('Location: index.php?page=warranty'); exit;
}

// ---- Update status klaim ----
if ($action === 'update_status') {
    $claim_id = (int)$_POST['claim_id'];
    $status = $_POST['status'] ?? 'pending';
    $catatan = trim($_POST['catatan_teknisi'] ?? '');
    $replacement = (int)($_POST['replacement_part_id'] ?? 0) ?: null;
    if (!in_array($status, ['pending','diproses','disetujui','ditolak'], true)) $status = 'pending';

    $db->beginTransaction();
    try {
        // Jika disetujui & ada sparepart pengganti -> catat barang keluar otomatis
        if ($status === 'disetujui' && $replacement) {
            $cekStok = $db->prepare("SELECT stok FROM parts WHERE id=?");
            $cekStok->execute([$replacement]);
            $stok = (int)$cekStok->fetchColumn();
            if ($stok < 1) throw new Exception('Stok sparepart pengganti habis.');
            $db->prepare("UPDATE parts SET stok = stok - 1 WHERE id=?")->execute([$replacement]);
            $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, ref_type, ref_id, keterangan) VALUES (?,?,?,?,?,?)")
               ->execute([$replacement, 'keluar', 1, 'garansi', $claim_id, 'Penggantian unit klaim garansi']);
        } elseif ($status !== 'disetujui') {
            $replacement = null;
        }
        $db->prepare("UPDATE warranty_claims SET status=?, catatan_teknisi=?, replacement_part_id=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
           ->execute([$status, $catatan, $replacement, $claim_id]);        $db->commit();
        set_flash('success', 'Status klaim diperbarui.');
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Gagal: ' . $e->getMessage());
    }
    header('Location: index.php?page=warranty&claim=' . $claim_id); exit;
}

$badge = ['pending'=>'warning', 'diproses'=>'info', 'disetujui'=>'success', 'ditolak'=>'danger'];
$label = ['pending'=>'Pending / Diajukan', 'diproses'=>'Sedang Diproses', 'disetujui'=>'Disetujui / Diganti Baru', 'ditolak'=>'Ditolak'];

// ---- Detail klaim ----
$claim_id = (int)($_GET['claim'] ?? 0);
if ($claim_id) {
    $c = $db->prepare("SELECT w.*, t.no_nota, cu.nama AS customer_nama, cu.telepon, p.nama AS replacement_nama
        FROM warranty_claims w
        JOIN transactions t ON t.id=w.transaction_id
        JOIN customers cu ON cu.id=w.customer_id
        LEFT JOIN parts p ON p.id=w.replacement_part_id
        WHERE w.id=?");
    $c->execute([$claim_id]);
    $c = $c->fetch(PDO::FETCH_ASSOC);
    if (!$c) { header('Location: index.php?page=warranty'); exit; }
    $parts = $db->query("SELECT id, kode, nama, stok FROM parts WHERE stok > 0 ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
    $sisa_hari = (int)((strtotime($c['tgl_berakhir']) - strtotime(date('Y-m-d'))) / 86400);
    ?>
    <a href="index.php?page=warranty" class="btn btn-sm btn-outline-secondary mb-3" data-testid="back-to-warranty"><i class="bi bi-arrow-left"></i> Kembali</a>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card table-card"><div class="card-body" data-testid="claim-detail">
          <h2 class="h6">Detail Klaim <?= esc($c['kode']) ?></h2>
          <table class="table table-sm">
            <tr><td style="width:40%">Kode Klaim</td><td class="fw-semibold"><?= esc($c['kode']) ?></td></tr>
            <tr><td>Nota Terkait</td><td><a href="index.php?page=receipt&id=<?= $c['transaction_id'] ?>"><?= esc($c['no_nota']) ?></a></td></tr>
            <tr><td>Pelanggan</td><td><?= esc($c['customer_nama']) ?> (<?= esc($c['telepon']) ?>)</td></tr>
            <tr><td>Item Digeransikan</td><td><?= esc($c['item_nama']) ?></td></tr>
            <tr><td>Tanggal Beli/Servis</td><td><?= esc($c['tgl_beli']) ?></td></tr>
            <tr><td>Garansi Berakhir</td><td><?= esc($c['tgl_berakhir']) ?>
              <span class="badge bg-<?= $sisa_hari >= 0 ? 'success' : 'danger' ?>"><?= $sisa_hari >= 0 ? "Sisa $sisa_hari hari" : 'Garansi habis' ?></span></td></tr>
            <tr><td>Status</td><td><span class="badge bg-<?= $badge[$c['status']] ?>" data-testid="claim-status-badge"><?= $label[$c['status']] ?></span></td></tr>
            <tr><td>Alasan Klaim</td><td><?= esc($c['alasan']) ?></td></tr>
            <tr><td>Catatan Teknisi</td><td><?= esc($c['catatan_teknisi'] ?: '-') ?></td></tr>
            <?php if ($c['replacement_nama']): ?><tr><td>Part Pengganti</td><td><?= esc($c['replacement_nama']) ?></td></tr><?php endif; ?>
          </table>
          <a href="index.php?page=warranty_print&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" data-testid="claim-print-btn"><i class="bi bi-printer me-1"></i>Cetak Bukti Klaim</a>
        </div></div>
      </div>
      <div class="col-lg-6">
        <div class="card table-card"><div class="card-body">
          <h2 class="h6">Ubah Status Klaim</h2>
          <form method="post" data-testid="claim-update-form">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
            <div class="mb-2"><label class="form-label small">Status</label>
              <select name="status" class="form-select form-select-sm" data-testid="claim-status-select">
                <?php foreach ($label as $k => $v): ?><option value="<?= $k ?>" <?= $c['status']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
              </select></div>
            <div class="mb-2"><label class="form-label small">Sparepart Pengganti (opsional, stok otomatis berkurang jika disetujui)</label>
              <select name="replacement_part_id" class="form-select form-select-sm" data-testid="claim-replacement-select">
                <option value="">- Tanpa penggantian part -</option>
                <?php foreach ($parts as $p): ?><option value="<?= $p['id'] ?>" <?= $c['replacement_part_id']==$p['id']?'selected':'' ?>><?= esc($p['kode']) ?> - <?= esc($p['nama']) ?> (stok <?= $p['stok'] ?>)</option><?php endforeach; ?>
              </select></div>
            <div class="mb-3"><label class="form-label small">Catatan Teknisi / Keputusan Akhir</label>
              <textarea name="catatan_teknisi" class="form-control form-control-sm" rows="3" data-testid="claim-catatan"><?= esc($c['catatan_teknisi']) ?></textarea></div>
            <button class="btn btn-sm btn-primary w-100" data-testid="claim-update-submit">Simpan Status</button>
          </form>
        </div></div>
      </div>
    </div>
    <?php
    return;
}

// ---- Daftar klaim + pencarian nota untuk pengajuan baru ----
$status_filter = $_GET['status'] ?? '';
$sql = "SELECT w.*, t.no_nota, cu.nama AS customer_nama FROM warranty_claims w
        JOIN transactions t ON t.id=w.transaction_id JOIN customers cu ON cu.id=w.customer_id";
$params = [];
if (in_array($status_filter, ['pending','diproses','disetujui','ditolak'], true)) {
    $sql .= " WHERE w.status=?"; $params[] = $status_filter;
}
$sql .= " ORDER BY w.id DESC LIMIT 100";
$stmt = $db->prepare($sql); $stmt->execute($params);
$claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6">Pengajuan Klaim Baru</h2>
      <p class="small text-muted">Cari nota lama berdasarkan <strong>nomor nota, plat kendaraan, atau nama pelanggan</strong>, lalu pilih item yang akan diklaim.</p>
      <div class="input-group input-group-sm mb-2">
        <input id="searchNota" class="form-control" placeholder="TRX-... / B 1234 / nama pelanggan" data-testid="warranty-search-input">
        <button class="btn btn-outline-primary" onclick="cariNota()" data-testid="warranty-search-btn"><i class="bi bi-search"></i></button>
      </div>
      <div id="hasilNota" data-testid="warranty-search-result"></div>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Daftar Klaim Garansi</h2>
        <form method="get" class="d-flex gap-1">
          <input type="hidden" name="page" value="warranty">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" data-testid="warranty-filter-status">
            <option value="">Semua status</option>
            <?php foreach ($label as $k => $v): ?><option value="<?= $k ?>" <?= $status_filter===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="claims-table">
        <thead><tr><th>Kode</th><th>Nota</th><th>Pelanggan</th><th>Item</th><th>Berlaku s.d.</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$claims): ?><tr><td colspan="7" class="text-center text-muted">Belum ada klaim garansi.</td></tr><?php endif; ?>
        <?php foreach ($claims as $c): ?>
          <tr>
            <td><?= esc($c['kode']) ?></td>
            <td><?= esc($c['no_nota']) ?></td>
            <td><?= esc($c['customer_nama']) ?></td>
            <td><?= esc($c['item_nama']) ?></td>
            <td class="small"><?= esc($c['tgl_berakhir']) ?></td>
            <td><span class="badge bg-<?= $badge[$c['status']] ?>"><?= $label[$c['status']] ?></span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="index.php?page=warranty&claim=<?= $c['id'] ?>" data-testid="claim-open-<?= $c['id'] ?>"><i class="bi bi-pencil-square"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>

<script>
// Pencarian cepat nota untuk pengajuan klaim
async function cariNota() {
  const q = document.getElementById('searchNota').value.trim();
  if (!q) return;
  const res = await fetch('ajax/lookup.php?action=search_trx&q=' + encodeURIComponent(q));
  const data = await res.json();
  const box = document.getElementById('hasilNota');
  if (!data.length) { box.innerHTML = '<div class="alert alert-warning py-2 small">Tidak ada nota ditemukan.</div>'; return; }
  let html = '';
  data.forEach(t => {
    html += `<div class="border rounded p-2 mb-2">
      <div class="d-flex justify-content-between">
        <strong>${t.no_nota}</strong><span class="small text-muted">${t.created_at_wib}</span>
      </div>
      <div class="small">${t.customer_nama} ${t.plat_nomor ? ' / ' + t.plat_nomor : ''}</div>
      <div class="mt-1">`;
    t.items.forEach(it => {
      const exp = new Date(t.tgl_beli_wib + 'T00:00:00'); exp.setDate(exp.getDate() + it.garansi_hari);
      const masih = exp >= new Date(new Date().toDateString());
      html += `<form method="post" class="d-flex align-items-center gap-2 border-top py-1">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="transaction_item_id" value="${it.id}">
        <div class="flex-grow-1 small">${it.nama} <span class="text-muted">(${it.tipe})</span>
          ${it.garansi_hari > 0
            ? `<span class="badge bg-${masih ? 'success' : 'danger'}">garansi s.d. ${exp.toLocaleDateString('id-ID')}</span>`
            : '<span class="badge bg-secondary">tanpa garansi</span>'}
        </div>
        <input name="alasan" class="form-control form-control-sm" style="max-width:200px" placeholder="Keluhan kerusakan" required>
        <button class="btn btn-sm btn-outline-success" data-testid="claim-submit-${it.id}">Klaim</button>
      </form>`;
    });
    html += '</div></div>';
  });
  box.innerHTML = html;
}
</script>
