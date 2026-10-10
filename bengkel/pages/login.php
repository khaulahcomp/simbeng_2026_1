<?php
// Halaman login memakai nama bengkel & tema gradasi dari pengaturan
$th1 = (int)setting('theme_h1', '210');
$th2 = (int)setting('theme_h2', '232');
$app_name = setting('nama_bengkel', 'Sistem Bengkel Motor');
// Slide foto untuk sisi kiri halaman login (dikelola di menu Pengaturan)
require_once __DIR__ . '/../includes/login_slides.php';
$slides = login_slides_active();
// Proses login (username + password)
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Rate limiting PER AKUN: bila username ini sedang terkunci (5x gagal
    // berturut-turut), tolak TANPA memeriksa password sama sekali. Akun lain
    // (role berbeda) di perangkat/IP yang sama TIDAK ikut terpengaruh.
    $lockSec = login_lock_seconds_left($ip, $username);
    if ($lockSec > 0) {
        $lockMin = (int)ceil($lockSec / 60);
        $error = "Akun ini terkunci sementara karena terlalu banyak percobaan gagal. Silakan coba lagi dalam $lockMin menit.";
        if (function_exists('app_log')) app_log('security', "Login diblokir (rate limit) username=" . substr($username, 0, 60) . " ip=$ip sisa={$lockSec}s");
    } else {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($password, $u['password_hash'])) {
            login_register_success($ip, $username);
            // Cegah session fixation: perbarui ID sesi setelah login berhasil.
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'nama' => $u['nama'], 'role' => $u['role']];
            header('Location: index.php');
            exit;
        }
        $failCount = login_register_fail($ip, $username);
        if (function_exists('app_log')) app_log('auth', 'Login gagal untuk username=' . substr($username, 0, 60) . ' ip=' . $ip . " (percobaan ke-$failCount)");
        if ($failCount >= LOGIN_MAX_ATTEMPTS) {
            $error = 'Terlalu banyak percobaan gagal. Akun ini dikunci sementara selama ' . LOGIN_LOCK_MINUTES . ' menit. Akun dengan role lain tetap bisa login.';
        } else {
            $sisa = LOGIN_MAX_ATTEMPTS - $failCount;
            $error = "Username atau password salah. Percobaan tersisa: $sisa.";
        }
    }
}
?>
<?php
// ---- Data tampilan: logo, slide (dari Pengaturan), pengaturan animasi ----
$logo = setting('logo');
$has_logo = $logo && is_file(__DIR__ . '/../' . $logo);
$has_slides = count($slides) > 0;
$many = count($slides) > 1;
$interval_ms = 7000;   // lama tiap slide tampil
$fade_ms = 2200;       // durasi transisi antar-slide (lambat)
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - <?= esc($app_name) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fontsource/playfair-display@5/600.css" rel="stylesheet">
<style>
  :root {
    --h1: <?= $th1 ?>; --h2: <?= $th2 ?>;
    --c1: hsl(var(--h1) 60% 18%);
    --c2: hsl(var(--h2) 65% 32%);
    --fade: <?= $fade_ms ?>ms;
    --interval: <?= $interval_ms ?>ms;
    --serif: "Playfair Display", Georgia, "Times New Roman", serif;
  }
  html, body { height: 100%; }
  body.login-body { margin: 0; background: #f4f6fa; color: #1f2933; }

  /* ===== Tata letak: hero (slider) kiri + panel login kanan ===== */
  .login-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(400px, .85fr); }

  /* ===== Hero / slider ===== */
  .login-hero { position: relative; overflow: hidden; isolation: isolate; min-height: 100vh; color: #fff;
    background: linear-gradient(150deg, var(--c1), var(--c2)); }
  .hero-slide { position: absolute; inset: 0; opacity: 0; margin: 0; }
  .hero-slide.is-leaving { opacity: 1; z-index: 0; }                     /* tetap terlihat sampai slide baru selesai muncul */
  .hero-slide.is-active { opacity: 1; z-index: 1; animation: slideIn var(--fade) ease-in-out both; }
  .hero-slide img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transform-origin: 50% 50%; will-change: transform; }
  .hero-slide:nth-child(3n+1) img { transform-origin: 18% 32%; }
  .hero-slide:nth-child(3n+2) img { transform-origin: 82% 40%; }
  .hero-slide:nth-child(3n)   img { transform-origin: 50% 85%; }
  /* Zoom perlahan (Ken Burns): berlangsung selama slide tampil + masa transisi keluar */
  .hero-slide.is-active img, .hero-slide.is-leaving img { animation: kenburns calc(var(--interval) + var(--fade) * 2) cubic-bezier(.3, .1, .3, 1) both; }
  .is-single .hero-slide img { animation-duration: 20s; animation-iteration-count: infinite; animation-direction: alternate; }
  @keyframes slideIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes kenburns { from { transform: scale(1); } to { transform: scale(1.14); } }

  .hero-overlay { position: absolute; inset: 0; z-index: 2; pointer-events: none;
    background:
      linear-gradient(180deg, hsl(var(--h1) 60% 8% / .38) 0%, transparent 30%),
      linear-gradient(0deg, hsl(var(--h1) 60% 8% / .9) 0%, hsl(var(--h1) 60% 10% / .55) 34%, hsl(var(--h2) 65% 20% / .14) 72%, transparent 100%); }

  .hero-content { position: absolute; z-index: 3; left: 0; right: 0; bottom: 0;
    padding: clamp(28px, 5vw, 72px); padding-bottom: clamp(64px, 8vw, 100px); }
  .hero-captions { display: grid; max-width: 38rem; }
  .hero-caption { grid-area: 1 / 1; opacity: 0; transform: translateY(22px); pointer-events: none;
    transition: opacity .9s ease, transform 1.2s cubic-bezier(.2, .7, .2, 1); }
  .hero-caption.is-active { opacity: 1; transform: none; pointer-events: auto; transition-delay: .6s; }   /* muncul setelah foto mulai terlihat */
  .hero-caption::before { content: ""; display: block; width: 44px; height: 2px; margin-bottom: 18px; background: rgba(255, 255, 255, .8);
    transform: scaleX(0); transform-origin: left; transition: transform 1s ease; }
  .hero-caption.is-active::before { transform: scaleX(1); transition-delay: .9s; }
  .hero-title { font-family: var(--serif); font-weight: 600; font-size: clamp(1.8rem, 3.1vw, 3rem); line-height: 1.15; letter-spacing: -.005em;
    margin: 0 0 14px; text-shadow: 0 2px 24px rgba(0, 0, 0, .35); overflow-wrap: anywhere; }
  .hero-detail { font-size: clamp(.95rem, 1.15vw, 1.1rem); line-height: 1.65; color: rgba(255, 255, 255, .86); margin: 0; max-width: 34rem; overflow-wrap: anywhere; }

  .hero-dots { position: absolute; z-index: 4; left: clamp(28px, 5vw, 72px); bottom: clamp(24px, 3.6vw, 44px); display: flex; gap: 8px; }
  .hero-dot { position: relative; width: 34px; height: 16px; padding: 0; border: 0; background: none; cursor: pointer; }
  .hero-dot::before, .hero-dot::after { content: ""; position: absolute; left: 0; top: 6px; height: 4px; border-radius: 4px; }
  .hero-dot::before { right: 0; background: rgba(255, 255, 255, .32); transition: background .3s; }
  .hero-dot:hover::before { background: rgba(255, 255, 255, .55); }
  .hero-dot::after { width: 0; background: #fff; }
  .hero-dot.is-active::after { animation: dotfill var(--interval) linear forwards; }
  .hero-dot:focus-visible { outline: 2px solid #fff; outline-offset: 2px; border-radius: 4px; }
  @keyframes dotfill { from { width: 0; } to { width: 100%; } }

  /* Tampilan bawaan (belum ada slide): gradasi tema + cahaya lembut yang bergerak pelan */
  .login-hero.is-empty::before, .login-hero.is-empty::after { content: ""; position: absolute; z-index: 0; border-radius: 50%; filter: blur(70px); opacity: .55;
    animation: drift 24s ease-in-out infinite alternate; }
  .login-hero.is-empty::before { width: 46vw; height: 46vw; left: -10vw; top: -12vw; background: hsl(var(--h2) 80% 55% / .45); }
  .login-hero.is-empty::after  { width: 38vw; height: 38vw; right: -8vw; bottom: -10vw; background: hsl(var(--h1) 80% 50% / .4); animation-delay: -9s; }
  @keyframes drift { to { transform: translate(6vw, 4vw) scale(1.15); } }

  /* ===== Panel login ===== */
  .login-panel { position: relative; display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: #f4f6fa; }
  .login-panel::before { content: ""; position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(900px 480px at 100% 0%, hsl(var(--h2) 70% 60% / .10), transparent 60%),
                radial-gradient(700px 400px at 0% 100%, hsl(var(--h1) 70% 50% / .08), transparent 60%); }
  .login-card { --bs-border-radius: .7rem; position: relative; width: 100%; max-width: 410px; border: 0; border-radius: 20px; background: #fff;
    box-shadow: 0 24px 60px -24px rgba(15, 23, 42, .28), 0 2px 6px rgba(15, 23, 42, .05); }
  .login-card .card-body { padding: 2.4rem 2.1rem 1.9rem; }
  .login-brand h1 { font-family: var(--serif); font-weight: 600; font-size: 1.6rem; letter-spacing: -.005em; color: #16202c; }
  .login-brand p { font-size: .88rem; }
  .login-card .form-label { font-size: .74rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; }
  .login-card .input-group { border-radius: var(--bs-border-radius); transition: box-shadow .2s; }
  .login-card .input-group:focus-within { box-shadow: 0 0 0 .24rem hsl(var(--h2) 65% 45% / .17); }
  .login-card .input-group-text { background: #f3f5f9; border-color: #dfe4ec; color: #7b8794; }
  .login-card .form-control { border-color: #dfe4ec; padding: .72rem .85rem; font-size: .95rem; }
  .login-card .form-control:focus { border-color: hsl(var(--h2) 65% 45%); box-shadow: none; }
  .login-card .input-group:focus-within .input-group-text { border-color: hsl(var(--h2) 65% 45%); color: hsl(var(--h2) 65% 38%); }
  .login-reveal { cursor: pointer; }
  .login-reveal:hover { color: #334155; }
  .btn-login { border: 0; color: #fff; font-weight: 600; letter-spacing: .02em; padding: .8rem 1rem;
    background-image: linear-gradient(135deg, var(--c1), var(--c2)); background-color: var(--c2);
    box-shadow: 0 12px 24px -12px hsl(var(--h2) 65% 28% / .7); transition: transform .2s, box-shadow .2s, filter .2s; }
  .btn-login:hover, .btn-login:focus { color: #fff; background-image: linear-gradient(135deg, var(--c1), var(--c2)); background-color: var(--c2); filter: brightness(1.1); transform: translateY(-1px); }
  .btn-login:active { transform: none; color: #fff; }
  .login-foot { font-size: .78rem; }

  /* ===== Layar kecil: slider jadi banner di atas, panel login menimpa sedikit ===== */
  @media (max-width: 991.98px) {
    .login-shell { display: flex; flex-direction: column; }
    .login-hero { min-height: 0; height: clamp(260px, 42vh, 380px); flex: none; }
    .hero-content { padding: 22px 22px 76px; }
    .hero-title { font-size: 1.5rem; margin-bottom: 8px; }
    .hero-caption::before { margin-bottom: 12px; }
    .hero-detail { font-size: .9rem; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .hero-dots { left: 22px; bottom: 46px; }   /* di atas bagian panel login yang menimpa banner */
    .login-panel { flex: 1; align-items: flex-start; margin-top: -30px; z-index: 5; padding: 26px 16px 36px; border-radius: 28px 28px 0 0; }
    .login-card { max-width: 460px; }
    .login-card .card-body { padding: 2rem 1.5rem 1.6rem; }
  }
  @media (max-width: 575.98px) {
    .login-hero { height: clamp(220px, 34vh, 300px); }
    .hero-title { font-size: 1.3rem; }
  }

  /* Hormati preferensi "kurangi animasi" */
  @media (prefers-reduced-motion: reduce) {
    .hero-slide.is-active img, .hero-slide.is-leaving img, .login-hero.is-empty::before, .login-hero.is-empty::after { animation: none; }
    .hero-slide.is-active { animation-duration: .6s; }
    .hero-caption, .hero-caption::before { transition-duration: .01s; transition-delay: 0s; transform: none; }
    .hero-dot.is-active::after { animation: none; width: 100%; }
    .btn-login:hover { transform: none; }
  }
</style>
</head>
<body class="login-body">
<main class="login-shell">

  <!-- ===== Sisi kiri: slider foto ===== -->
  <section id="loginHero" class="login-hero <?= $has_slides ? ($many ? '' : 'is-single') : 'is-empty' ?>"
           data-interval="<?= $interval_ms ?>" data-fade="<?= $fade_ms ?>" data-testid="login-hero"
           role="region" aria-roledescription="carousel" aria-label="Galeri <?= esc($app_name) ?>">
    <?php if ($has_slides): ?>
    <div class="hero-slides">
      <?php foreach ($slides as $i => $s): ?>
      <figure class="hero-slide <?= $i === 0 ? 'is-active' : '' ?>" data-testid="login-slide-<?= $i ?>">
        <?php if ($i === 0): ?>
        <img src="<?= esc($s['image']) ?>" alt="" fetchpriority="high" decoding="async">
        <?php else: ?>
        <img data-src="<?= esc($s['image']) ?>" alt="" decoding="async">
        <?php endif; ?>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="hero-overlay"></div>

    <div class="hero-content">
      <div class="hero-captions" aria-live="off">
        <?php if ($has_slides): ?>
          <?php foreach ($slides as $i => $s): ?>
            <?php if ($s['title'] !== '' || $s['detail'] !== ''): ?>
            <div class="hero-caption <?= $i === 0 ? 'is-active' : '' ?>" data-testid="login-caption-<?= $i ?>">
              <?php if ($s['title'] !== ''): ?><h2 class="hero-title"><?= esc($s['title']) ?></h2><?php endif; ?>
              <?php if ($s['detail'] !== ''): ?><p class="hero-detail"><?= esc($s['detail']) ?></p><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="hero-caption <?= $i === 0 ? 'is-active' : '' ?>" aria-hidden="true"></div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?>
        <div class="hero-caption is-active" data-testid="login-caption-default">
          <h2 class="hero-title"><?= esc($app_name) ?></h2>
          <p class="hero-detail">Kelola servis, stok sparepart, kasir, dan laporan bengkel dalam satu tempat.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($many): ?>
    <div class="hero-dots" role="tablist" aria-label="Pilih slide" data-testid="login-dots">
      <?php foreach ($slides as $i => $s): ?>
      <button type="button" class="hero-dot <?= $i === 0 ? 'is-active' : '' ?>" role="tab" aria-label="Slide <?= $i + 1 ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- ===== Sisi kanan: form login ===== -->
  <section class="login-panel">
    <div class="card login-card" data-testid="login-card">
      <div class="card-body">
        <div class="login-brand text-center mb-4">
          <?php if ($has_logo): ?>
          <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:72px" data-testid="login-logo">
          <?php else: ?>
          <i class="bi bi-gear-wide-connected text-primary" style="font-size:3rem"></i>
          <?php endif; ?>
          <h1 class="h4 mt-2 mb-1"><?= esc($app_name) ?></h1>
          <p class="text-muted mb-0">Silakan masuk untuk melanjutkan</p>
        </div>
        <?php if ($error): ?>
        <div class="alert alert-danger" data-testid="login-error"><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" data-testid="login-form">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="loginUser">Username</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" name="username" id="loginUser" class="form-control" required autofocus autocomplete="username" data-testid="login-username">
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label" for="loginPass">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" id="loginPass" class="form-control" required autocomplete="current-password" data-testid="login-password">
              <button type="button" class="input-group-text login-reveal" id="togglePw" aria-label="Tampilkan password" data-testid="login-toggle-password"><i class="bi bi-eye"></i></button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-login w-100" data-testid="login-submit">Masuk</button>
        </form>
        <p class="login-foot text-center text-muted mt-3 mb-0">Default: <code>admin</code> / <code>admin123</code></p>
      </div>
    </div>
  </section>

</main>
<script>
(function () {
  // Tampilkan / sembunyikan password
  var btn = document.getElementById('togglePw'), pw = document.getElementById('loginPass');
  if (btn && pw) btn.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    btn.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    pw.focus();
  });

  // Slider: pergantian otomatis, foto berikutnya dimuat belakangan agar halaman tetap ringan
  var hero = document.getElementById('loginHero');
  if (!hero) return;
  var slides = [].slice.call(hero.querySelectorAll('.hero-slide'));
  var caps = [].slice.call(hero.querySelectorAll('.hero-caption'));
  var dots = [].slice.call(hero.querySelectorAll('.hero-dot'));
  var n = slides.length;
  if (n < 1) return;
  var interval = parseInt(hero.getAttribute('data-interval'), 10) || 7000;
  var fade = parseInt(hero.getAttribute('data-fade'), 10) || 2200;
  var cur = 0, timer = null, leaveTimer = null;

  function load(i) {
    var img = slides[i] && slides[i].querySelector('img');
    if (img && !img.getAttribute('src') && img.getAttribute('data-src')) img.setAttribute('src', img.getAttribute('data-src'));
  }
  function restartDot(i) {
    var d = dots[i];
    if (!d) return;
    d.classList.remove('is-active'); void d.offsetWidth; d.classList.add('is-active');
  }
  function show(next) {
    if (next === cur) return;
    load(next);
    var prev = slides[cur];
    slides.forEach(function (s) { s.classList.remove('is-leaving'); });
    prev.classList.remove('is-active');
    prev.classList.add('is-leaving');
    slides[next].classList.add('is-active');
    caps.forEach(function (c, i) { c.classList.toggle('is-active', i === next); });
    dots.forEach(function (d, i) {
      d.setAttribute('aria-selected', i === next ? 'true' : 'false');
      if (i !== next) d.classList.remove('is-active');
    });
    restartDot(next);
    cur = next;
    load((cur + 1) % n);
    clearTimeout(leaveTimer);
    leaveTimer = setTimeout(function () { prev.classList.remove('is-leaving'); }, fade + 150);
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(function () { show((cur + 1) % n); schedule(); }, interval);
  }
  if (n < 2) return;

  dots.forEach(function (d, i) { d.addEventListener('click', function () { show(i); schedule(); }); });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(timer); }
    else { restartDot(cur); schedule(); }
  });
  function start() { load(1); restartDot(0); schedule(); }
  if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();
</script>
</body>
</html>
