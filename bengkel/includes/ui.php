<?php
// ============================================================
// ui.php - Ikon menu bernuansa OTOMOTIF (khusus tampilan).
// Tidak menyentuh database / logika aplikasi. Data menu tetap dari
// bengkel_nav_items(); file ini hanya memilih ikon yang ditampilkan.
// Bila sebuah menu tidak ada di peta, ikon Bootstrap bawaan dipakai.
// ============================================================

/** page => [ikon Font Awesome, animasi saat hover, kelompok warna] */
function ui_icon_map(): array {
    return [
        'dashboard'    => ['fa-gauge-high',         'needle', 'ops'],   // spidometer
        'pos'          => ['fa-screwdriver-wrench', 'twist',  'ops'],   // servis
        'transactions' => ['fa-file-invoice',       'pop',    'ops'],
        'reports'      => ['fa-chart-line',         'pop',    'money'],
        'finance'      => ['fa-wallet',             'pop',    'money'],
        'debts'        => ['fa-hand-holding-dollar','pop',    'money'],
        'charts'       => ['fa-chart-column',       'pop',    'money'],
        'customers'    => ['fa-motorcycle',         'rev',    'ops'],   // pemilik motor
        'parts'        => ['fa-gears',              'spin',   'stock'], // sparepart
        'parts_bulk'   => ['fa-tags',               'pop',    'stock'],
        'categories'   => ['fa-layer-group',        'pop',    'stock'],
        'stock'        => ['fa-truck-ramp-box',     'drive',  'stock'],
        'racks'        => ['fa-warehouse',          'pop',    'stock'],
        'suppliers'    => ['fa-truck-fast',         'drive',  'stock'],
        'warranty'     => ['fa-shield-halved',      'pop',    'ops'],
        'notes'        => ['fa-note-sticky',        'pop',    'misc'],
        'users'        => ['fa-user-gear',          'spin',   'admin'],
        'settings'     => ['fa-gear',               'spin',   'admin'],
        'logout'       => ['fa-power-off',          'pop',    'misc'],  // matikan kontak
    ];
}

/** HTML ikon menu untuk halaman $page (fallback: ikon Bootstrap $bi_class). */
function ui_nav_icon(string $page, string $bi_class = '', string $extra = ''): string {
    $m = ui_icon_map()[$page] ?? null;
    $extra = $extra !== '' ? ' ' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') : '';
    if ($m) {
        return '<i class="fa-solid ' . $m[0] . ' nav-ic ic-' . $m[1] . ' g-' . $m[2] . $extra . '" aria-hidden="true"></i>';
    }
    return '<i class="bi ' . htmlspecialchars($bi_class, ENT_QUOTES, 'UTF-8') . $extra . '" aria-hidden="true"></i>';
}

/** Versi file aset (cache-busting otomatis saat file diubah). */
function ui_asset(string $rel): string {
    $f = __DIR__ . '/../' . $rel;
    return $rel . (is_file($f) ? '?v=' . filemtime($f) : '');
}
