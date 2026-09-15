<?php
// Halaman login memakai nama bengkel & tema gradasi dari pengaturan
$th1 = (int)setting('theme_h1', '210');
$th2 = (int)setting('theme_h2', '232');
$app_name = setting('nama_bengkel', 'Sistem Bengkel Motor');
// Proses login (username + password)
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u && password_verify($password, $u['password_hash'])) {
        // Cegah session fixation: perbarui ID sesi setelah login berhasil.
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'nama' => $u['nama'], 'role' => $u['role']];
        header('Location: index.php');
        exit;
    }
    if (function_exists('app_log')) app_log('auth', 'Login gagal untuk username=' . substr($username, 0, 60) . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    $error = 'Username atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - <?= esc($app_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: linear-gradient(150deg, hsl(<?= $th1 ?> 60% 18%), hsl(<?= $th2 ?> 65% 32%)); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .login-card { width: 100%; max-width: 400px; border: 0; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
</style>
</head>
<body>
<div class="card login-card" data-testid="login-card">
  <div class="card-body p-4">
    <div class="text-center mb-4">
      <?php $logo = setting('logo'); if ($logo && is_file(__DIR__ . '/../' . $logo)): ?>
      <img src="<?= esc($logo) ?>" alt="Logo" style="max-height:72px" data-testid="login-logo">
      <?php else: ?>
      <i class="bi bi-gear-wide-connected text-primary" style="font-size:3rem"></i>
      <?php endif; ?>
      <h1 class="h4 mt-2 mb-0"><?= esc($app_name) ?></h1>
      <p class="text-muted small">Silakan masuk untuk melanjutkan</p>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-danger" data-testid="login-error"><?= esc($error) ?></div>
    <?php endif; ?>
    <form method="post" data-testid="login-form">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required autofocus data-testid="login-username">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required data-testid="login-password">
      </div>
      <button type="submit" class="btn btn-primary w-100" data-testid="login-submit">Masuk</button>
    </form>
    <p class="text-center text-muted small mt-3 mb-0">Default: <code>admin</code> / <code>admin123</code></p>
  </div>
</div>
</body>
</html>
