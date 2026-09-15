<?php
// ============================================================
// sync_hsc.php - Sinkronisasi katalog hargasukucadang.online (CLI-safe).
// Dipakai baik oleh cron/supervisor harian, atau dipanggil manual via
// ajax/sync_hsc.php dari tombol "Sync Sekarang" di menu Sparepart.
//
// Strategi refresh (agar mode offline tetap segar):
//  1) Untuk daftar kata kunci umum (oli, busi, kampas, dll.) -> fetch by nama
//  2) Untuk seluruh kode yang sudah ada di part_catalog -> fetch by kode
//     (dibatasi supaya sinkronisasi tidak berlangsung terlalu lama)
// Setelah selesai, catat waktu ke settings.last_hsc_sync (ISO UTC).
// ============================================================
require_once __DIR__ . '/../includes/db.php';

// Batas maksimum kode yang diperbarui per menjalankan supaya cepat & sopan.
if (!defined('HSC_SYNC_MAX_KODE')) define('HSC_SYNC_MAX_KODE', 25);

function hsc_sync_run(?callable $log = null): array {
    init_db();
    $db = db();
    $started = time();
    $inserted = 0; $updated = 0; $totalRows = 0; $errors = []; $partsInserted = 0;

    $keywords = ['oli', 'busi', 'kampas', 'rantai', 'ban', 'shock', 'lampu', 'aki', 'filter', 'gear', 'piston', 'karburator'];

    if ($log) $log('Sync HSC dimulai pada ' . date('Y-m-d H:i:s'));

    // 1) refresh by nama untuk kata kunci umum
    foreach ($keywords as $kw) {
        $rows = hsc_sync_fetch('nama', $kw);
        if ($rows === null) { $errors[] = "Gagal fetch nama=$kw"; continue; }
        $r = hsc_sync_upsert($db, $rows);
        $inserted += $r['inserted']; $updated += $r['updated']; $totalRows += count($rows);
        // Impor otomatis ke tabel sparepart (parts): kode, nama, kategori=keyword.
        $partsInserted += hsc_parts_import($db, $rows, ucfirst($kw));
        if ($log) $log(" - nama=$kw: " . count($rows) . " hasil");
        usleep(300000); // 300ms sopan-santun antar request
    }

    // 2) refresh by kode untuk kode yang paling lama diperbarui
    $kodes = $db->query("SELECT kode FROM part_catalog ORDER BY updated_at ASC LIMIT " . (int)HSC_SYNC_MAX_KODE)
                ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($kodes as $kd) {
        if (mb_strlen($kd) < 2) continue;
        $rows = hsc_sync_fetch('kode', $kd);
        if ($rows === null) { $errors[] = "Gagal fetch kode=$kd"; continue; }
        $r = hsc_sync_upsert($db, $rows);
        $inserted += $r['inserted']; $updated += $r['updated']; $totalRows += count($rows);
        if ($log) $log(" - kode=$kd: " . count($rows) . " hasil");
        usleep(300000);
    }

    // 3) sinkronisasi HCG (hondacengkareng.com) berbasis kata kunci umum.
    $hcg = hcg_sync_keywords($db, $log);
    $totalRows += $hcg['rows'];
    $partsInserted += $hcg['parts_inserted'];
    $inserted += $hcg['catalog'];
    if ($hcg['errors']) $errors[] = "HCG gagal {$hcg['errors']} kata kunci";

    // Simpan waktu sinkronisasi terakhir (UTC ISO).
    set_setting('last_hsc_sync', gmdate('Y-m-d\TH:i:s\Z'));
    set_setting('last_hsc_sync_summary', json_encode([
        'inserted' => $inserted, 'updated' => $updated, 'rows' => $totalRows,
        'parts_inserted' => $partsInserted, 'hcg_rows' => $hcg['rows'], 'hcg_parts' => $hcg['parts_inserted'],
        'errors' => count($errors), 'duration_sec' => time() - $started,
    ]));

    $result = ['inserted' => $inserted, 'updated' => $updated, 'rows' => $totalRows, 'parts_inserted' => $partsInserted, 'hcg_rows' => $hcg['rows'], 'hcg_parts' => $hcg['parts_inserted'], 'errors' => $errors, 'duration_sec' => time() - $started];
    if ($log) $log("Selesai. rows=$totalRows inserted=$inserted updated=$updated errors=" . count($errors));
    return $result;
}

