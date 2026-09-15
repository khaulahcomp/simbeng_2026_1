<?php
// ============================================================
// schema.php - Instalasi & migrasi skema database (BUKAN runtime).
// Fungsi run_full_migration() menjalankan seluruh CREATE TABLE,
// migrasi kolom, index, dan seed default. Bersifat idempotent &
// aman dijalankan berkali-kali, TETAPI tidak dipanggil pada setiap
// request pengguna (lihat init_db() di db.php yang memakai gerbang
// versi skema). Panggil manual via migrate.php saat maintenance.
// ============================================================

// Tambah index bila belum ada (kompatibel MySQL 5.7/8 & MariaDB).
function add_index_if_missing(PDO $db, string $table, string $index, string $cols): void {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$table, $index]);
        if ((int)$st->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `$table` ADD INDEX `$index` ($cols)");
        }
    } catch (\Throwable $e) {
        if (function_exists('app_log')) app_log('error', "add_index $table.$index gagal: " . $e->getMessage());
    }
}

function column_exists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

function run_full_migration(): void {
    $db = db();
    $eng = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nama VARCHAR(150) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'kasir',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        telepon VARCHAR(40) NOT NULL DEFAULT '',
        alamat VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        merek VARCHAR(100) NOT NULL,
        model VARCHAR(100) NOT NULL DEFAULT '',
        plat_nomor VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id),
        CONSTRAINT fk_vehicles_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        telepon VARCHAR(40) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        alamat VARCHAR(500) NOT NULL DEFAULT '',
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        barcode VARCHAR(100) NOT NULL DEFAULT '',
        nama VARCHAR(200) NOT NULL,
        kategori VARCHAR(150) NOT NULL DEFAULT '',
        harga_beli DECIMAL(14,2) NOT NULL DEFAULT 0,
        harga_jual DECIMAL(14,2) NOT NULL DEFAULT 0,
        stok INT NOT NULL DEFAULT 0,
        stok_min INT NOT NULL DEFAULT 5,
        lokasi_rak VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");
    if (!column_exists($db, 'parts', 'lokasi_rak')) {
        $db->exec("ALTER TABLE parts ADD COLUMN lokasi_rak VARCHAR(50) NOT NULL DEFAULT '' AFTER stok_min");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        part_id INT NOT NULL,
        tipe VARCHAR(10) NOT NULL,
        jumlah INT NOT NULL,
        supplier_id INT NULL,
        ref_type VARCHAR(30) NOT NULL DEFAULT '',
        ref_id INT NULL,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (part_id), INDEX (supplier_id),
        CONSTRAINT fk_sm_part FOREIGN KEY (part_id) REFERENCES parts(id),
        CONSTRAINT fk_sm_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        no_nota VARCHAR(100) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        vehicle_id INT NULL,
        total_jasa DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_part DECIMAL(14,2) NOT NULL DEFAULT 0,
        diskon DECIMAL(14,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'selesai',
        metode_bayar VARCHAR(20) NOT NULL DEFAULT 'cash',
        catatan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id), INDEX (vehicle_id),
        CONSTRAINT fk_trx_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_trx_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ) $eng");
    if (!column_exists($db, 'transactions', 'metode_bayar')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN metode_bayar VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER status");
    }
    if (!column_exists($db, 'transactions', 'diskon')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN diskon DECIMAL(14,2) NOT NULL DEFAULT 0");
    }
    // Status pembayaran faktur (lunas / belum) + jatuh tempo (pengganti fungsi invoice)
    if (!column_exists($db, 'transactions', 'status_bayar')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN status_bayar VARCHAR(20) NOT NULL DEFAULT 'lunas' AFTER metode_bayar");
    }
    if (!column_exists($db, 'transactions', 'jatuh_tempo')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN jatuh_tempo VARCHAR(20) NULL AFTER status_bayar");
    }
    // Diskon nota: jenis (nominal/persen) + nilai asli, agar cetakan faktur dapat menampilkan persen (%).
    if (!column_exists($db, 'transactions', 'diskon_jenis')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN diskon_jenis VARCHAR(10) NOT NULL DEFAULT '' AFTER diskon");
    }
    if (!column_exists($db, 'transactions', 'diskon_nilai')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN diskon_nilai DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER diskon_jenis");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        tipe VARCHAR(10) NOT NULL,
        part_id INT NULL,
        nama VARCHAR(200) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        harga DECIMAL(14,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        garansi_hari INT NOT NULL DEFAULT 0,
        INDEX (transaction_id), INDEX (part_id),
        CONSTRAINT fk_ti_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
        CONSTRAINT fk_ti_part FOREIGN KEY (part_id) REFERENCES parts(id)
    ) $eng");
    // Diskon per item (nominal Rp / persen %). diskon = nominal potongan terhitung.
    if (!column_exists($db, 'transaction_items', 'diskon_jenis')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN diskon_jenis VARCHAR(10) NOT NULL DEFAULT '' AFTER harga");
    }
    if (!column_exists($db, 'transaction_items', 'diskon_nilai')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN diskon_nilai DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER diskon_jenis");
    }
    if (!column_exists($db, 'transaction_items', 'diskon')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN diskon DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER diskon_nilai");
    }
    // Special Price per item sparepart (harga khusus dari owner utk konsumen tertentu).
    // 0/NULL = tidak dipakai (harga jual normal). Bila diisi, menggantikan harga satuan.
    if (!column_exists($db, 'transaction_items', 'special_price')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN special_price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER harga");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS warranty_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        transaction_id INT NOT NULL,
        transaction_item_id INT NOT NULL,
        customer_id INT NOT NULL,
        item_nama VARCHAR(200) NOT NULL,
        tgl_beli VARCHAR(20) NOT NULL,
        tgl_berakhir VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        alasan VARCHAR(500) NOT NULL DEFAULT '',
        catatan_teknisi VARCHAR(500) NOT NULL DEFAULT '',
        replacement_part_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (transaction_id), INDEX (transaction_item_id), INDEX (customer_id), INDEX (replacement_part_id),
        CONSTRAINT fk_wc_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
        CONSTRAINT fk_wc_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id),
        CONSTRAINT fk_wc_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_wc_part FOREIGN KEY (replacement_part_id) REFERENCES parts(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL UNIQUE,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT NULL
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        isi TEXT NOT NULL,
        warna VARCHAR(20) NOT NULL DEFAULT 'kuning',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS part_catalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        nama VARCHAR(255) NOT NULL DEFAULT '',
        harga VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(50) NOT NULL DEFAULT '',
        tipe VARCHAR(100) NOT NULL DEFAULT '',
        sumber VARCHAR(10) NOT NULL DEFAULT 'hsc',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (nama), INDEX (tipe)
    ) $eng");
    // Sumber katalog: 'hsc' (hargasukucadang.online) / 'hcg' (hondacengkareng.com)
    if (!column_exists($db, 'part_catalog', 'sumber')) {
        $db->exec("ALTER TABLE part_catalog ADD COLUMN sumber VARCHAR(10) NOT NULL DEFAULT 'hsc' AFTER tipe");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS import_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL DEFAULT '',
        mode VARCHAR(20) NOT NULL DEFAULT 'upgrade',
        total INT NOT NULL DEFAULT 0,
        inserted INT NOT NULL DEFAULT 0,
        updated INT NOT NULL DEFAULT 0,
        skipped INT NOT NULL DEFAULT 0,
        invalid INT NOT NULL DEFAULT 0,
        user_id INT NULL,
        user_nama VARCHAR(150) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_at)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS debts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jenis VARCHAR(10) NOT NULL DEFAULT 'piutang',
        pihak VARCHAR(200) NOT NULL,
        telepon VARCHAR(40) NOT NULL DEFAULT '',
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        jumlah DECIMAL(14,2) NOT NULL DEFAULT 0,
        dibayar DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'belum_lunas',
        tgl VARCHAR(20) NOT NULL DEFAULT '',
        jatuh_tempo VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (jenis), INDEX (status)
    ) $eng");
    if (!column_exists($db, 'debts', 'telepon')) {
        $db->exec("ALTER TABLE debts ADD COLUMN telepon VARCHAR(40) NOT NULL DEFAULT '' AFTER pihak");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS debt_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        debt_id INT NOT NULL,
        jumlah DECIMAL(14,2) NOT NULL DEFAULT 0,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        tgl VARCHAR(20) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (debt_id),
        CONSTRAINT fk_dp_debt FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE CASCADE
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS cash_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipe VARCHAR(10) NOT NULL DEFAULT 'keluar',
        kategori VARCHAR(150) NOT NULL DEFAULT '',
        jumlah DECIMAL(14,2) NOT NULL DEFAULT 0,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        tgl VARCHAR(20) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (tipe), INDEX (tgl)
    ) $eng");

    // ---- Index tambahan untuk performa data besar (ratusan ribu+ record) ----
    // Range tanggal & filter status transaksi (dashboard, laporan, keuangan).
    add_index_if_missing($db, 'transactions', 'idx_trx_created_at', 'created_at');
    add_index_if_missing($db, 'transactions', 'idx_trx_status_created', 'status, created_at');
    // Pencarian sparepart POS/Stok (barcode exact & nama prefix).
    add_index_if_missing($db, 'parts', 'idx_parts_barcode', 'barcode');
    add_index_if_missing($db, 'parts', 'idx_parts_nama', 'nama');
    // Klaim garansi aktif (dashboard) & riwayat pergerakan stok.
    add_index_if_missing($db, 'warranty_claims', 'idx_wc_status', 'status');
    add_index_if_missing($db, 'stock_movements', 'idx_sm_created_at', 'created_at');
    add_index_if_missing($db, 'debts', 'idx_debts_status_jt', 'status, jatuh_tempo');

    // FULLTEXT untuk pencarian nama sparepart yang skalabel (MATCH..AGAINST,
    // termasuk kata di tengah nama) tanpa full table scan LIKE '%kata%'.
    try {
        $ft = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parts' AND INDEX_NAME='ft_parts_nama'");
        $ft->execute();
        if ((int)$ft->fetchColumn() === 0) {
            $db->exec("ALTER TABLE parts ADD FULLTEXT INDEX ft_parts_nama (nama)");
        }
    } catch (\Throwable $e) {
        if (function_exists('app_log')) app_log('error', 'FULLTEXT ft_parts_nama gagal: ' . $e->getMessage());
    }

    // ---- Kolom terhitung untuk stok menipis (dashboard cepat di data besar) ----
    // Aman & backward-compatible: bila host tak mendukung generated column,
    // dashboard otomatis fallback ke perbandingan kolom biasa.
    if (!column_exists($db, 'parts', 'is_low_stock')) {
        try {
            $db->exec("ALTER TABLE parts ADD COLUMN is_low_stock TINYINT(1)
                       AS (CASE WHEN stok <= stok_min THEN 1 ELSE 0 END) STORED");
            add_index_if_missing($db, 'parts', 'idx_parts_low_stock', 'is_low_stock');
        } catch (\Throwable $e) {
            if (function_exists('app_log')) app_log('error', 'generated col is_low_stock gagal: ' . $e->getMessage());
        }
    } else {
        add_index_if_missing($db, 'parts', 'idx_parts_low_stock', 'is_low_stock');
    }

    // ---- Seed data default (idempotent) ----
    if ((int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, nama, role) VALUES (?,?,?,?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrator', 'admin']);
    }

    if ((int) $db->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
        $defaults = ['Oli', 'Kampas Rem', 'Busi', 'Aki', 'Ban', 'Rantai & Gir', 'Lampu', 'Lainnya'];
        $existing = $db->query("SELECT DISTINCT kategori FROM parts WHERE kategori != ''")->fetchAll(PDO::FETCH_COLUMN);
        $ins = $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)");
        foreach (array_unique(array_merge($defaults, $existing)) as $k) $ins->execute([$k]);
    }

    $setting_defaults = [
        'nama_bengkel' => 'Bengkel Motor',
        'nib'          => '',
        'pemilik'      => '',
        'alamat'       => 'Jl. Contoh No. 1',
        'telepon'      => '0812-3456-7890',
        'logo'         => '',
        'theme_h1'     => '210',
        'theme_h2'     => '232',
        'menu_position'=> 'top',
        'menu_order'   => '',
    ];
    $ins = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach ($setting_defaults as $k => $v) $ins->execute([$k, $v]);
}
