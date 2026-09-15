<?php
// ============================================================
// seed_dummy.php - Isi data contoh (±10 aktivitas per fitur)
// agar tampilan tiap menu terlihat berisi. Idempotent via marker
// settings.dummy_seeded. Jalankan: php scripts/seed_dummy.php [force]
// ============================================================
require_once __DIR__ . '/../includes/db.php';
init_db();
$db = db();

$force = in_array('force', $argv, true);
if (setting('dummy_seeded', '') === '1' && !$force) {
    echo "Data dummy sudah pernah dibuat. Jalankan dengan argumen 'force' untuk mengulang.\n";
    exit(0);
}

function u($y, $m, $d, $h = 3) { return sprintf('%04d-%02d-%02d %02d:00:00', $y, $m, $d, $h); } // UTC (+7 = WIB)

// ---------- 1) Pelanggan + kendaraan ----------
$customers = [
    ['Budi Santoso', '081234500001', 'Jl. Melati No. 1', 'Honda', 'Vario 125', 'B 1234 ABC'],
    ['Siti Aminah', '081234500002', 'Jl. Mawar No. 2', 'Yamaha', 'NMAX', 'B 2345 BCD'],
    ['Agus Pratama', '081234500003', 'Jl. Kenanga No. 3', 'Honda', 'Beat', 'B 3456 CDE'],
    ['Dewi Lestari', '081234500004', 'Jl. Anggrek No. 4', 'Suzuki', 'Satria FU', 'B 4567 DEF'],
    ['Eko Nugroho', '081234500005', 'Jl. Dahlia No. 5', 'Yamaha', 'Aerox', 'B 5678 EFG'],
    ['Fitri Handayani', '081234500006', 'Jl. Cempaka No. 6', 'Honda', 'PCX', 'B 6789 FGH'],
    ['Gunawan', '081234500007', 'Jl. Flamboyan No. 7', 'Kawasaki', 'Ninja 250', 'B 7890 GHI'],
    ['Hana Permata', '081234500008', 'Jl. Teratai No. 8', 'Honda', 'Scoopy', 'B 8901 HIJ'],
    ['Indra Wijaya', '081234500009', 'Jl. Bougenville No. 9', 'Yamaha', 'Mio', 'B 9012 IJK'],
    ['Joko Susilo', '081234500010', 'Jl. Kamboja No. 10', 'Honda', 'CBR 150', 'B 1123 JKL'],
];
$cust_ids = []; $veh_ids = [];
$insC = $db->prepare("INSERT INTO customers (nama, telepon, alamat) VALUES (?,?,?)");
$insV = $db->prepare("INSERT INTO vehicles (customer_id, merek, model, plat_nomor) VALUES (?,?,?,?)");
foreach ($customers as $c) {
    $insC->execute([$c[0], $c[1], $c[2]]);
    $cid = (int)$db->lastInsertId(); $cust_ids[] = $cid;
    $insV->execute([$cid, $c[3], $c[4], $c[5]]);
    $veh_ids[] = (int)$db->lastInsertId();
}

// ---------- 2) Supplier ----------
$suppliers = [
    ['Maju Jaya Motor', '0217700001', 'sales@majujaya.co.id', 'Jakarta'],
    ['Sumber Rejeki Part', '0217700002', 'info@sumberrejeki.co.id', 'Bekasi'],
    ['Anugrah Sparepart', '0217700003', 'cs@anugrah.co.id', 'Tangerang'],
    ['Berkah Oli', '0217700004', 'order@berkaholi.co.id', 'Depok'],
    ['Cahaya Ban', '0217700005', 'sales@cahayaban.co.id', 'Bogor'],
];
$sup_ids = [];
$insS = $db->prepare("INSERT INTO suppliers (nama, telepon, email, alamat) VALUES (?,?,?,?)");
foreach ($suppliers as $s) { $insS->execute($s); $sup_ids[] = (int)$db->lastInsertId(); }

