<?php
// ============================================================
// purchase_order.php - Helper fitur PURCHASE ORDER (Bon Belanja ke supplier).
//
// - Khusus role admin (tab "Purchase Order" di halaman Supplier).
// - PO TIDAK memuat harga dan TIDAK mengubah stok/keuangan.
// - Saat barang datang, PO diteruskan ke form Stok Masuk (page=stock&from_po=ID)
//   sehingga penyimpanan memakai logika Stok Masuk yang sama persis
//   (stok, riwayat stok, multi rak, faktur tagihan, hutang).
// - Jumlah "diterima" dihitung dari faktur Stok Masuk yang terhubung ke PO
//   (tabel purchase_order_receipts), jadi otomatis ikut bila faktur
//   tersebut dikoreksi atau dibatalkan.
//
// Skema: 3 tabel BARU (additive). Tidak ada ALTER/DROP pada tabel lama.
// Dibuat otomatis sekali (CREATE TABLE IF NOT EXISTS) saat fitur ini
// pertama kali dibuka, ditandai setting 'po_schema_version'.
// ============================================================

const PO_SCHEMA_VERSION = '1';

function po_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('po_schema_version', '') === PO_SCHEMA_VERSION) return;
    $eng = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        no_po VARCHAR(50) NOT NULL,
        supplier_id INT NOT NULL,
        tgl VARCHAR(20) NOT NULL DEFAULT '',
        catatan VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        user_nama VARCHAR(150) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        closed_at DATETIME NULL,
        closed_by VARCHAR(150) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_po_no (no_po),
        INDEX idx_po_supplier (supplier_id, tgl)
    ) $eng");
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        part_id INT NULL,
        kode VARCHAR(100) NOT NULL DEFAULT '',
        nama VARCHAR(200) NOT NULL DEFAULT '',
        qty INT NOT NULL DEFAULT 1,
        catatan VARCHAR(255) NOT NULL DEFAULT '',
        INDEX idx_poi_po (po_id),
        INDEX idx_poi_part (part_id),
        CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
    ) $eng");
    $db->exec("CREATE TABLE IF NOT EXISTS purchase_order_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        invoice_id INT NOT NULL,
        user_nama VARCHAR(150) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_por_invoice (invoice_id),
        INDEX idx_por_po (po_id),
        CONSTRAINT fk_por_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
    ) $eng");
    set_setting('po_schema_version', PO_SCHEMA_VERSION);
}

function po_is_admin(): bool {
    return ((current_user()['role'] ?? '') === 'admin');
}

function po_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT po.*, s.nama AS supplier_nama, s.telepon AS supplier_telepon, s.alamat AS supplier_alamat
        FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id WHERE po.id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Item PO beserta jumlah yang sudah diterima (dari faktur Stok Masuk yang terhubung)
 * dan sisa yang belum datang. Item manual (part_id NULL) selalu diterima 0.
 */
function po_items(PDO $db, int $po_id): array {
    $st = $db->prepare("SELECT i.*, p.stok AS part_stok, p.stok_min AS part_stok_min, p.harga_beli AS part_harga_beli,
            p.kode AS part_kode, p.nama AS part_nama,
            (SELECT COALESCE(SUM(sii.qty),0) FROM purchase_order_receipts r
               JOIN supplier_invoice_items sii ON sii.invoice_id = r.invoice_id
              WHERE r.po_id = i.po_id AND i.part_id IS NOT NULL AND sii.part_id = i.part_id) AS diterima
        FROM purchase_order_items i LEFT JOIN parts p ON p.id = i.part_id
        WHERE i.po_id=? ORDER BY i.id");
    $st->execute([$po_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['diterima'] = (int)$r['diterima'];
        $r['sisa'] = max(0, (int)$r['qty'] - $r['diterima']);
    }
    return $rows;
}

/** Faktur Stok Masuk yang terbentuk dari PO (faktur yang dibatalkan otomatis hilang). */
function po_receipts(PDO $db, int $po_id): array {
    $st = $db->prepare("SELECT r.invoice_id, r.user_nama, r.created_at, si.no_faktur, si.no_faktur_supplier, si.total,
            (SELECT COALESCE(SUM(qty),0) FROM supplier_invoice_items WHERE invoice_id = si.id) AS qty
        FROM purchase_order_receipts r JOIN supplier_invoices si ON si.id = r.invoice_id
        WHERE r.po_id=? ORDER BY r.id");
    $st->execute([$po_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Status tampilan PO.
 *  selesai  = semua qty sudah diterima
 *  ditutup  = ditutup manual oleh admin sebelum semua datang
 *  sebagian = sebagian sudah diterima
 *  menunggu = belum ada barang diterima
 */
function po_status_key(string $status, int $q_total, int $q_rcv): string {
    if ($q_total > 0 && $q_rcv >= $q_total) return 'selesai';
    if ($status === 'closed') return 'ditutup';
    if ($q_rcv > 0) return 'sebagian';
    return 'menunggu';
}

function po_status_badge(string $key): string {
    $map = [
        'menunggu' => ['bg-warning text-dark', 'Menunggu barang'],
        'sebagian' => ['bg-primary', 'Diterima sebagian'],
        'selesai'  => ['bg-success', 'Selesai'],
        'ditutup'  => ['bg-secondary', 'Ditutup'],
    ];
    [$cls, $label] = $map[$key] ?? $map['menunggu'];
    return '<span class="badge ' . $cls . '" data-testid="po-status">' . $label . '</span>';
}

/** Ringkasan status satu PO (dipakai sebelum menerima barang, dsb). */
function po_summary(PDO $db, int $po_id, ?array $po = null): array {
    $po = $po ?: po_get($db, $po_id);
    $items = po_items($db, $po_id);
    $q_total = 0; $q_rcv = 0; $manual_open = 0; $linked_open = 0;
    foreach ($items as $it) {
        $q_total += (int)$it['qty'];
        $q_rcv += min((int)$it['qty'], $it['diterima']);
        if ($it['sisa'] > 0) { if ($it['part_id']) $linked_open++; else $manual_open++; }
    }
    return ['po' => $po, 'items' => $items, 'q_total' => $q_total, 'q_rcv' => $q_rcv,
        'manual_open' => $manual_open, 'linked_open' => $linked_open,
        'key' => $po ? po_status_key((string)$po['status'], $q_total, $q_rcv) : 'menunggu'];
}

/**
 * Dipanggil dari Stok Masuk (DI DALAM transaksi penyimpanan faktur) untuk
 * menghubungkan faktur yang baru dibuat ke PO asalnya.
 */
function po_link_invoice(PDO $db, int $po_id, int $invoice_id): void {
    $db->prepare("DELETE FROM purchase_order_receipts WHERE invoice_id=?")->execute([$invoice_id]); // sisa tautan lama (jaga-jaga)
    $db->prepare("INSERT INTO purchase_order_receipts (po_id, invoice_id, user_nama) VALUES (?,?,?)")
       ->execute([$po_id, $invoice_id, (string)(current_user()['nama'] ?? '')]);
    $db->prepare("UPDATE purchase_orders SET updated_at=NOW() WHERE id=?")->execute([$po_id]);
}