function hsc_sync_fetch(string $field, string $q): ?array {
    $payload = ['kodepart' => '--Kode Part--', 'namapart' => '--Nama Part--', 'tipe' => '--Motor--', 'submit' => ''];
    if ($field === 'kode')      $payload['kodepart'] = $q;
    elseif ($field === 'tipe')  $payload['tipe']     = $q;
    else                        $payload['namapart'] = $q;

    $body = http_build_query($payload);
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://hargasukucadang.online/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SimbengBengkel/1.0)',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $code < 200 || $code >= 400) return null;
    return hsc_sync_parse($html);
}

function hsc_sync_parse(string $html, int $max = 40): array {
    $out  = [];
    if (!class_exists('DOMDocument')) return $out; // ekstensi DOM nonaktif di hosting
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $rows = $xp->query("//table[@id='customers']/tbody/tr");
    if ($rows === false) return $out;
    foreach ($rows as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 5) continue;
        $get = function ($i) use ($tds) { return trim(preg_replace('/\s+/', ' ', $tds->item($i)->textContent)); };
        $kode = $get(0);
        if ($kode === '') continue;
        $out[] = ['kode' => $kode, 'nama' => $get(1), 'harga' => $get(2), 'status' => $get(3), 'tipe' => $get(4)];
        if ($max > 0 && count($out) >= $max) break;
    }
    return $out;
}

function hsc_sync_upsert(PDO $db, array $rows): array {
    $ins = 0; $upd = 0;
    $find = $db->prepare("SELECT id FROM part_catalog WHERE kode = ?");
    $insert = $db->prepare("INSERT INTO part_catalog (kode, nama, harga, status, tipe) VALUES (?,?,?,?,?)");
    $update = $db->prepare("UPDATE part_catalog SET nama=?, harga=?, status=?, tipe=?, updated_at=CURRENT_TIMESTAMP WHERE kode=?");
    foreach ($rows as $r) {
        if (($r['kode'] ?? '') === '') continue;
        $find->execute([$r['kode']]);
        if ($find->fetchColumn()) { $update->execute([$r['nama'], $r['harga'], $r['status'], $r['tipe'], $r['kode']]); $upd++; }
        else                       { $insert->execute([$r['kode'], $r['nama'], $r['harga'], $r['status'], $r['tipe']]); $ins++; }
    }
    return ['inserted' => $ins, 'updated' => $upd];
}

// Impor hasil katalog HSC ke tabel sparepart (parts) proyek ini.
// Hanya kode + nama + kategori yang diisi otomatis; kolom lain (harga, stok)
// dibiarkan default agar diisi manual user sesuai kondisi bengkel.
// INSERT IGNORE: sparepart yang kodenya sudah ada TIDAK ditimpa.
function hsc_parts_import(PDO $db, array $rows, string $kategori = ''): int {
    $count = 0;
    if ($kategori !== '') {
        $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)")->execute([$kategori]);
    }
    $ins = $db->prepare("INSERT IGNORE INTO parts (kode, nama, kategori, harga_beli, harga_jual, stok, stok_min)
                         VALUES (?,?,?,0,0,0,5)");
    foreach ($rows as $r) {
        $kode = trim($r['kode'] ?? '');
        $nama = trim($r['nama'] ?? '');
        if ($kode === '' || $nama === '') continue;
        $ins->execute([$kode, $nama, $kategori]);
        $count += $ins->rowCount(); // 1 bila baru, 0 bila kode sudah ada
    }
    return $count;
}

// Cek apakah waktu sinkronisasi terakhir lebih tua dari $hours jam.
function hsc_sync_stale(int $hours = 24): bool {
    $last = setting('last_hsc_sync', '');
    if ($last === '') return true;
    $ts = strtotime($last);
    if ($ts === false) return true;
    return (time() - $ts) >= ($hours * 3600);
}

// ============================================================
// HONDA CENGKARENG (hondacengkareng.com) — sumber katalog kedua.
// Situs berbasis WooCommerce; datanya diambil lewat Store API JSON:
//   /wp-json/wc/store/v1/products?search=KEYWORD&per_page=N
// Mengembalikan kode (sku), nama, harga jual, status stok, kategori.
// ============================================================

