<?php
// ============================================================
// config.php — Pengaturan koneksi database MySQL.
// EDIT nilai di bawah sesuai data dari cPanel / phpMyAdmin / XAMPP.
// ============================================================
//
// Cara mengisi:
//  - XAMPP (lokal): host 'localhost', user 'root', pass '' (kosong),
//    name 'bengkel', auto_create_database true (database dibuat otomatis).
//  - cPanel / shared hosting: buat database + user lewat menu
//    "MySQL Databases", lalu isi name/user/pass sesuai yang dibuat
//    (biasanya berawalan nama akun, mis. 'namauser_bengkel'),
//    dan set auto_create_database = false.
//
// Catatan: nilai getenv(...) hanya dipakai bila environment variable diset
// (untuk kebutuhan hosting tertentu). Jika tidak, nilai default di kanan
// tanda ?: yang dipakai — silakan ubah nilai default itu.

return [
    'host'    => getenv('DB_HOST') ?: '127.0.0.1',
    'port'    => getenv('DB_PORT') ?: '3306',
    'name'    => getenv('DB_NAME') ?: 'bengkel',
    'user'    => getenv('DB_USER') ?: 'root',
    'pass'    => (getenv('DB_PASS') !== false) ? getenv('DB_PASS') : '',
    'charset' => 'utf8mb4',

    // true  = coba buat database otomatis bila belum ada (cocok untuk XAMPP).
    // false = database sudah dibuat manual (wajib untuk cPanel shared hosting).
    'auto_create_database' => (getenv('DB_AUTO_CREATE') === false) ? true : (getenv('DB_AUTO_CREATE') === '1'),
];
