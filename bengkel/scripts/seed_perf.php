<?php
// ============================================================
// seed_perf.php - Isi dataset BESAR untuk uji performa/EXPLAIN.
// Hanya untuk development/staging (preview), BUKAN produksi.
// Jalankan: php scripts/seed_perf.php [jumlah_parts] [jumlah_trx]
//   default: 100000 parts, 30000 transaksi.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
init_db();
$db = db();

$nParts = (int)($argv[1] ?? 100000);
$nTrx   = (int)($argv[2] ?? 30000);
$t0 = microtime(true);

function rnd($arr) { return $arr[array_rand($arr)]; }
$kategori = ['Oli','Kampas Rem','Busi','Aki','Ban','Rantai & Gir','Lampu','Lainnya'];
$merek = ['Honda','Yamaha','Suzuki','Kawasaki'];

// ---- Pelanggan + kendaraan (200) bila belum banyak ----
$custIds = $db->query("SELECT id FROM customers")->fetchAll(PDO::FETCH_COLUMN);
if (count($custIds) < 200) {
    $db->beginTransaction();
    $ic = $db->prepare("INSERT INTO customers (nama, telepon, alamat) VALUES (?,?,?)");
    $iv = $db->prepare("INSERT INTO vehicles (customer_id, merek, model, plat_nomor) VALUES (?,?,?,?)");
    for ($i = count($custIds); $i < 200; $i++) {
        $ic->execute(["Pelanggan $i", '0812' . str_pad((string)$i, 8, '0', STR_PAD_LEFT), "Jl. Uji No. $i"]);
        $cid = (int)$db->lastInsertId();
        $iv->execute([$cid, rnd($merek), 'Model ' . ($i % 20), 'B ' . (1000 + $i) . ' XYZ']);
        $custIds[] = $cid;
    }
    $db->commit();
}

// ---- Parts (multi-row batch insert) ----
$have = (int)$db->query("SELECT COUNT(*) FROM parts")->fetchColumn();
echo "Parts sekarang: $have. Target: $nParts\n";
if ($have < $nParts) {
    $batch = 1000;
    $db->exec("SET autocommit=0");
    $start = $have + 1;
    for ($base = $start; $base <= $nParts; $base += $batch) {
        $rows = []; $vals = [];
        $end = min($base + $batch - 1, $nParts);
        for ($i = $base; $i <= $end; $i++) {
            $rows[] = "(?,?,?,?,?,?,?,?)";
            $kode = 'SP' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
            $barcode = '899' . str_pad((string)$i, 10, '0', STR_PAD_LEFT);
            $nama = rnd($kategori) . ' ' . rnd($merek) . ' Tipe ' . ($i % 500) . ' #' . $i;
            $hb = rand(5, 500) * 1000; $hj = $hb + rand(2, 50) * 1000;
            $stok = rand(0, 100); $stokmin = 5;
            array_push($vals, $kode, $barcode, $nama, rnd($kategori), $hb, $hj, $stok, $stokmin);
        }
        $sql = "INSERT INTO parts (kode, barcode, nama, kategori, harga_beli, harga_jual, stok, stok_min) VALUES " . implode(',', $rows);
        $db->prepare($sql)->execute($vals);
        $db->exec("COMMIT");
        if ($base % 20000 < $batch) echo "  parts: $end\n";
    }
    $db->exec("SET autocommit=1");
}

// ---- Transaksi + item (tersebar 18 bulan ke belakang) ----
$have = (int)$db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
echo "Transaksi sekarang: $have. Target: $nTrx\n";
if ($have < $nTrx) {
    $partIds = $db->query("SELECT id, harga_jual, harga_beli FROM parts ORDER BY id LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
    $seqBase = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM transactions")->fetchColumn();
    $db->exec("SET autocommit=0");
    $it = $db->prepare("INSERT INTO transactions (no_nota, customer_id, vehicle_id, total_jasa, total_part, diskon, grand_total, status, metode_bayar, created_at) VALUES (?,?,?,?,?,?,?,'selesai',?,?)");
    $ii = $db->prepare("INSERT INTO transaction_items (transaction_id, tipe, part_id, nama, qty, harga, subtotal, garansi_hari) VALUES (?,?,?,?,?,?,?,0)");
    $iu = $db->prepare("UPDATE parts SET stok = stok + 1 WHERE id=?"); // jaga stok tak minus (dummy)
    $n = 0;
    for ($i = $have; $i < $nTrx; $i++) {
        $daysAgo = rand(0, 540);
        $ts = date('Y-m-d H:i:s', strtotime("-$daysAgo days") - rand(0, 86400));
        $ym = date('Ym', strtotime($ts));
        $seq = ++$seqBase;
        $noNota = 'TRX-' . $ym . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
        $cid = (int)rnd($custIds);
        $jasa = rand(0, 3) * 25000;
        $items = [];
        $tp = 0;
        $nItem = rand(1, 3);
        for ($k = 0; $k < $nItem; $k++) {
            $p = rnd($partIds); $qty = rand(1, 3); $harga = (float)$p['harga_jual']; $sub = $qty * $harga;
            $items[] = ['pid'=>$p['id'], 'qty'=>$qty, 'harga'=>$harga, 'sub'=>$sub]; $tp += $sub;
        }
        $grand = $jasa + $tp;
        $it->execute([$noNota, $cid, null, $jasa, $tp, 0, $grand, rnd(['cash','transfer']), $ts]);
        $tid = (int)$db->lastInsertId();
        foreach ($items as $x) { $ii->execute([$tid, 'part', $x['pid'], 'Item', $x['qty'], $x['harga'], $x['sub']]); }
        if ((++$n % 2000) === 0) { $db->exec("COMMIT"); echo "  trx: $n\n"; }
    }
    $db->exec("COMMIT");
    $db->exec("SET autocommit=1");
}

set_setting('perf_seeded', '1');
printf("Selesai dalam %.1f detik. Parts=%d Trx=%d\n",
    microtime(true) - $t0,
    (int)$db->query("SELECT COUNT(*) FROM parts")->fetchColumn(),
    (int)$db->query("SELECT COUNT(*) FROM transactions")->fetchColumn());