// GET JSON ke Store API HCG. Kembalikan array produk atau null bila gagal.
function hcg_store_api(string $q, int $perPage = 30, int $page = 1): ?array {
    $url = 'https://www.hondacengkareng.com/wp-json/wc/store/v1/products?'
         . http_build_query(['search' => $q, 'per_page' => $perPage, 'page' => $page]);
    $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code < 200 || $code >= 400) return null;
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 12,
            'header' => "Accept: application/json\r\nUser-Agent: $ua\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) return null;
    }
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

// Normalisasi hasil Store API HCG ke bentuk baris seragam katalog.
// harga_num = harga jual numerik (rupiah). status = Tersedia/Habis.
function hcg_normalize(array $products, int $max = 40): array {
    $out = [];
    foreach ($products as $p) {
        $kode = trim((string)($p['sku'] ?? ''));
        $nama = trim((string)($p['name'] ?? ''));
        if ($kode === '' || $nama === '') continue;
        $nama = html_entity_decode($nama, ENT_QUOTES, 'UTF-8');
        $hargaNum = 0;
        if (isset($p['prices']['price'])) $hargaNum = (int)$p['prices']['price'];
        $kategori = '';
        if (!empty($p['categories'][0]['name'])) $kategori = html_entity_decode((string)$p['categories'][0]['name'], ENT_QUOTES, 'UTF-8');
        $status = !empty($p['is_in_stock']) ? 'Tersedia' : 'Habis';
        $out[] = [
            'kode'      => $kode,
            'nama'      => $nama,
            'harga'     => $hargaNum ? ('Rp ' . number_format($hargaNum, 0, ',', '.')) : '-',
            'harga_num' => $hargaNum,
            'status'    => $status,
            'tipe'      => $kategori,
            'sumber'    => 'hcg',
        ];
        if ($max > 0 && count($out) >= $max) break;
    }
    return $out;
}

// Cari sparepart di HCG (dipakai proxy pencarian & sync).
function hcg_search_rows(string $q, int $max = 40): ?array {
    $products = hcg_store_api($q, min(50, max(10, $max)));
    if ($products === null) return null;
    return hcg_normalize($products, $max);
}

// Upsert hasil HCG ke part_catalog (dengan sumber='hcg').
function hcg_catalog_upsert(PDO $db, array $rows): int {
    $ins = $db->prepare("INSERT INTO part_catalog (kode, nama, harga, status, tipe, sumber)
        VALUES (?,?,?,?,?, 'hcg')
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), harga=VALUES(harga), status=VALUES(status), tipe=VALUES(tipe), sumber='hcg', updated_at=CURRENT_TIMESTAMP");
    $n = 0;
    foreach ($rows as $r) {
        if (($r['kode'] ?? '') === '') continue;
        $ins->execute([$r['kode'], $r['nama'], $r['harga'], $r['status'], $r['tipe']]);
        $n++;
    }
    return $n;
}

