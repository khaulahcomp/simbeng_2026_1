<?php $user = current_user(); $flash = get_flash();
// Tema gradasi dari pengaturan (warna dasar aplikasi)
$th1 = (int)setting('theme_h1', '210');
$th2 = (int)setting('theme_h2', '232');
$app_name = setting('nama_bengkel', 'Bengkel Motor');
$menu_position = setting('menu_position', 'top');
if (!in_array($menu_position, ['top', 'left', 'right'], true)) $menu_position = 'top';
$is_admin = (($user['role'] ?? '') === 'admin');
$logo = setting('logo');
$has_logo = $logo && is_file(__DIR__ . '/../' . $logo);
// Daftar menu: [page, href, icon, label, admin_only] — urutan diatur di Pengaturan
$nav_items = bengkel_nav_items_ordered();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title ?? $app_name) ?> - <?= esc($app_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  html, body { overflow-x: hidden; }
  body { background: #f4f6f9; }
  .sidebar { min-height: 100vh; background: linear-gradient(165deg, hsl(<?= $th1 ?> 60% 18%) 0%, hsl(<?= $th2 ?> 65% 32%) 100%); }
  .sidebar .nav-link { color: #b8c4d0; border-radius: 8px; margin: 2px 8px; }
  .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,.08); }
  .sidebar .nav-link.active { color: #fff; background: #0d6efd; }
  .stat-card { border: 0; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
  .table-card { border: 0; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
  /* --- Menu posisi ATAS (top navbar) --- */
  .topnav { background: linear-gradient(90deg, hsl(<?= $th1 ?> 60% 18%), hsl(<?= $th2 ?> 65% 32%)); box-shadow: 0 2px 10px rgba(0,0,0,.12); }
  .topnav .navbar-brand { font-size: 1.1rem; color: #fff; }
  .topnav .nav-link { color: #cfd8e3; border-radius: 8px; padding: .3rem .65rem; margin: 2px; font-size: .82rem; }
  .topnav .nav-link:hover { color: #fff; background: rgba(255,255,255,.10); }
  .topnav .nav-link.active { color: #fff; background: #0d6efd; }
  .topnav .navbar-toggler { border-color: rgba(255,255,255,.5); color:#fff; }
  .topnav .navbar-toggler-icon { filter: invert(1) grayscale(1); }
  /* --- Off-canvas sidebar untuk smartphone (<768px). Desktop/tablet tidak berubah. --- */
  .mobile-topbar { display: none; }
  .sidebar-overlay { display: none; }
  @media (max-width: 767.98px) {
    .sidebar {
      position: fixed;
      top: 0; left: 0;
      z-index: 1050;
      width: 260px;
      max-width: 82vw;
      height: 100vh;
      min-height: 100vh;
      overflow-y: auto;
      transform: translateX(-105%);
      transition: transform .25s ease;
      box-shadow: 4px 0 18px rgba(0,0,0,.25);
    }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay {
      display: block;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 32, .45);
      z-index: 1040;
      opacity: 0;
      pointer-events: none;
      transition: opacity .25s ease;
    }
    .sidebar-overlay.show { opacity: 1; pointer-events: auto; }
    body.sidebar-open { overflow: hidden; }
    .mobile-topbar { display: flex; }
  }
</style>
</head>
<body class="menu-<?= $menu_position ?>">
<?php if ($menu_position === 'top'): ?>
<nav class="topnav navbar navbar-expand-lg py-2" data-testid="topnav">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
      <?php if ($has_logo): ?><img src="<?= esc($logo) ?>" alt="Logo" class="me-2 rounded bg-white p-1" style="height:32px;width:32px;object-fit:contain" data-testid="topnav-logo"><?php else: ?><i class="bi bi-gear-wide-connected me-2"></i><?php endif; ?>
      <?= esc($app_name) ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavMenu" data-testid="topnav-toggler"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="topNavMenu">
      <ul class="navbar-nav ms-auto flex-wrap align-items-lg-center">
        <?php foreach ($nav_items as $it): if ($it[4] && !$is_admin) continue; ?>
        <li class="nav-item"><a class="nav-link <?= $page===$it[0]?'active':'' ?>" href="<?= $it[1] ?>" data-testid="nav-<?= $it[0] ?>"><i class="bi <?= $it[2] ?> me-1"></i><?= $it[3] ?></a></li>
        <?php endforeach; ?>
        <li class="nav-item"><a class="nav-link" href="index.php?page=logout" data-testid="nav-logout"><i class="bi bi-box-arrow-right me-1"></i>Keluar</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container-fluid">
<div class="row">
  <main class="col-12 px-4 py-3">
<?php else: ?>
<div class="container-fluid">
<div class="row <?= $menu_position==='right' ? 'flex-row-reverse' : '' ?>">
  <nav class="col-lg-2 col-md-3 sidebar py-3 px-0" data-testid="sidebar">
    <div class="px-3 mb-3 d-flex justify-content-between align-items-center">
      <span class="text-white fw-bold fs-5 d-flex align-items-center">
        <?php if ($has_logo): ?>
        <img src="<?= esc($logo) ?>" alt="Logo" class="me-2 rounded bg-white p-1" style="height:34px;width:34px;object-fit:contain" data-testid="sidebar-logo">
        <?php else: ?><i class="bi bi-gear-wide-connected me-2"></i><?php endif; ?>
        <?= esc($app_name) ?>
      </span>
      <button type="button" id="btnSidebarClose" class="btn btn-sm text-white d-md-none" data-testid="sidebar-close" aria-label="Tutup menu"><i class="bi bi-x-lg"></i></button>
    </div>
    <ul class="nav flex-column">
      <?php foreach ($nav_items as $it): if ($it[4] && !$is_admin) continue; ?>
      <li><a class="nav-link <?= $page===$it[0]?'active':'' ?>" href="<?= $it[1] ?>" data-testid="nav-<?= $it[0] ?>"><i class="bi <?= $it[2] ?> me-2"></i><?= $it[3] ?></a></li>
      <?php endforeach; ?>
      <li><a class="nav-link" href="index.php?page=logout" data-testid="nav-logout"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
    </ul>
  </nav>
  <div class="sidebar-overlay" id="sidebarOverlay" data-testid="sidebar-overlay"></div>
  <script>
  // Off-canvas sidebar mobile: buka/tutup via hamburger, tombol X, overlay,
  // dan otomatis tertutup saat salah satu menu dipilih.
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('[data-testid="sidebar"]');
    if (!sidebar) return;
    const overlay = document.getElementById('sidebarOverlay');
    const btnOpen = document.getElementById('btnSidebarOpen');
    const btnClose = document.getElementById('btnSidebarClose');
    function showSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); document.body.classList.add('sidebar-open'); }
    function hideSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); document.body.classList.remove('sidebar-open'); }
    if (btnOpen) btnOpen.addEventListener('click', showSidebar);
    if (btnClose) btnClose.addEventListener('click', hideSidebar);
    if (overlay) overlay.addEventListener('click', hideSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(a => a.addEventListener('click', hideSidebar));
  });
  </script>
  <main class="col-lg-10 col-md-9 px-4 py-3">
<?php endif; ?>
    <?php if ($menu_position !== 'top'): ?>
    <div class="mobile-topbar align-items-center gap-2 mb-3 pb-2 border-bottom" data-testid="mobile-topbar">
      <button type="button" id="btnSidebarOpen" class="btn btn-sm btn-outline-secondary" data-testid="hamburger-btn" aria-label="Buka menu"><i class="bi bi-list fs-5"></i></button>
      <span class="fw-bold text-truncate"><?= esc($app_name) ?></span>
      <span class="ms-auto small text-muted text-nowrap"><i class="bi bi-person-circle me-1"></i><?= esc($user['nama'] ?? '') ?></span>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h4 mb-0" data-testid="page-title"><?= esc($title ?? '') ?></h1>
      <span class="text-muted d-none d-md-inline" data-testid="current-user"><i class="bi bi-person-circle me-1"></i><?= esc($user['nama'] ?? '') ?> (<?= esc($user['role'] ?? '') ?>)</span>
    </div>
    <?php if ($flash): ?>
    <div class="alert alert-<?= esc($flash['type']) ?> alert-dismissible fade show" data-testid="flash-message">
      <?= esc($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
