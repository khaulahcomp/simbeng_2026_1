<?php
// ============================================================
// racks.php - Helper fitur "Multi Rak".
//
// Konsep dasar:
// - parts.stok tetap TOTAL stok sparepart (dipakai apa adanya di seluruh
//   halaman lain: dashboard, laporan, low-stock, dsb - TIDAK ada perubahan
//   di tempat lain).
// - Saat multi_rak_aktif = 0 (default): sparepart hanya punya 1 lokasi
//   (parts.lokasi_rak), sama seperti sebelum fitur ini ada.
// - Saat multi_rak_aktif = 1: parts.lokasi_rak menjadi nama "Rak Utama".
//   Detail lokasi (termasuk Rak Utama) tersimpan di part_racks, dan
//   SUM(part_racks.stok) untuk part tsb SELALU SAMA DENGAN parts.stok.
//
// PENTING - Rak Utama BUKAN acuan/sumber wajib saat membuat rak baru:
//   Etalase (Rak Utama) bisa saja hanya muat 10 unit, sementara 30 unit
//   lain disimpan di gudang (rak lain). Karena itu:
//     * rack_add_stock_to_rak() -> "Tambah Rak" MENAMBAH stok baru
//       langsung ke rak tsb (& ke total parts.stok) - TIDAK mengurangi
//       Rak Utama, dan jumlahnya boleh lebih besar dari stok Rak Utama.
//     * rack_transfer_manual()  -> fitur "Transfer Rak" - ini yang
//       benar2 MEMINDAHKAN stok antar rak yang sudah ada, jadi rak asal
//       WAJIB punya stok cukup (kalau kurang -> error/notif merah).
//     * rack_deduct_for_keluar() -> saat barang keluar (penjualan/servis/
//       garansi/manual), Rak Utama SELALU dikurangi lebih dulu; bila
//       kurang, otomatis tarik dari rak lain (dicatat sbg transfer
//       otomatis, dengan konfirmasi user sebelum disimpan).
// ============================================================

const RAK_UTAMA_KEY = '__UTAMA__'; // dipakai sbg alias "rak_asal"/"rak_tujuan" utk Rak Utama di form