// Impor hasil HCG ke tabel sparepart (parts). Karena HCG punya harga jual asli,
// sparepart BARU langsung terisi harga_jual otomatis. Sparepart yang kodenya
// sudah ada TIDAK ditimpa (menjaga harga/stok yang mungkin diatur manual).
function hcg_parts_import(PDO $db, array $rows): int {
    $ins = $db->prepare("INSERT IGNORE INTO parts (kode, nama, kategori, harga_beli, harga_jual, stok, stok_min)
                         VALUES (?,?,?,0,?,0,5)");
    $count = 0;
    foreach ($rows as $r) {
        $kode = trim($r['kode'] ?? '');
        $nama = trim($r['nama'] ?? '');
        if ($kode === '' || $nama === '') continue;
        $ins->execute([$kode, $nama, $r['tipe'] ?? '', (int)($r['harga_num'] ?? 0)]);
        $count += $ins->rowCount();
    }
    return $count;
}

// Sinkronisasi HCG berbasis kata kunci umum (dipanggil dari hsc_sync_run).
function hcg_sync_keywords(PDO $db, ?callable $log = null): array {
    $keywords = ['kampas rem', 'oli', 'busi', 'rantai', 'gear', 'lampu', 'aki', 'filter', 'v-belt', 'roller', 'shock', 'ban'];
    $rowsTotal = 0; $partsInserted = 0; $catalog = 0; $errors = 0;
    foreach ($keywords as $kw) {
        $rows = hcg_search_rows($kw, 50);
        if ($rows === null) { $errors++; if ($log) $log(" - HCG $kw: gagal"); continue; }
        $catalog += hcg_catalog_upsert($db, $rows);
        $partsInserted += hcg_parts_import($db, $rows);
        $rowsTotal += count($rows);
        if ($log) $log(" - HCG $kw: " . count($rows) . " hasil");
        usleep(250000);
    }
    return ['rows' => $rowsTotal, 'parts_inserted' => $partsInserted, 'catalog' => $catalog, 'errors' => $errors];
}

// ============================================================
// SYNC SELURUH KATALOG (semua halaman ?ok=ok&cur=N, 25 item/halaman).
// Berjalan lama -> dijalankan sebagai proses latar belakang (CLI) dan
// progress-nya ditulis ke file agar bisa dipantau via polling AJAX.
// ============================================================

// GET request sederhana ke HSC (dipakai untuk pagination katalog).
function hsc_http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SimbengBengkel/1.0)',
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($html !== false && $code >= 200 && $code < 400) return $html;
    }
    // Fallback bila curl tidak tersedia di shared hosting.
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 30,
            'header' => "User-Agent: Mozilla/5.0 (compatible; SimbengBengkel/1.0)\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $html = @file_get_contents($url, false, $ctx);
        if ($html !== false && $html !== '') return $html;
    }
    return null;
}

// Ambil satu halaman katalog (semua baris pada halaman itu, biasanya 25).
function hsc_fetch_page(int $cur): ?array {
    $html = hsc_http_get('https://hargasukucadang.online/index.php?ok=ok&cur=' . $cur);
    if ($html === null) return null;
    return hsc_sync_parse($html, 0); // 0 = tanpa batas baris
}

// Deteksi total halaman dari link paginator (cur=NNNN terbesar).
function hsc_detect_total_pages(): int {
    $html = hsc_http_get('https://hargasukucadang.online/index.php?ok=ok&cur=1');
    if ($html === null) return 0;
    if (preg_match_all('/cur=(\d+)/', $html, $m)) {
        return max(array_map('intval', $m[1]));
    }
    return 0;
}

// Lokasi file kontrol & progress untuk full-sync.
function hsc_full_paths(): array {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return [
        'dir'      => $dir,
        'progress' => $dir . '/hsc_full_progress.json',
        'stop'     => $dir . '/hsc_full_stop',
        'log'      => $dir . '/hsc_full.log',
    ];
}

function hsc_full_write_progress(array $p): void {
    $paths = hsc_full_paths();
    @file_put_contents($paths['progress'], json_encode($p), LOCK_EX);
}

function hsc_full_read_progress(): array {
    $paths = hsc_full_paths();
    if (!is_file($paths['progress'])) return [];
    $j = json_decode((string)@file_get_contents($paths['progress']), true);
    return is_array($j) ? $j : [];
}

// Apakah PID masih hidup?
function hsc_pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (is_dir("/proc/$pid")) return true;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return false;
}

// Apakah job full-sync masih aktif? Berbasis kesegaran progress (cocok untuk
// mode batch di shared hosting yang TANPA proses latar belakang).
function hsc_full_is_active(array $prog, int $staleSec = 45): bool {
    if (empty($prog) || !empty($prog['done'])) return false;
    if (empty($prog['running'])) return false;
    $upd = !empty($prog['updated_at']) ? strtotime($prog['updated_at']) : 0;
    if ($upd && (time() - $upd) > $staleSec) return false; // dianggap terhenti
    return true;
}

// Inisialisasi progress full-sync (dipanggil saat mulai).
function hsc_full_init(int $start = 1): array {
    $totalPages = hsc_detect_total_pages();
    if ($totalPages < 1) $totalPages = 0; // 0 = akan dideteksi ulang di batch pertama
    $prog = [
        'running' => true, 'done' => false, 'page' => max(0, $start - 1),
        'total_pages' => $totalPages, 'catalog_inserted' => 0, 'parts_inserted' => 0,
        'rows' => 0, 'errors' => 0, 'started_at' => gmdate('c'), 'updated_at' => gmdate('c'),
        'error' => '', 'pid' => 0,
    ];
    hsc_full_write_progress($prog);
    return $prog;
}

