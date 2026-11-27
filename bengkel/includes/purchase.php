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
        // Lepas tautan ke Purchase Order (bila ada) agar PO kembali menunggu sisa barang.
        try { $db->prepare("DELETE FROM purchase_order_receipts WHERE invoice_id=?")->execute([$pid]); } catch (\Throwable $e) { /* tabel PO belum dibuat */ }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 'Gagal membatalkan faktur: ' . $e->getMessage();
    }
    return null;
}

/**
 * Tarik kembali stok masuk dari rak asalnya (dipakai saat EDIT faktur).
 * Bila rak asal tidak ada / stoknya tidak cukup (sudah terpakai), jatuh ke
 * logika pengurangan standar (Rak Utama dulu, lalu rak lain).
 */
function purchase_rack_reverse(PDO $db, int $part_id, string $nama_rak, int $jumlah, int $uid, string $ket): void {
    if ($jumlah <= 0) return;
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) return; // bukan multi-rak: cukup parts.stok
    $nama_rak = trim($nama_rak);
    if ($nama_rak !== '' && $nama_rak !== RAK_UTAMA_KEY) {
        $r = $db->prepare("SELECT id, stok FROM part_racks WHERE part_id=? AND nama_rak=? FOR UPDATE");
        $r->execute([$part_id, $nama_rak]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['stok'] >= $jumlah) {
            $db->prepare("UPDATE part_racks SET stok = stok - ? WHERE id=?")->execute([$jumlah, $row['id']]);
            return;
        }
    } else {
        $u = rack_get_or_create_utama($db, $part_id);
        if ((int)$u['stok'] >= $jumlah) {
            $db->prepare("UPDATE part_racks SET stok = stok - ? WHERE id=?")->execute([$jumlah, $u['id']]);
            return;
        }
    }
    rack_deduct_for_keluar($db, $part_id, $jumlah, $uid, $ket);
}

/**
 * EDIT faktur supplier yang sudah jadi (khusus admin).
 *  - Stok dikoreksi dengan SELISIH (tambah/kurang) per sparepart, atomik dalam 1 transaksi.
 *  - Ditolak bila koreksi membuat stok minus (barang sudah terjual) -> tidak ada yang berubah.
 *  - Nomor faktur (PBL-...) & tanggal input asli tetap; riwayat stok faktur ditulis ulang.
 *  - Hutang disinkronkan ulang (pembayaran/cicilan yang sudah masuk tetap dipertahankan).
 * $head: supplier_id, no_sup, tgl, jt, status, catatan, upd_hb. $lines: [['part'=>[id,kode,nama],'qty','hb','sub','rak'],...]
 * Return null bila sukses, atau string pesan galat. Panggil di luar transaksi DB.
 */