// Ambil (atau buat) baris Rak Utama untuk sebuah part. Dipanggil hanya saat
// multi_rak_aktif=1. Idempotent.
function rack_get_or_create_utama(PDO $db, int $part_id, string $nama_utama = ''): array {
    $st = $db->prepare("SELECT * FROM part_racks WHERE part_id=? AND is_utama=1 LIMIT 1");
    $st->execute([$part_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $p = $db->prepare("SELECT stok, lokasi_rak FROM parts WHERE id=?");
    $p->execute([$part_id]);
    $part = $p->fetch(PDO::FETCH_ASSOC) ?: ['stok' => 0, 'lokasi_rak' => ''];
    $nama = trim($nama_utama) ?: (trim((string)$part['lokasi_rak']) ?: 'Rak Utama');

    $db->prepare("INSERT INTO part_racks (part_id, nama_rak, is_utama, stok) VALUES (?,?,1,?)
                  ON DUPLICATE KEY UPDATE is_utama=1")
       ->execute([$part_id, $nama, (int)$part['stok']]);

    $st->execute([$part_id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

// Aktifkan Multi Rak untuk sebuah sparepart. Seluruh stok saat ini otomatis
// menjadi isi Rak Utama (nama bebas sesuai input user / lokasi_rak lama).
function rack_activate(PDO $db, int $part_id, string $nama_utama = ''): void {
    $p = $db->prepare("SELECT stok, lokasi_rak, multi_rak_aktif FROM parts WHERE id=?");
    $p->execute([$part_id]);
    $part = $p->fetch(PDO::FETCH_ASSOC);
    if (!$part) return;

    $nama = trim($nama_utama) ?: (trim((string)$part['lokasi_rak']) ?: 'Rak Utama');

    if ((int)$part['multi_rak_aktif'] === 1) {
        // Sudah aktif: hanya perbarui nama Rak Utama bila diganti.
        $db->prepare("UPDATE part_racks SET nama_rak=? WHERE part_id=? AND is_utama=1")->execute([$nama, $part_id]);
        $db->prepare("UPDATE parts SET lokasi_rak=? WHERE id=?")->execute([$nama, $part_id]);
        return;
    }

    $db->prepare("INSERT INTO part_racks (part_id, nama_rak, is_utama, stok) VALUES (?,?,1,?)
                  ON DUPLICATE KEY UPDATE is_utama=1, stok=VALUES(stok)")
       ->execute([$part_id, $nama, (int)$part['stok']]);
    $db->prepare("UPDATE parts SET multi_rak_aktif=1, lokasi_rak=? WHERE id=?")->execute([$nama, $part_id]);
}

// Non-aktifkan Multi Rak: seluruh rak digabung kembali menjadi 1 lokasi
// (nama Rak Utama dipakai sbg lokasi_rak), semua baris part_racks dihapus.
function rack_deactivate(PDO $db, int $part_id): void {
    $st = $db->prepare("SELECT nama_rak FROM part_racks WHERE part_id=? AND is_utama=1 LIMIT 1");
    $st->execute([$part_id]);
    $nama = $st->fetchColumn();
    if ($nama === false) {
        $p = $db->prepare("SELECT lokasi_rak FROM parts WHERE id=?"); $p->execute([$part_id]);
        $nama = $p->fetchColumn() ?: '';
    }
    // Pastikan total stok konsisten (jaga-jaga bila ada selisih pembulatan).
    $sum = $db->prepare("SELECT COALESCE(SUM(stok),0) FROM part_racks WHERE part_id=?");
    $sum->execute([$part_id]);
    $total = (int)$sum->fetchColumn();
    if ($total > 0) {
        $db->prepare("UPDATE parts SET stok=? WHERE id=?")->execute([$total, $part_id]);
    }
    $db->prepare("DELETE FROM part_racks WHERE part_id=?")->execute([$part_id]);
    $db->prepare("UPDATE parts SET multi_rak_aktif=0, lokasi_rak=? WHERE id=?")->execute([$nama, $part_id]);
}

// Daftar rak sebuah part, Rak Utama selalu tampil pertama.
function rack_list(PDO $db, int $part_id): array {
    $st = $db->prepare("SELECT * FROM part_racks WHERE part_id=? ORDER BY is_utama DESC, id ASC");
    $st->execute([$part_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Ringkasan cepat utk badge di tabel listing sparepart.
function rack_summary(PDO $db, int $part_id): array {
    $rows = rack_list($db, $part_id);
    return ['count' => count($rows), 'rows' => $rows];
}

// Dipanggil paralel dengan "Barang Masuk" (parts.stok = stok + jumlah sudah
// dijalankan oleh caller). Menambah jumlah tsb ke rak pilihan user.
// $nama_rak boleh Rak Utama (RAK_UTAMA_KEY) atau nama rak lain (baru/lama).
function rack_receive_masuk(PDO $db, int $part_id, string $nama_rak, int $jumlah): void {
    if ($jumlah <= 0) return;
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) return; // non multi-rak: cukup parts.stok saja

    if ($nama_rak === '' || $nama_rak === RAK_UTAMA_KEY) {
        $utama = rack_get_or_create_utama($db, $part_id);
        $db->prepare("UPDATE part_racks SET stok = stok + ? WHERE id=?")->execute([$jumlah, $utama['id']]);
        return;
    }
    $nama_rak = trim($nama_rak);
    $db->prepare("INSERT INTO part_racks (part_id, nama_rak, is_utama, stok) VALUES (?,?,0,?)
                  ON DUPLICATE KEY UPDATE stok = stok + VALUES(stok)")
       ->execute([$part_id, $nama_rak, $jumlah]);
}

// Cek apakah "barang keluar" sejumlah $jumlah akan memerlukan transfer
// otomatis dari rak lain ke Rak Utama (karena stok Rak Utama tidak cukup).
// Dipakai utk pre-check/konfirmasi SEBELUM transaksi benar2 disimpan.
// Return: ['perlu_transfer'=>bool, 'kurang'=>int, 'sumber'=>[['nama_rak'=>.., 'ambil'=>int], ...], 'rak_utama_stok'=>int]
function rack_precheck_keluar(PDO $db, int $part_id, int $jumlah): array {
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) {
        return ['perlu_transfer' => false, 'kurang' => 0, 'sumber' => [], 'rak_utama_stok' => null];
    }
    $utama = rack_get_or_create_utama($db, $part_id);
    $stokUtama = (int)$utama['stok'];
    $kurang = $jumlah - $stokUtama;
    if ($kurang <= 0) {
        return ['perlu_transfer' => false, 'kurang' => 0, 'sumber' => [], 'rak_utama_stok' => $stokUtama];
    }
    $lain = $db->prepare("SELECT nama_rak, stok FROM part_racks WHERE part_id=? AND is_utama=0 AND stok > 0 ORDER BY stok DESC, id ASC");
    $lain->execute([$part_id]);
    $sumber = []; $sisa = $kurang;
    foreach ($lain->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($sisa <= 0) break;
        $ambil = min((int)$r['stok'], $sisa);
        if ($ambil <= 0) continue;
        $sumber[] = ['nama_rak' => $r['nama_rak'], 'ambil' => $ambil];
        $sisa -= $ambil;
    }
    return ['perlu_transfer' => true, 'kurang' => $kurang, 'sumber' => $sumber, 'rak_utama_stok' => $stokUtama];
}

// Jalankan pengurangan stok dgn logika "Rak Utama dikurangi duluan, bila
// kurang otomatis tarik dari rak lain (dicatat sbg transfer otomatis)".
// TIDAK mengubah parts.stok (caller tetap melakukan UPDATE parts SET stok=stok-jumlah
// seperti kode lama) - fungsi ini HANYA menata ulang part_racks & mencatat log.
// Return array info transfer otomatis yg terjadi (utk pesan notifikasi/flash).
function rack_deduct_for_keluar(PDO $db, int $part_id, int $jumlah, ?int $user_id = null, string $ket = ''): array {
    if ($jumlah <= 0) return [];
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) return []; // non multi-rak: tidak ada yg perlu dilakukan

    $utama = rack_get_or_create_utama($db, $part_id);
    $stokUtama = (int)$utama['stok'];
    $kurang = $jumlah - $stokUtama;
    $transfers = [];

    if ($kurang > 0) {
        $lain = $db->prepare("SELECT id, nama_rak, stok FROM part_racks WHERE part_id=? AND is_utama=0 AND stok > 0 ORDER BY stok DESC, id ASC FOR UPDATE");
        $lain->execute([$part_id]);
        $sisa = $kurang;
        $insLog = $db->prepare("INSERT INTO rack_transfer_logs (part_id, rak_asal, rak_tujuan, jumlah, tipe, keterangan, user_id) VALUES (?,?,?,?, 'otomatis', ?, ?)");
        foreach ($lain->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($sisa <= 0) break;
            $ambil = min((int)$r['stok'], $sisa);
            if ($ambil <= 0) continue;
            $db->prepare("UPDATE part_racks SET stok = stok - ? WHERE id=?")->execute([$ambil, $r['id']]);
            $db->prepare("UPDATE part_racks SET stok = stok + ? WHERE id=?")->execute([$ambil, $utama['id']]);
            $insLog->execute([$part_id, $r['nama_rak'], $utama['nama_rak'], $ambil, trim("Otomatis (stok keluar). $ket"), $user_id]);
            $transfers[] = ['nama_rak' => $r['nama_rak'], 'jumlah' => $ambil];
            $sisa -= $ambil;
        }
    }
    // Kurangi Rak Utama sejumlah $jumlah (setelah menerima transfer bila ada).
    $db->prepare("UPDATE part_racks SET stok = GREATEST(0, stok - ?) WHERE id=?")->execute([$jumlah, $utama['id']]);
    return $transfers;
}

// "Tambah Rak" - membuat rak baru (atau menambah stok rak yang sudah ada)
// dengan jumlah stok yang diinput LANGSUNG oleh user, TANPA mengambil dari
// Rak Utama dan TANPA perlu Rak Utama punya stok sebanyak itu. Ini dipakai
// utk kasus: etalase/Rak Utama hanya muat 10, sedangkan 30 lainnya memang
// disimpan terpisah di gudang. Jumlah ini menambah TOTAL stok sparepart
// (parts.stok) juga, sekaligus tercatat di riwayat Stok Masuk.
function rack_add_stock_to_rak(PDO $db, int $part_id, string $nama_rak, int $jumlah, ?int $user_id = null, string $ket = ''): void {
    if ($jumlah <= 0) throw new Exception('Jumlah harus lebih dari 0.');
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) throw new Exception('Aktifkan Multi Rak pada sparepart ini terlebih dahulu.');
    $nama_rak = trim($nama_rak);
    if ($nama_rak === '' || $nama_rak === RAK_UTAMA_KEY) throw new Exception('Nama rak tidak valid.');

    $db->prepare("INSERT INTO part_racks (part_id, nama_rak, is_utama, stok) VALUES (?,?,0,?)
                  ON DUPLICATE KEY UPDATE stok = stok + VALUES(stok)")
       ->execute([$part_id, $nama_rak, $jumlah]);
    // Rak Utama TIDAK berkurang - ini stok baru yang menambah total.
    $db->prepare("UPDATE parts SET stok = stok + ? WHERE id=?")->execute([$jumlah, $part_id]);
    $db->prepare("INSERT INTO stock_movements (part_id, tipe, jumlah, ref_type, keterangan) VALUES (?, 'masuk', ?, 'rak_baru', ?)")
       ->execute([$part_id, $jumlah, trim("Stok rak \"$nama_rak\". $ket")]);
}

// Pindahkan stok antar rak secara MANUAL (fitur "Transfer Rak"). Tidak
// mengubah parts.stok (total tidak berubah, hanya lokasi). $rak_asal /
// $rak_tujuan boleh RAK_UTAMA_KEY. $rak_tujuan_baru dipakai bila user
// mengetik nama rak baru (bukan memilih dari daftar yg sudah ada).
// Melempar Exception (pesan siap tampil) bila gagal.
function rack_transfer_manual(PDO $db, int $part_id, string $rak_asal, string $rak_tujuan, int $jumlah, ?int $user_id = null, string $ket = ''): void {
    if ($jumlah <= 0) throw new Exception('Jumlah transfer harus lebih dari 0.');
    $p = $db->prepare("SELECT multi_rak_aktif FROM parts WHERE id=?"); $p->execute([$part_id]);
    if ((int)$p->fetchColumn() !== 1) throw new Exception('Aktifkan Multi Rak pada sparepart ini terlebih dahulu.');

    $utama = rack_get_or_create_utama($db, $part_id);

    $findRak = function (string $nama) use ($db, $part_id, $utama): ?array {
        if ($nama === '' || $nama === RAK_UTAMA_KEY) return $utama;
        $st = $db->prepare("SELECT * FROM part_racks WHERE part_id=? AND nama_rak=? LIMIT 1");
        $st->execute([$part_id, $nama]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    };

    $asal = $findRak($rak_asal);
    if (!$asal) throw new Exception('Rak asal tidak ditemukan.');
    if ((int)$asal['stok'] < $jumlah) throw new Exception("Stok di rak \"{$asal['nama_rak']}\" tidak mencukupi (tersedia {$asal['stok']}).");

    $namaTujuan = trim($rak_tujuan);
    if ($namaTujuan === '' ) throw new Exception('Rak tujuan wajib diisi.');

    if ($namaTujuan === RAK_UTAMA_KEY) {
        $tujuan = $utama;
    } else {
        $db->prepare("INSERT INTO part_racks (part_id, nama_rak, is_utama, stok) VALUES (?,?,0,0)
                      ON DUPLICATE KEY UPDATE nama_rak=nama_rak")
           ->execute([$part_id, $namaTujuan]);
        $tujuan = $findRak($namaTujuan);
    }
    if ($tujuan['id'] === $asal['id']) throw new Exception('Rak asal dan tujuan tidak boleh sama.');

    $db->prepare("UPDATE part_racks SET stok = stok - ? WHERE id=?")->execute([$jumlah, $asal['id']]);
    $db->prepare("UPDATE part_racks SET stok = stok + ? WHERE id=?")->execute([$jumlah, $tujuan['id']]);
    $db->prepare("INSERT INTO rack_transfer_logs (part_id, rak_asal, rak_tujuan, jumlah, tipe, keterangan, user_id) VALUES (?,?,?,?, 'manual', ?, ?)")
       ->execute([$part_id, $asal['nama_rak'], $tujuan['nama_rak'], $jumlah, $ket, $user_id]);
}

// Hapus 1 rak (bukan Rak Utama): stoknya digabung kembali ke Rak Utama.
function rack_delete(PDO $db, int $part_id, string $nama_rak, ?int $user_id = null): void {
    $st = $db->prepare("SELECT * FROM part_racks WHERE part_id=? AND nama_rak=? AND is_utama=0 LIMIT 1");
    $st->execute([$part_id, $nama_rak]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Rak tidak ditemukan / tidak bisa dihapus (Rak Utama tidak dapat dihapus).');
    if ((int)$r['stok'] > 0) {
        rack_transfer_manual($db, $part_id, $r['nama_rak'], RAK_UTAMA_KEY, (int)$r['stok'], $user_id, 'Otomatis saat rak dihapus');
    }
    $db->prepare("DELETE FROM part_racks WHERE id=?")->execute([$r['id']]);
}
