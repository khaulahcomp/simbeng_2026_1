<?php
$db = db();
$is_admin = ((current_user()['role'] ?? '') === 'admin');

// ---- Hapus transaksi: stok sparepart dikembalikan, jejak pergerakan dihapus ----
// Hanya role admin yang boleh menghapus transaksi (kasir/mekanik hanya Cetak Nota).
if (($_POST['action'] ?? '') === 'delete') {
    if (!$is_admin) {
        set_flash('danger', 'Akses ditolak. Hapus transaksi hanya untuk role admin.');
        header('Location: index.php?page=transactions'); exit;
    }
    $id = (int)$_POST['id'];
    // Tolak hapus bila ada klaim garansi yang terkait transaksi ini
    $klaim = $db->prepare("SELECT COUNT(*) FROM warranty_claims WHERE transaction_id=?");
    $klaim->execute([$id]);
    if ((int)$klaim->fetchColumn() > 0) {
        set_flash('danger', 'Transaksi tidak dapat dihapus karena memiliki klaim garansi terkait.');
        header('Location: index.php?page=transactions'); exit;
    }
    $db->beginTransaction();
    try {
        $items = $db->prepare("SELECT part_id, qty FROM transaction_items WHERE transaction_id=? AND tipe='part'");
        $items->execute([$id]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$it['qty'], $it['part_id']]);
            // Multi Rak: stok yang dikembalikan masuk ke Rak Utama (jaga invarian
            // SUM(part_racks.stok) = parts.stok tetap konsisten).
            rack_receive_masuk($db, $it['part_id'], RAK_UTAMA_KEY, $it['qty']);
        }
        $db->prepare("DELETE FROM stock_movements WHERE ref_type='penjualan' AND ref_id=?")->execute([$id]);
        $db->prepare("DELETE FROM transaction_items WHERE transaction_id=?")->execute([$id]);
        require_once __DIR__ . '/../includes/invoice_debt.php';
        delete_invoice_debt($db, $id); // piutang otomatis dari faktur ini ikut dihapus
        $db->prepare("DELETE FROM transactions WHERE id=?")->execute([$id]);
        $db->commit();
        set_flash('success', 'Transaksi dihapus dan stok sparepart dikembalikan.');
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Gagal menghapus transaksi: ' . $e->getMessage());
    }
    header('Location: index.php?page=transactions'); exit;
}

$q = trim($_GET['q'] ?? '');
$dari = $_GET['dari'] ?? '';
$sampai = $_GET['sampai'] ?? '';

$from_sql = " FROM transactions t
        JOIN customers c ON c.id=t.customer_id LEFT JOIN vehicles v ON v.id=t.vehicle_id";
$where = []; $params = [];
if ($q !== '') { $where[] = "(t.no_nota LIKE ? OR c.nama LIKE ? OR v.plat_nomor LIKE ?)"; $params = array_merge($params, ["%$q%","%$q%","%$q%"]); }
if ($dari !== '' && _valid_date($dari)) { [$d_su, ] = wib_range_utc($dari, $dari); $where[] = "t.created_at >= ?"; $params[] = $d_su; }
if ($sampai !== '' && _valid_date($sampai)) { [, $d_eu] = wib_range_utc($sampai, $sampai); $where[] = "t.created_at < ?"; $params[] = $d_eu; }
$where_sql = $where ? " WHERE " . implode(' AND ', $where) : '';

// ---- Pagination: default 25 per halaman, maksimal 200 per halaman ----
$allowedPer = [25, 50, 100, 200];
$per = (int)($_GET['per_page'] ?? 25);
if (!in_array($per, $allowedPer, true)) $per = 25;

// Hitung total transaksi & total nominal keseluruhan sesuai filter aktif
// (bukan hanya yang tampil di halaman ini) agar rekap tetap akurat meski dipaging.
$cstmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(t.grand_total),0) AS total" . $from_sql . $where_sql);
$cstmt->execute($params);
$agg = $cstmt->fetch(PDO::FETCH_ASSOC);
$total_count = (int)$agg['cnt'];
$total_all = (float)$agg['total'];
$totalPages = max(1, (int)ceil($total_count / $per));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $per;