// ---------- 3) Sparepart (10 dummy, sebagian stok menipis) ----------
$parts = [
    ['DM-OLI-001', 'Oli Mesin MPX2 0.8L', 'Oli', 32000, 42000, 40, 10],
    ['DM-OLI-002', 'Oli Gardan 120ml', 'Oli', 8000, 13000, 6, 8],
    ['DM-BUS-001', 'Busi NGK CPR8EA', 'Busi', 15000, 22000, 25, 10],
    ['DM-KMP-001', 'Kampas Rem Depan Vario', 'Kampas Rem', 28000, 45000, 4, 6],
    ['DM-KMP-002', 'Kampas Kopling Satria', 'Kampas Rem', 55000, 85000, 12, 5],
    ['DM-BAN-001', 'Ban Luar IRC 80/90-14', 'Ban', 120000, 165000, 3, 5],
    ['DM-AKI-001', 'Aki GS GTZ5S', 'Aki', 145000, 195000, 8, 4],
    ['DM-RTI-001', 'Rantai + Gir SSS Beat', 'Rantai & Gir', 130000, 185000, 5, 4],
    ['DM-LMP-001', 'Lampu LED Depan H4', 'Lampu', 45000, 75000, 20, 6],
    ['DM-FLT-001', 'Filter Udara NMAX', 'Lainnya', 35000, 55000, 2, 5],
];
$part_ids = [];
$insCat = $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)");
$insP = $db->prepare("INSERT INTO parts (kode, nama, kategori, harga_beli, harga_jual, stok, stok_min) VALUES (?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE nama=VALUES(nama), kategori=VALUES(kategori), harga_beli=VALUES(harga_beli),
                      harga_jual=VALUES(harga_jual), stok=VALUES(stok), stok_min=VALUES(stok_min)");
foreach ($parts as $p) {
    $insCat->execute([$p[2]]);
    $insP->execute($p);
    $pid = (int)$db->lastInsertId();
    if ($pid === 0) { // sudah ada (duplicate) -> ambil id
        $q = $db->prepare("SELECT id FROM parts WHERE kode=?"); $q->execute([$p[0]]); $pid = (int)$q->fetchColumn();
    }
    $part_ids[] = $pid;
}

// ---------- 4) Pergerakan stok (10) ----------
$insSM = $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, supplier_id, ref_type, keterangan, created_at) VALUES (?,?,?,?,?,?,?)");
for ($i = 0; $i < 10; $i++) {
    $pid = $part_ids[$i % count($part_ids)];
    $tipe = $i % 3 === 0 ? 'keluar' : 'masuk';
    $sup = $tipe === 'masuk' ? $sup_ids[$i % count($sup_ids)] : null;
    $ket = $tipe === 'masuk' ? ('Faktur SUP-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT)) : 'Penyesuaian stok';
    $insSM->execute([$pid, $tipe, ($i % 5) + 2, $sup, 'manual', $ket, u(2026, 9, ($i % 4) + 1)]);
}

// ---------- 5) Transaksi kasir (10) + item ----------
$insT = $db->prepare("INSERT INTO transactions (no_nota, customer_id, vehicle_id, total_jasa, total_part, diskon, grand_total, status, metode_bayar, catatan, created_at) VALUES (?,?,?,?,?,?,?, 'selesai', ?, '', ?)");
$insTI = $db->prepare("INSERT INTO transaction_items (transaction_id, tipe, part_id, nama, qty, harga, subtotal, garansi_hari) VALUES (?,?,?,?,?,?,?,?)");
$trx_ids = []; $trx_items_map = [];
for ($i = 0; $i < 10; $i++) {
    $jasa = 25000 + ($i % 4) * 15000;
    $pidx = $i % count($part_ids);
    $pid = $part_ids[$pidx];
    $harga = $parts[$pidx][4]; $qty = ($i % 2) + 1; $totalPart = $harga * $qty;
    $diskon = $i % 3 === 0 ? 5000 : 0;
    $grand = $jasa + $totalPart - $diskon;
    $no = 'TRX-202609-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT);
    $metode = $i % 2 === 0 ? 'cash' : 'transfer';
    $insT->execute([$no, $cust_ids[$i], $veh_ids[$i], $jasa, $totalPart, $diskon, $grand, $metode, u(2026, 9, ($i % 4) + 1, 4 + ($i % 6))]);
    $tid = (int)$db->lastInsertId(); $trx_ids[] = $tid;
    // item jasa
    $insTI->execute([$tid, 'jasa', null, 'Jasa Servis Berkala', 1, $jasa, $jasa, 0]);
    // item part (dengan garansi utk sebagian)
    $garansi = $i % 2 === 0 ? 30 : 0;
    $insTI->execute([$tid, 'part', $pid, $parts[$pidx][1], $qty, $harga, $totalPart, $garansi]);
    $itemId = (int)$db->lastInsertId();
    $trx_items_map[$tid] = ['item_id' => $itemId, 'nama' => $parts[$pidx][1], 'cust' => $cust_ids[$i], 'garansi' => $garansi, 'tgl' => date('Y-m-d')];
}

// ---------- 6) Klaim garansi (3) ----------
$insW = $db->prepare("INSERT INTO warranty_claims (kode, transaction_id, transaction_item_id, customer_id, item_nama, tgl_beli, tgl_berakhir, status, alasan, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$wcount = 0;
foreach ($trx_ids as $idx => $tid) {
    if ($trx_items_map[$tid]['garansi'] <= 0 || $wcount >= 3) continue;
    $wcount++;
    $kode = 'GRS-202609-' . str_pad((string)$wcount, 3, '0', STR_PAD_LEFT);
    $tglBeli = '2026-09-0' . (($idx % 4) + 1);
    $tglAkhir = date('Y-m-d', strtotime($tglBeli . ' +30 days'));
    $status = ['pending', 'diproses', 'disetujui'][$wcount - 1];
    $insW->execute([$kode, $tid, $trx_items_map[$tid]['item_id'], $trx_items_map[$tid]['cust'], $trx_items_map[$tid]['nama'], $tglBeli, $tglAkhir, $status, 'Klaim contoh: part cacat pemakaian normal', u(2026, 9, ($idx % 4) + 1), u(2026, 9, ($idx % 4) + 1)]);
}

// ---------- 7) Hutang & Piutang (10) + pembayaran (3) ----------
$insD = $db->prepare("INSERT INTO debts (jenis, pihak, telepon, keterangan, jumlah, dibayar, status, tgl, jatuh_tempo, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$insDP = $db->prepare("INSERT INTO debt_payments (debt_id, jumlah, keterangan, tgl) VALUES (?,?,?,?)");
$debts = [
    ['piutang', 'Budi Santoso', '081234500001', 'Servis belum lunas', 350000, 0, '2026-09-01', '2026-09-02'],   // overdue
    ['piutang', 'Dewi Lestari', '081234500004', 'Ganti ban tempo', 165000, 0, '2026-09-02', '2026-09-06'],       // near due
    ['piutang', 'Gunawan', '081234500007', 'Servis besar', 850000, 200000, '2026-09-01', '2026-09-15'],
    ['piutang', 'Indra Wijaya', '081234500009', 'Sparepart pesanan', 275000, 0, '2026-09-03', '2026-09-10'],
    ['piutang', 'Joko Susilo', '081234500010', 'Bon servis', 120000, 120000, '2026-08-20', '2026-08-30'],         // lunas
    ['hutang', 'Maju Jaya Motor', '0217700001', 'Faktur oli & busi', 1200000, 400000, '2026-09-01', '2026-09-05'],// near due
    ['hutang', 'Berkah Oli', '0217700004', 'Restok oli', 750000, 0, '2026-09-02', '2026-09-12'],
    ['hutang', 'Cahaya Ban', '0217700005', 'Ban IRC 10 pcs', 1150000, 0, '2026-09-03', '2026-09-20'],
    ['hutang', 'Sumber Rejeki Part', '0217700002', 'Kampas rem grosir', 480000, 480000, '2026-08-25', '2026-09-01'], // lunas
    ['hutang', 'Anugrah Sparepart', '0217700003', 'Aki 5 pcs', 725000, 300000, '2026-09-01', '2026-09-08'],
];
foreach ($debts as $d) {
    $status = $d[5] >= $d[4] ? 'lunas' : 'belum_lunas';
    $insD->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $status, $d[6], $d[7], u(2026, 9, 1), u(2026, 9, 1)]);
    $did = (int)$db->lastInsertId();
    if ($d[5] > 0) $insDP->execute([$did, $d[5], 'Pembayaran awal', $d[6]]);
}

// ---------- 8) Kas (10, tersebar Sep) ----------
$insCash = $db->prepare("INSERT INTO cash_entries (tipe, kategori, jumlah, keterangan, tgl, created_at) VALUES (?,?,?,?,?,?)");
$cash = [
    ['masuk', 'Modal Awal', 2000000, 'Setoran modal kas', '2026-09-01'],
    ['masuk', 'Pendapatan Servis', 850000, 'Kas dari servis harian', '2026-09-02'],
    ['keluar', 'Sewa Tempat', 750000, 'Sewa bulanan', '2026-09-01'],
    ['keluar', 'Listrik & Air', 220000, 'Token PLN + PDAM', '2026-09-02'],
    ['keluar', 'Gaji Mekanik', 900000, 'Gaji mingguan', '2026-09-03'],
    ['masuk', 'Pendapatan Servis', 640000, 'Kas servis', '2026-09-03'],
    ['keluar', 'Konsumsi', 120000, 'Makan siang tim', '2026-09-03'],
    ['keluar', 'ATK & Nota', 85000, 'Cetak nota', '2026-09-04'],
    ['masuk', 'Pelunasan Piutang', 200000, 'Cicilan Gunawan', '2026-09-04'],
    ['keluar', 'Perawatan Alat', 150000, 'Servis kompresor', '2026-09-04'],
];
foreach ($cash as $c) { $insCash->execute([$c[0], $c[1], $c[2], $c[3], $c[4], u(2026, 9, (int)substr($c[4], 8, 2))]); }

set_setting('dummy_seeded', '1');
echo "Selesai. Pelanggan:" . count($cust_ids) . " Supplier:" . count($sup_ids) . " Sparepart:" . count($part_ids)
   . " Transaksi:" . count($trx_ids) . " Garansi:$wcount Hutang/Piutang:" . count($debts) . " Kas:" . count($cash) . "\n";
