<?php
// ============================================================
// lookup_hsc.php - Proxy pencarian sparepart dari DUA sumber:
//   - hargasukucadang.online (sumber='hsc')
//   - hondacengkareng.com    (sumber='hcg', WooCommerce Store API + harga jual)
// Mengembalikan JSON: { results: [ {kode,nama,harga,harga_num,status,tipe,sumber} ], source }
// Hasil live disimpan/di-cache ke part_catalog lokal (tahan-offline).
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sync_hsc.php';
require_login();
init_db();
header('Content-Type: application/json; charset=utf-8');

$field = $_GET['field'] ?? 'auto';           // auto | nama | kode | tipe
$q     = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['results' => [], 'source' => 'none', 'error' => 'Kata kunci minimal 2 karakter.']);
    exit;
}

// Auto-detect: kalau kata kunci mirip kode part (mengandung digit + uppercase,
// tanpa spasi & lebih dari 3 karakter), utamakan pencarian by kode.
if ($field === 'auto') {
    $noSpace = preg_replace('/\s+/', '', $q);
    $looksLikeCode = ($noSpace === $q)
        && strlen($q) >= 3
        && preg_match('/[0-9]/', $q)
        && preg_match('/^[A-Z0-9\-]+$/i', $q);
    $field = $looksLikeCode ? 'kode' : 'nama';
}

$db = db();
$LIMIT = 60;

// 1) Hasil dari katalog lokal (instan, tahan-offline) — mencakup kedua sumber.
$local = catalog_search($db, $field, $q, $LIMIT);

// 2) Live hargasukucadang.online (POST form).
$hscLive = [];
$payload = ['kodepart' => '--Kode Part--', 'namapart' => '--Nama Part--', 'tipe' => '--Motor--', 'submit' => ''];
if ($field === 'kode')      $payload['kodepart'] = $q;
elseif ($field === 'tipe')  $payload['tipe']     = $q;
else                        $payload['namapart'] = $q;
$html = hsc_fetch('https://hargasukucadang.online/index.php', $payload);
if ($html !== null) {
    foreach (hsc_parse($html) as $r) {
        $r['sumber'] = 'hsc';
        // Parse harga HSC (format "260.500") menjadi numerik untuk auto-fill harga jual.
        $r['harga_num'] = (int)preg_replace('/\D+/', '', (string)($r['harga'] ?? ''));
        $hscLive[] = $r;
    }
    if ($hscLive) { try { catalog_upsert($db, $hscLive, 'hsc'); } catch (Exception $e) {} }
}

// 3) Live hondacengkareng.com (Store API JSON, punya harga jual asli).
$hcgLive = hcg_search_rows($q, $LIMIT); // null bila situs tak terjangkau
if (is_array($hcgLive) && $hcgLive) {
    try { catalog_upsert($db, $hcgLive, 'hcg'); } catch (Exception $e) {}
} else {
    $hcgLive = [];
}

// 4) Gabungkan: interleave (round-robin) hasil live kedua sumber agar KEDUANYA
//    terwakili, lalu tambahkan katalog lokal yang belum muncul. Dedup per (sumber+kode).
$merged = []; $seen = [];
$add = function ($r) use (&$merged, &$seen, $LIMIT) {
    if (count($merged) >= $LIMIT) return;
    $key = ($r['sumber'] ?? 'hsc') . '|' . ($r['kode'] ?? '');
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $merged[] = [
        'kode'      => $r['kode'] ?? '',
        'nama'      => $r['nama'] ?? '',
        'harga'     => $r['harga'] ?? '-',
        'harga_num' => (int)($r['harga_num'] ?? 0),
        'status'    => $r['status'] ?? '',
        'tipe'      => $r['tipe'] ?? '',
        'sumber'    => $r['sumber'] ?? 'hsc',
    ];
};
// Round-robin antar dua sumber live.
$maxLen = max(count($hcgLive), count($hscLive));
for ($i = 0; $i < $maxLen && count($merged) < $LIMIT; $i++) {
    if (isset($hcgLive[$i])) $add($hcgLive[$i]);
    if (isset($hscLive[$i])) $add($hscLive[$i]);
}
// Sisa dari katalog lokal (kedua sumber).
foreach ($local as $r) { if (count($merged) >= $LIMIT) break; $add($r); }

$anyLive = !empty($hscLive) || !empty($hcgLive);
echo json_encode([
    'results' => array_slice($merged, 0, $LIMIT),
    'source'  => $anyLive ? 'live' : 'local',
    'offline' => !$anyLive,
    'error'   => $merged ? null : 'Kedua situs sumber tidak dapat dihubungi dan belum ada data di katalog lokal untuk kata kunci ini.',
]);
exit;

// ------------------------------------------------------------
// Cari di katalog lokal berdasarkan kolom (whitelist), sertakan sumber.
function catalog_search(PDO $db, string $field, string $q, int $limit = 60): array {
    $col = $field === 'kode' ? 'kode' : ($field === 'tipe' ? 'tipe' : 'nama');
    $stmt = $db->prepare("SELECT kode, nama, harga, status, tipe, sumber FROM part_catalog WHERE $col LIKE ? ORDER BY kode LIMIT $limit");
    $stmt->execute(["%$q%"]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        // Turunkan harga_num dari string harga ("260.500" / "Rp 45,000") untuk
        // KEDUA sumber, agar klik hasil pencarian otomatis mengisi harga jual.
        $r['harga_num'] = (int)preg_replace('/\D+/', '', (string)($r['harga'] ?? ''));
    }
    return $rows;
}

// Simpan/segarkan hasil ke katalog lokal (upsert berdasarkan kode) dengan sumber.
function catalog_upsert(PDO $db, array $rows, string $sumber = 'hsc'): void {
    $ins = $db->prepare("INSERT INTO part_catalog (kode, nama, harga, status, tipe, sumber)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), harga=VALUES(harga), status=VALUES(status), tipe=VALUES(tipe), sumber=VALUES(sumber), updated_at=CURRENT_TIMESTAMP");
    foreach ($rows as $r) {
        if (($r['kode'] ?? '') === '') continue;
        $ins->execute([$r['kode'], $r['nama'] ?? '', $r['harga'] ?? '', $r['status'] ?? '', $r['tipe'] ?? '', $r['sumber'] ?? $sumber]);
    }
}

// ------------------------------------------------------------
// Ambil HTML hasil pencarian HSC (POST). Utamakan cURL, fallback file_get_contents.
function hsc_fetch(string $url, array $post): ?string {
    $body = http_build_query($post);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SimbengBengkel/1.0)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res !== false && $code >= 200 && $code < 400) return $res;
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SimbengBengkel/1.0\r\n",
            'content' => $body,
            'timeout' => 8,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res === false ? null : $res;
}

// ------------------------------------------------------------
// Parse tabel hasil HSC (id="customers") menjadi array asosiatif.
function hsc_parse(string $html): array {
    $out  = [];
    if (!class_exists('DOMDocument')) return $out; // ekstensi DOM nonaktif di hosting
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp   = new DOMXPath($doc);
    $rows = $xp->query("//table[@id='customers']/tbody/tr");
    if ($rows === false) return $out;
    foreach ($rows as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 5) continue;
        $get = function ($i) use ($tds) { return trim(preg_replace('/\s+/', ' ', $tds->item($i)->textContent)); };
        $kode = $get(0); $nama = $get(1);
        if ($kode === '' && $nama === '') continue;
        $out[] = ['kode' => $kode, 'nama' => $nama, 'harga' => $get(2), 'status' => $get(3), 'tipe' => $get(4)];
        if (count($out) >= 40) break;
    }
    return $out;
}