// Proses SATU batch halaman (dipanggil berulang oleh browser). Aman untuk
// shared hosting: berhenti setelah $batch halaman atau $time_budget detik.
function hsc_sync_batch(array $opt = []): array {
    init_db();
    $db    = db();
    $paths = hsc_full_paths();
    $prog  = hsc_full_read_progress();
    if (empty($prog)) $prog = hsc_full_init((int)($opt['start'] ?? 1));

    // Permintaan berhenti.
    if (is_file($paths['stop'])) {
        @unlink($paths['stop']);
        $prog['running'] = false; $prog['done'] = true; $prog['error'] = 'dihentikan';
        $prog['updated_at'] = gmdate('c');
        hsc_full_write_progress($prog);
        return $prog;
    }

    $totalPages = (int)($prog['total_pages'] ?? 0);
    if ($totalPages < 1) { $totalPages = hsc_detect_total_pages(); if ($totalPages < 1) $totalPages = 1; }

    $catNew    = (int)($prog['catalog_inserted'] ?? 0);
    $partsNew  = (int)($prog['parts_inserted'] ?? 0);
    $rowsTotal = (int)($prog['rows'] ?? 0);
    $errors    = (int)($prog['errors'] ?? 0);
    $started   = !empty($prog['started_at']) ? strtotime($prog['started_at']) : time();

    $batch      = max(1, (int)($opt['batch'] ?? 8));
    $timeBudget = max(3, (int)($opt['time_budget'] ?? 15));
    $sleepMs    = max(0, (int)($opt['sleep_ms'] ?? 60));
    $tStart     = microtime(true);

    $insCat = $db->prepare("INSERT INTO part_catalog (kode,nama,harga,status,tipe) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), harga=VALUES(harga), status=VALUES(status), tipe=VALUES(tipe), updated_at=CURRENT_TIMESTAMP");
    $insPart = $db->prepare("INSERT IGNORE INTO parts (kode,nama,kategori,harga_beli,harga_jual,stok,stok_min) VALUES (?,?, '',0,0,0,5)");

    $page      = max(1, (int)($prog['page'] ?? 0) + 1);
    $lastDone  = (int)($prog['page'] ?? 0);
    $processed = 0;
    $stopped   = false;

    while ($page <= $totalPages) {
        if (is_file($paths['stop'])) { @unlink($paths['stop']); $stopped = true; break; }
        $rows = hsc_fetch_page($page);
        if ($rows === null) {
            $errors++;
        } else {
            try {
                $db->beginTransaction();
                foreach ($rows as $r) {
                    if (($r['kode'] ?? '') === '') continue;
                    $insCat->execute([$r['kode'], $r['nama'], $r['harga'], $r['status'], $r['tipe']]);
                    if ($insCat->rowCount() === 1) $catNew++;
                    $insPart->execute([$r['kode'], $r['nama']]);
                    $partsNew += $insPart->rowCount();
                    $rowsTotal++;
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors++;
            }
        }
        $lastDone = $page;
        $processed++;
        $page++;
        if ($processed >= $batch) break;
        if ((microtime(true) - $tStart) >= $timeBudget) break;
        if ($sleepMs) usleep($sleepMs * 1000);
    }

    $done = $stopped || ($lastDone >= $totalPages);
    $prog = [
        'running' => !$done, 'done' => $done, 'page' => $lastDone, 'total_pages' => $totalPages,
        'catalog_inserted' => $catNew, 'parts_inserted' => $partsNew, 'rows' => $rowsTotal,
        'errors' => $errors, 'started_at' => gmdate('c', $started), 'updated_at' => gmdate('c'),
        'error' => $stopped ? 'dihentikan' : '', 'pid' => 0,
    ];
    hsc_full_write_progress($prog);

    if ($done && !$stopped) {
        set_setting('last_hsc_sync', gmdate('Y-m-d\TH:i:s\Z'));
        set_setting('last_hsc_full_summary', json_encode([
            'pages' => $totalPages, 'rows' => $rowsTotal, 'parts_inserted' => $partsNew,
            'catalog_inserted' => $catNew, 'errors' => $errors, 'duration_sec' => time() - $started,
        ]));
    }
    return $prog;
}

// Jalankan sinkronisasi SELURUH katalog. Menulis progress tiap halaman.
// $opt: ['start'=>int, 'total_pages'=>int, 'sleep_ms'=>int]
function hsc_sync_full(?callable $log = null, array $opt = []): array {
    init_db();
    $db    = db();
    $paths = hsc_full_paths();
    @unlink($paths['stop']);

    $totalPages = (int)($opt['total_pages'] ?? 0);
    if ($totalPages < 1) $totalPages = hsc_detect_total_pages();
    if ($totalPages < 1) $totalPages = 1;
    $start   = max(1, (int)($opt['start'] ?? 1));
    $sleepMs = max(0, (int)($opt['sleep_ms'] ?? 120));
    $started = time();

    $catNew = 0; $partsNew = 0; $rowsTotal = 0; $errors = 0;

    $insCat = $db->prepare("INSERT INTO part_catalog (kode,nama,harga,status,tipe) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), harga=VALUES(harga), status=VALUES(status), tipe=VALUES(tipe), updated_at=CURRENT_TIMESTAMP");
    $insPart = $db->prepare("INSERT IGNORE INTO parts (kode,nama,kategori,harga_beli,harga_jual,stok,stok_min) VALUES (?,?, '',0,0,0,5)");

    $writeProg = function (int $page, bool $done = false, string $err = '') use (&$catNew, &$partsNew, &$rowsTotal, &$errors, $totalPages, $started) {
        hsc_full_write_progress([
            'running'          => !$done,
            'done'             => $done,
            'page'             => $page,
            'total_pages'      => $totalPages,
            'catalog_inserted' => $catNew,
            'parts_inserted'   => $partsNew,
            'rows'             => $rowsTotal,
            'errors'           => $errors,
            'started_at'       => gmdate('c', $started),
            'updated_at'       => gmdate('c'),
            'error'            => $err,
            'pid'              => getmypid(),
        ]);
    };

    if ($log) $log("Full sync mulai. total_pages=$totalPages start=$start");
    $writeProg($start - 1);

    for ($cur = $start; $cur <= $totalPages; $cur++) {
        if (is_file($paths['stop'])) {
            if ($log) $log("STOP diminta pada halaman $cur");
            @unlink($paths['stop']);
            $writeProg($cur - 1, true, 'dihentikan');
            break;
        }
        $rows = hsc_fetch_page($cur);
        if ($rows === null) {
            $errors++;
            if ($log) $log("Halaman $cur gagal diambil");
            $writeProg($cur);
            if ($sleepMs) usleep($sleepMs * 1000);
            continue;
        }
        try {
            $db->beginTransaction();
            foreach ($rows as $r) {
                if (($r['kode'] ?? '') === '') continue;
                $insCat->execute([$r['kode'], $r['nama'], $r['harga'], $r['status'], $r['tipe']]);
                if ($insCat->rowCount() === 1) $catNew++;   // 1=insert, 2=update
                $insPart->execute([$r['kode'], $r['nama']]);
                $partsNew += $insPart->rowCount();           // 1 bila baru, 0 bila kode sudah ada
                $rowsTotal++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors++;
            if ($log) $log("Halaman $cur error DB: " . $e->getMessage());
        }
        if ($log && $cur % 50 === 0) $log("Halaman $cur/$totalPages rows=$rowsTotal partsBaru=$partsNew errors=$errors");
        $writeProg($cur, $cur >= $totalPages);
        if ($cur < $totalPages && $sleepMs) usleep($sleepMs * 1000);
    }

    set_setting('last_hsc_sync', gmdate('Y-m-d\TH:i:s\Z'));
    set_setting('last_hsc_full_summary', json_encode([
        'pages' => $totalPages, 'rows' => $rowsTotal, 'parts_inserted' => $partsNew,
        'catalog_inserted' => $catNew, 'errors' => $errors, 'duration_sec' => time() - $started,
    ]));
    if ($log) $log("Full sync selesai. rows=$rowsTotal partsBaru=$partsNew catalogBaru=$catNew errors=$errors durasi=" . (time() - $started) . "s");

    return ['pages' => $totalPages, 'rows' => $rowsTotal, 'parts_inserted' => $partsNew, 'catalog_inserted' => $catNew, 'errors' => $errors, 'duration_sec' => time() - $started];
}
