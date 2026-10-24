<?php
// ============================================================
// purchase.php - Faktur TAGIHAN SUPPLIER (barang masuk) <-> HUTANG.
//
//  - Barang masuk dari supplier diinput sebagai 1 faktur (banyak item).
//  - Faktur BELUM LUNAS  -> otomatis tercatat sebagai HUTANG (jenis 'hutang')
//    di menu Hutang Piutang, atas nama supplier.
//  - Faktur LUNAS        -> tidak ada hutang / hutang terkait ditutup.
//  - Hutang dilunasi dari menu Hutang Piutang -> faktur otomatis LUNAS.
//  - Faktur dibatalkan (admin) -> stok dikembalikan (bila masih mencukupi),
//    riwayat stok & hutang terkait ikut dibersihkan.
// ============================================================
require_once __DIR__ . '/invoice_debt.php'; // memakai INVOICE_AUTO_PAY_PREFIX

/** Sinkronkan hutang untuk satu faktur supplier sesuai status_bayar-nya. */
function sync_purchase_debt(PDO $db, int $pid): void {
    if ($pid <= 0) return;
    $st = $db->prepare("SELECT i.*, s.nama AS supplier_nama, s.telepon AS supplier_telepon
        FROM supplier_invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.id = ?");
    $st->execute([$pid]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return;

    $ds = $db->prepare("SELECT * FROM debts WHERE purchase_id = ? LIMIT 1");
    $ds->execute([$pid]);
    $debt = $ds->fetch(PDO::FETCH_ASSOC);

    $belum  = (($inv['status_bayar'] ?? 'belum') === 'belum');
    $jumlah = (float)$inv['total'];

    if ($belum) {
        if ($jumlah <= 0) return;
        $ket = 'Faktur pembelian ' . $inv['no_faktur'];
        if (trim((string)$inv['no_faktur_supplier']) !== '') $ket .= ' (No. faktur supplier: ' . $inv['no_faktur_supplier'] . ')';
        $tgl = _valid_date($inv['tgl']) ? $inv['tgl'] : lokal($inv['created_at'], 'Y-m-d');
        $jt  = (!empty($inv['jatuh_tempo']) && _valid_date($inv['jatuh_tempo'])) ? $inv['jatuh_tempo'] : null;

        if ($debt) {
            $dibayar = (float)$debt['dibayar'];
            if ($debt['status'] === 'lunas') { // dibuka lagi: batalkan pelunasan otomatis
                $db->prepare("DELETE FROM debt_payments WHERE debt_id=? AND keterangan LIKE ?")
                   ->execute([$debt['id'], INVOICE_AUTO_PAY_PREFIX . '%']);
                $sp = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM debt_payments WHERE debt_id=?");
                $sp->execute([$debt['id']]);
                $dibayar = (float)$sp->fetchColumn();
            }
            $status = ($dibayar >= $jumlah) ? 'lunas' : 'belum_lunas';
            $db->prepare("UPDATE debts SET jenis='hutang', pihak=?, telepon=?, supplier_id=?, keterangan=?, jumlah=?, dibayar=?, status=?, tgl=?, jatuh_tempo=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
               ->execute([$inv['supplier_nama'], (string)$inv['supplier_telepon'], $inv['supplier_id'], $ket, $jumlah, $dibayar, $status, $tgl, $jt, $debt['id']]);
            if ($status === 'lunas') {
                $db->prepare("UPDATE supplier_invoices SET status_bayar='lunas' WHERE id=?")->execute([$pid]);
            }
        } else {
            $db->prepare("INSERT INTO debts (jenis, pihak, telepon, supplier_id, purchase_id, keterangan, jumlah, dibayar, status, tgl, jatuh_tempo)
                          VALUES ('hutang',?,?,?,?,?,?,0,'belum_lunas',?,?)")
               ->execute([$inv['supplier_nama'], (string)$inv['supplier_telepon'], $inv['supplier_id'], $pid, $ket, $jumlah, $tgl, $jt]);
        }
        return;
    }

    // LUNAS: tutup hutang terkait bila ada.
    if ($debt && $debt['status'] !== 'lunas') {
        $sisa = (float)$debt['jumlah'] - (float)$debt['dibayar'];
        if ($sisa > 0) {
            $db->prepare("INSERT INTO debt_payments (debt_id, jumlah, keterangan, tgl) VALUES (?,?,?,?)")
               ->execute([$debt['id'], $sisa, INVOICE_AUTO_PAY_PREFIX . ' ' . $inv['no_faktur'], date('Y-m-d')]);
        }
        $db->prepare("UPDATE debts SET dibayar = jumlah, status='lunas', updated_at=CURRENT_TIMESTAMP WHERE id=?")
           ->execute([$debt['id']]);
    }
}

/** Hutang terkait faktur supplier sudah lunas dari menu Hutang Piutang -> faktur LUNAS. */
function mark_purchase_paid_if_debt_settled(PDO $db, int $debt_id): void {
    $d = $db->prepare("SELECT purchase_id, status FROM debts WHERE id = ?");
    $d->execute([$debt_id]);
    $row = $d->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['purchase_id']) && $row['status'] === 'lunas') {
        $db->prepare("UPDATE supplier_invoices SET status_bayar='lunas' WHERE id=?")->execute([(int)$row['purchase_id']]);
    }
}

/**
 * Batalkan faktur supplier: stok dikembalikan, riwayat stok & hutang dihapus.
 * Return null bila sukses, atau string pesan galat (tidak ada yang berubah).
 * Harus dipanggil di luar transaksi DB yang sedang berjalan.
 */
function delete_purchase_invoice(PDO $db, int $pid): ?string {
    $st = $db->prepare("SELECT id, no_faktur FROM supplier_invoices WHERE id=?");
    $st->execute([$pid]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return 'Faktur tidak ditemukan.';

    $it = $db->prepare("SELECT part_id, kode, nama, SUM(qty) AS qty FROM supplier_invoice_items WHERE invoice_id=? AND part_id IS NOT NULL GROUP BY part_id, kode, nama");
    $it->execute([$pid]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    try {
        // Pastikan stok masih cukup untuk ditarik kembali (barang belum terjual).
        foreach ($items as $r) {
            $ps = $db->prepare("SELECT stok FROM parts WHERE id=? FOR UPDATE");
            $ps->execute([$r['part_id']]);
            $stok = $ps->fetchColumn();
            if ($stok === false) continue; // sparepart sudah dihapus: lewati
            if ((int)$stok < (int)$r['qty']) {
                $db->rollBack();
                return "Faktur tidak bisa dibatalkan: stok {$r['kode']} - {$r['nama']} tinggal $stok, sedangkan faktur ini memasukkan {$r['qty']} unit (sebagian sudah terjual).";
            }
        }
        $uid = (int)(current_user()['id'] ?? 0);
        foreach ($items as $r) {
            $ex = $db->prepare("SELECT 1 FROM parts WHERE id=?"); $ex->execute([$r['part_id']]);
            if (!$ex->fetchColumn()) continue;
            rack_deduct_for_keluar($db, (int)$r['part_id'], (int)$r['qty'], $uid, 'Pembatalan faktur ' . $inv['no_faktur']);
            $db->prepare("UPDATE parts SET stok = stok - ? WHERE id=?")->execute([(int)$r['qty'], $r['part_id']]);
        }
        $db->prepare("DELETE FROM stock_movements WHERE ref_type='pembelian' AND ref_id=?")->execute([$pid]);
        $db->prepare("DELETE FROM debts WHERE purchase_id=?")->execute([$pid]); // debt_payments ikut (CASCADE)
        $db->prepare("DELETE FROM supplier_invoices WHERE id=?")->execute([$pid]); // item ikut (CASCADE)
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 'Gagal membatalkan faktur: ' . $e->getMessage();
    }
    return null;
}