$sql = "SELECT t.*, c.nama AS customer_nama, v.plat_nomor" . $from_sql . $where_sql . " ORDER BY t.id DESC LIMIT $per OFFSET $offset";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = array_sum(array_column($rows, 'grand_total'));
$qs = http_build_query(['page' => 'transactions', 'q' => $q, 'dari' => $dari, 'sampai' => $sampai, 'per_page' => $per]);
?>
<div class="card table-card"><div class="card-body">
  <form class="row g-2 mb-3" method="get">
    <input type="hidden" name="page" value="transactions">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="Cari nota / pelanggan / plat..." value="<?= esc($q) ?>" data-testid="trx-search"></div>
    <div class="col-md-3"><input name="dari" type="date" class="form-control form-control-sm" value="<?= esc($dari) ?>" data-testid="trx-dari"></div>
    <div class="col-md-2"><input name="sampai" type="date" class="form-control form-control-sm" value="<?= esc($sampai) ?>" data-testid="trx-sampai"></div>
    <div class="col-md-2">
      <select name="per_page" class="form-select form-select-sm" data-testid="trx-perpage">
        <?php foreach ($allowedPer as $pp): ?><option value="<?= $pp ?>" <?= $per===$pp?'selected':'' ?>><?= $pp ?> / halaman</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-primary w-100" data-testid="trx-filter-btn"><i class="bi bi-funnel"></i></button></div>
  </form>
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <span class="small text-muted" data-testid="trx-count">Menampilkan <?= count($rows) ?> dari <?= $total_count ?> transaksi<?= $q!==''?' (filter: "'.esc($q).'")':'' ?> &mdash; Total keseluruhan: <strong><?= rupiah($total_all) ?></strong></span>
  </div>
  <div class="table-responsive">
  <table class="table table-sm align-middle" data-testid="transactions-table">
    <thead><tr><th>Nota</th><th>Pelanggan</th><th>Plat</th><th class="text-end">Jasa</th><th class="text-end">Sparepart</th><th class="text-end">Total</th><th>Tanggal</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">Tidak ada transaksi ditemukan.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= esc($r['no_nota']) ?></td>
        <td><?= esc($r['customer_nama']) ?></td>
        <td><?= esc($r['plat_nomor'] ?? '-') ?></td>
        <td class="text-end"><?= rupiah($r['total_jasa']) ?></td>
        <td class="text-end"><?= rupiah($r['total_part']) ?></td>
        <td class="text-end fw-semibold"><?= rupiah($r['grand_total']) ?></td>
        <td class="small"><?= esc(lokal($r['created_at'])) ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="index.php?page=receipt&id=<?= $r['id'] ?>" title="Cetak Nota" data-testid="trx-receipt-<?= $r['id'] ?>"><i class="bi bi-printer"></i></a>
          <?php if ($is_admin): ?>
          <a class="btn btn-sm btn-outline-warning" href="index.php?page=pos&edit=<?= $r['id'] ?>" title="Edit Transaksi" data-testid="trx-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Hapus transaksi ini? Stok sparepart yang terpakai akan dikembalikan.')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" title="Hapus Transaksi" data-testid="trx-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr class="fw-bold"><td colspan="5">Subtotal halaman ini (<?= count($rows) ?> transaksi)</td><td class="text-end" data-testid="trx-total-sum"><?= rupiah($total) ?></td><td colspan="2"></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <nav class="d-flex justify-content-center align-items-center gap-2 mt-2" data-testid="trx-pagination">
    <a class="btn btn-sm btn-outline-secondary <?= $pageNum<=1?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= max(1,$pageNum-1) ?>" data-testid="trx-prev">&laquo; Sebelumnya</a>
    <span class="small text-muted">Halaman <?= $pageNum ?> / <?= $totalPages ?></span>
    <a class="btn btn-sm btn-outline-secondary <?= $pageNum>=$totalPages?'disabled':'' ?>" href="index.php?<?= $qs ?>&p=<?= min($totalPages,$pageNum+1) ?>" data-testid="trx-next">Berikutnya &raquo;</a>
  </nav>
  <?php endif; ?>
</div></div>
<script>
// Ganti halaman ke ukuran per_page baru: reset ke halaman 1 agar tidak melompat
// ke halaman yang mungkin tidak lagi ada.
document.querySelector('[data-testid="trx-perpage"]').addEventListener('change', function () {
  const form = this.closest('form');
  const p = document.createElement('input');
  p.type = 'hidden'; p.name = 'p'; p.value = '1';
  form.appendChild(p);
  form.submit();
});
</script>
