<?php
// ============================================================
// profile.php - Keamanan akun milik pengguna yang sedang login:
// aktifkan/nonaktifkan OTP Google Authenticator (TOTP) + kelola
// kode cadangan. Setiap pengguna mengatur 2FA miliknya sendiri.
// ============================================================
$me = current_user();
$db = db();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$me['id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

$action = $_POST['action'] ?? '';
$recoveryCodesToShow = null;

if ($action === 'enable_confirm') {
    $secret = $_SESSION['totp_setup_secret'] ?? '';
    $code = trim($_POST['otp_code'] ?? '');
    if ($secret !== '' && totp_verify($secret, $code)) {
        $plainCodes = totp_generate_recovery_codes();
        $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ? WHERE id = ?")
           ->execute([$secret, totp_hash_recovery_codes($plainCodes), $me['id']]);
        unset($_SESSION['totp_setup_secret']);
        $recoveryCodesToShow = $plainCodes;
        set_flash('success', 'OTP Google Authenticator berhasil diaktifkan.');
        if (function_exists('app_log')) app_log('auth', 'OTP diaktifkan untuk username=' . $u['username']);
        $stmt->execute([$me['id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        set_flash('danger', 'Kode OTP tidak valid. Pastikan jam di HP Anda sudah tepat, lalu coba lagi.');
    }
}

if ($action === 'disable') {
    $password = $_POST['password'] ?? '';
    if ($password !== '' && password_verify($password, $u['password_hash'])) {
        $db->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_recovery_codes = NULL WHERE id = ?")->execute([$me['id']]);
        if (function_exists('app_log')) app_log('auth', 'OTP dinonaktifkan untuk username=' . $u['username']);
        set_flash('success', 'OTP Google Authenticator dinonaktifkan.');
    } else {
        set_flash('danger', 'Password salah. OTP tidak dinonaktifkan.');
    }
    header('Location: index.php?page=profile'); exit;
}

if ($action === 'regenerate_recovery' && !empty($u['totp_enabled'])) {
    $plainCodes = totp_generate_recovery_codes();
    $db->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ?")->execute([totp_hash_recovery_codes($plainCodes), $me['id']]);
    $recoveryCodesToShow = $plainCodes;
    set_flash('success', 'Kode cadangan baru dibuat. Kode lama sudah tidak berlaku.');
}

// Siapkan secret baru (belum tersimpan permanen) untuk proses aktivasi.
if (empty($u['totp_enabled']) && empty($_SESSION['totp_setup_secret'])) {
    $_SESSION['totp_setup_secret'] = totp_generate_secret();
}
$setupSecret = $_SESSION['totp_setup_secret'] ?? '';
$appName = setting('nama_bengkel', 'Sistem Bengkel Motor');
$otpauthUri = ($setupSecret !== '' && empty($u['totp_enabled'])) ? totp_provisioning_uri($setupSecret, $u['username'], $appName) : '';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6 mb-3"><i class="bi bi-shield-lock me-1"></i>OTP Google Authenticator</h2>

      <?php if ($recoveryCodesToShow): ?>
      <div class="alert alert-warning" data-testid="recovery-codes-box">
        <strong>Simpan kode cadangan berikut di tempat aman.</strong> Setiap kode hanya berlaku sekali pakai untuk login jika HP Anda hilang/rusak. Kode ini hanya ditampilkan satu kali sekarang.
        <div class="mt-2 font-monospace">
          <?php foreach ($recoveryCodesToShow as $c): ?><span class="badge bg-dark me-1 mb-1" data-testid="recovery-code"><?= esc($c) ?></span><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($u['totp_enabled'])): ?>
        <p class="text-success" data-testid="otp-status-active"><i class="bi bi-check-circle-fill me-1"></i>OTP Google Authenticator <strong>aktif</strong> untuk akun ini. Setiap login akan meminta kode 6 digit tambahan.</p>

        <form method="post" class="mb-4" data-testid="regen-recovery-form">
          <input type="hidden" name="action" value="regenerate_recovery">
          <button class="btn btn-sm btn-outline-secondary" data-testid="btn-regen-recovery"><i class="bi bi-arrow-repeat me-1"></i>Buat Ulang Kode Cadangan</button>
        </form>

        <hr>
        <h3 class="h6">Nonaktifkan OTP</h3>
        <p class="text-muted small">Menonaktifkan OTP membuat akun hanya dilindungi password. Masukkan password Anda untuk konfirmasi.</p>
        <form method="post" class="row g-2" data-testid="disable-otp-form">
          <input type="hidden" name="action" value="disable">
          <div class="col-sm-6">
            <input type="password" name="password" class="form-control form-control-sm" placeholder="Password Anda" required data-testid="disable-otp-password">
          </div>
          <div class="col-sm-6">
            <button class="btn btn-sm btn-outline-danger w-100" data-testid="btn-disable-otp">Nonaktifkan OTP</button>
          </div>
        </form>
      <?php else: ?>
        <p class="text-muted small">Aktifkan verifikasi 2 langkah memakai aplikasi <strong>Google Authenticator</strong> (atau Authy / Microsoft Authenticator) agar akun ini tetap aman walau password bocor.</p>
        <ol class="small text-muted">
          <li>Buka aplikasi Authenticator di HP, pilih <em>Scan QR Code</em>.</li>
          <li>Arahkan kamera ke kode QR di bawah (atau masukkan kunci manual).</li>
          <li>Masukkan 6 digit kode yang muncul untuk mengonfirmasi & mengaktifkan.</li>
        </ol>
        <div class="text-center my-3">
          <canvas id="totpQr" data-testid="totp-qr"></canvas>
        </div>
        <p class="text-center small">Kunci manual: <code data-testid="totp-manual-key"><?= esc($setupSecret) ?></code></p>
        <form method="post" class="row g-2 justify-content-center" data-testid="enable-otp-form">
          <input type="hidden" name="action" value="enable_confirm">
          <div class="col-sm-6">
            <input type="text" name="otp_code" class="form-control text-center" placeholder="123456" maxlength="6" inputmode="numeric" pattern="\d{6}" required autofocus data-testid="enable-otp-code">
          </div>
          <div class="col-sm-6">
            <button class="btn btn-primary w-100" data-testid="btn-enable-otp">Aktifkan OTP</button>
          </div>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php if ($otpauthUri): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.getElementById('totpQr'), <?= json_encode($otpauthUri) ?>, { width: 220 }, function (err) { if (err) console.error(err); });
</script>
<?php endif; ?>
