<?php
// ============================================================
// invoice_debt.php - Sinkronisasi FAKTUR (transactions) <-> PIUTANG (debts).
//
// Aturan:
//  - Faktur berstatus BELUM LUNAS  -> otomatis punya 1 baris piutang di menu
//    Hutang Piutang (identitas pelanggan diambil dari data faktur: nama,
//    no. HP, kendaraan/plat, no. nota, jatuh tempo, nominal grand total).
//  - Faktur diubah ke LUNAS        -> piutang terkait ditandai lunas
//    (sisa tagihan dicatat sebagai pelunasan agar riwayat tetap konsisten).
//  - Faktur diedit (nominal/pelanggan) -> piutang ikut diperbarui; cicilan
//    yang sudah masuk TIDAK hilang.
//  - Faktur dihapus                -> piutang terkait ikut dihapus.
//  - Piutang terkait faktur dilunasi dari menu Hutang Piutang -> status faktur
//    otomatis menjadi LUNAS.
//
// Semua fungsi idempotent & aman dipanggil berulang. Tidak mengubah stok,
// kas, atau data transaksi selain kolom status_bayar/jatuh_tempo (arah balik).
// ============================================================

// Awalan keterangan pembayaran otomatis (agar bisa dibatalkan bila faktur dibuka lagi).
const INVOICE_AUTO_PAY_PREFIX = 'Pelunasan otomatis (status faktur LUNAS)';

/** Sinkronkan piutang untuk satu faktur sesuai status_bayar-nya saat ini. */
function sync_invoice_debt(PDO $db, int $trx_id): void {
    if ($trx_id <= 0) return;

    $st = $db->prepare("SELECT t.id, t.no_nota, t.customer_id, t.grand_total, t.status_bayar, t.jatuh_tempo, t.created_at,
            c.nama AS customer_nama, c.telepon AS customer_telepon,
            v.merek, v.model, v.plat_nomor
        FROM transactions t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN vehicles v ON v.id = t.vehicle_id
        WHERE t.id = ?");
    $st->execute([$trx_id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return;

    $ds = $db->prepare("SELECT * FROM debts WHERE transaction_id = ? LIMIT 1");
    $ds->execute([$trx_id]);
    $debt = $ds->fetch(PDO::FETCH_ASSOC);

    $belum  = (($t['status_bayar'] ?? 'lunas') === 'belum');
    $jumlah = (float)$t['grand_total'];

    if ($belum) {
        if ($jumlah <= 0) {
            // Tidak ada nominal yang ditagih -> tidak perlu piutang.
            if ($debt && (float)$debt['dibayar'] <= 0) {
                $db->prepare("DELETE FROM debts WHERE id = ?")->execute([$debt['id']]);
            }
            return;
        }
        $kendaraan = trim(($t['merek'] ?? '') . ' ' . ($t['model'] ?? ''));
        $ket = 'Faktur ' . $t['no_nota'];
        if (!empty($t['plat_nomor'])) $ket .= ' - ' . ($kendaraan !== '' ? $kendaraan . ' / ' : '') . $t['plat_nomor'];
        $tgl = lokal($t['created_at'], 'Y-m-d');
        $jt  = (!empty($t['jatuh_tempo']) && _valid_date($t['jatuh_tempo'])) ? $t['jatuh_tempo'] : null;

        if ($debt) {
            $dibayar = (float)$debt['dibayar'];
            // Faktur dibuka lagi (LUNAS -> BELUM LUNAS): batalkan pelunasan OTOMATIS
            // sebelumnya; cicilan nyata yang pernah diinput tetap dipertahankan.
            if ($debt['status'] === 'lunas') {
                $db->prepare("DELETE FROM debt_payments WHERE debt_id=? AND keterangan LIKE ?")
                   ->execute([$debt['id'], INVOICE_AUTO_PAY_PREFIX . '%']);
                $sp = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM debt_payments WHERE debt_id=?");
                $sp->execute([$debt['id']]);
                $dibayar = (float)$sp->fetchColumn();
            }
            $status  = ($dibayar >= $jumlah) ? 'lunas' : 'belum_lunas';
            $db->prepare("UPDATE debts SET jenis='piutang', pihak=?, telepon=?, customer_id=?, keterangan=?, jumlah=?, dibayar=?, status=?, tgl=?, jatuh_tempo=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
               ->execute([$t['customer_nama'], (string)$t['customer_telepon'], $t['customer_id'], $ket, $jumlah, $dibayar, $status, $tgl, $jt, $debt['id']]);
            // Ternyata sudah terbayar penuh oleh cicilan nyata -> faktur memang LUNAS.
            if ($status === 'lunas') {
                $db->prepare("UPDATE transactions SET status_bayar='lunas', jatuh_tempo=NULL WHERE id=?")->execute([$trx_id]);
            }
        } else {
            $db->prepare("INSERT INTO debts (jenis, pihak, telepon, customer_id, transaction_id, keterangan, jumlah, dibayar, status, tgl, jatuh_tempo)
                          VALUES ('piutang',?,?,?,?,?,?,0,'belum_lunas',?,?)")
               ->execute([$t['customer_nama'], (string)$t['customer_telepon'], $t['customer_id'], $trx_id, $ket, $jumlah, $tgl, $jt]);
        }
        return;
    }

    // Faktur LUNAS: tutup piutang terkait (bila ada).
    if ($debt && $debt['status'] !== 'lunas') {
        $sisa = (float)$debt['jumlah'] - (float)$debt['dibayar'];
        if ($sisa > 0) {
            $db->prepare("INSERT INTO debt_payments (debt_id, jumlah, keterangan, tgl) VALUES (?,?,?,?)")
               ->execute([$debt['id'], $sisa, INVOICE_AUTO_PAY_PREFIX . ' ' . $t['no_nota'], date('Y-m-d')]);
        }
        $db->prepare("UPDATE debts SET dibayar = jumlah, status='lunas', updated_at=CURRENT_TIMESTAMP WHERE id=?")
           ->execute([$debt['id']]);
    }
}

/** Hapus piutang terkait faktur (dipanggil saat faktur dihapus). */
function delete_invoice_debt(PDO $db, int $trx_id): void {
    if ($trx_id <= 0) return;
    // debt_payments ikut terhapus via ON DELETE CASCADE.
    $db->prepare("DELETE FROM debts WHERE transaction_id = ?")->execute([$trx_id]);
}

/** Setelah piutang dilunasi dari menu Hutang Piutang -> faktur menjadi LUNAS. */
function mark_invoice_paid_if_debt_settled(PDO $db, int $debt_id): void {
    $d = $db->prepare("SELECT transaction_id, status FROM debts WHERE id = ?");
    $d->execute([$debt_id]);
    $row = $d->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['transaction_id']) && $row['status'] === 'lunas') {
        $db->prepare("UPDATE transactions SET status_bayar='lunas', jatuh_tempo=NULL WHERE id=?")
           ->execute([(int)$row['transaction_id']]);
    }
}

/** Ambil ringkasan piutang suatu faktur (untuk ditampilkan di halaman faktur). */
function get_invoice_debt(PDO $db, int $trx_id): ?array {
    $s = $db->prepare("SELECT id, jumlah, dibayar, status FROM debts WHERE transaction_id = ? LIMIT 1");
    $s->execute([$trx_id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