function update_purchase_invoice(PDO $db, int $pid, array $head, array $lines): ?string {
    if (!$lines) return 'Faktur harus memiliki minimal satu sparepart.';
    $uid = (int)(current_user()['id'] ?? 0);
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT * FROM supplier_invoices WHERE id=? FOR UPDATE");
        $st->execute([$pid]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) { $db->rollBack(); return 'Faktur tidak ditemukan.'; }

        $it = $db->prepare("SELECT part_id, kode, nama, qty, rak FROM supplier_invoice_items WHERE invoice_id=? ORDER BY id");
        $it->execute([$pid]);
        $old = $it->fetchAll(PDO::FETCH_ASSOC);

        // Net per sparepart: stok akhir = stok - lama + baru, tidak boleh minus.
        $net = [];
        foreach ($old as $o) {
            if (empty($o['part_id'])) { $db->rollBack(); return 'Faktur ini memuat baris tanpa data sparepart (sparepart sudah dihapus) sehingga tidak bisa diedit.'; }
            $net[(int)$o['part_id']] = ($net[(int)$o['part_id']] ?? 0) - (int)$o['qty'];
        }
        foreach ($lines as $ln) $net[(int)$ln['part']['id']] = ($net[(int)$ln['part']['id']] ?? 0) + $ln['qty'];
        ksort($net); // urutan kunci tetap -> hindari deadlock
        $ps = $db->prepare("SELECT stok, kode, nama FROM parts WHERE id=? FOR UPDATE");
        foreach ($net as $partId => $delta) {
            $ps->execute([$partId]);
            $p = $ps->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $db->rollBack(); return 'Salah satu sparepart pada faktur sudah tidak ada di data sparepart.'; }
            if ($delta < 0 && (int)$p['stok'] + $delta < 0) {
                $db->rollBack();
                return "Koreksi ditolak: stok {$p['kode']} - {$p['nama']} tinggal {$p['stok']}, sedangkan koreksi mengurangi " . (-$delta) . " unit (sebagian sudah terjual). Faktur tidak diubah.";
            }
        }

        $ket = 'Faktur ' . $inv['no_faktur'] . ($head['no_sup'] !== '' ? ' (No. supplier: ' . $head['no_sup'] . ')' : '') . ' [diedit]';
        $insItem = $db->prepare("INSERT INTO supplier_invoice_items (invoice_id, part_id, kode, nama, qty, harga_beli, subtotal, rak) VALUES (?,?,?,?,?,?,?,?)");
        $insMv   = $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, supplier_id, ref_type, ref_id, keterangan, created_at) VALUES (?,?,?,?,?,?,?,?)");

        // 1) Terapkan isi BARU (tambah dulu agar stok/rak tidak sempat negatif)
        $total = 0.0;
        foreach ($lines as $ln) {
            $pt = $ln['part']; $total += $ln['sub'];
            $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$ln['qty'], $pt['id']]);
            rack_receive_masuk($db, (int)$pt['id'], $ln['rak'], $ln['qty']);
        }
        // 2) Tarik kembali isi LAMA
        foreach ($old as $o) {
            $db->prepare("UPDATE parts SET stok = stok - ? WHERE id=?")->execute([(int)$o['qty'], $o['part_id']]);
            purchase_rack_reverse($db, (int)$o['part_id'], (string)$o['rak'], (int)$o['qty'], $uid, 'Koreksi faktur ' . $inv['no_faktur']);
        }
        // 3) Tulis ulang item & riwayat stok faktur (tanggal riwayat tetap tanggal input asli)
        $db->prepare("DELETE FROM supplier_invoice_items WHERE invoice_id=?")->execute([$pid]);
        $db->prepare("DELETE FROM stock_movements WHERE ref_type='pembelian' AND ref_id=?")->execute([$pid]);
        foreach ($lines as $ln) {
            $pt = $ln['part'];
            $insItem->execute([$pid, $pt['id'], $pt['kode'], $pt['nama'], $ln['qty'], $ln['hb'], $ln['sub'], $ln['rak']]);
            if (!empty($head['upd_hb']) && $ln['hb'] > 0) $db->prepare("UPDATE parts SET harga_beli=? WHERE id=?")->execute([$ln['hb'], $pt['id']]);
            $insMv->execute([$pt['id'], 'masuk', $ln['qty'], $head['supplier_id'], 'pembelian', $pid, $ket, $inv['created_at']]);
        }
        // 4) Header faktur + hutang
        $status = ($total <= 0) ? 'lunas' : $head['status'];
        $db->prepare("UPDATE supplier_invoices SET no_faktur_supplier=?, supplier_id=?, tgl=?, jatuh_tempo=?, total=?, status_bayar=?, catatan=? WHERE id=?")
           ->execute([$head['no_sup'], $head['supplier_id'], $head['tgl'], $head['jt'], $total, $status, $head['catatan'], $pid]);
        sync_purchase_debt($db, $pid);
        $db->commit();
        if (function_exists('app_log')) app_log('purchase', 'Faktur ' . $inv['no_faktur'] . ' diedit oleh ' . ((string)(current_user()['nama'] ?? '?')) . ': total ' . $inv['total'] . ' -> ' . $total);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 'Gagal menyimpan koreksi faktur: ' . $e->getMessage();
    }
    return null;
}

/** Tandai ke browser bahwa faktur dari draf tertentu SUDAH tersimpan (cookie dibaca JS untuk menghapus draf). */
function purchase_mark_draft_done(): void {
    $t = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['draft_token'] ?? ''));
    if ($t === '' || headers_sent()) return;
    setcookie('pbl_done', $t, ['expires' => time() + 2592000, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
}
