<?php
// ============================================================
// totp.php - TOTP (RFC 6238) murni PHP, tanpa dependensi Composer.
// Kompatibel dengan Google Authenticator, Authy, Microsoft
// Authenticator, dsb. Dipakai untuk verifikasi login 2 langkah.
// ============================================================

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) continue;
        $out .= chr(bindec($byte));
    }
    return $out;
}

// Buat secret baru (160-bit, sesuai rekomendasi RFC 4226) dalam Base32.
function totp_generate_secret(int $bytes = 20): string {
    return totp_base32_encode(random_bytes($bytes));
}

function totp_hotp(string $secretBase32, int $counter, int $digits = TOTP_DIGITS): string {
    $key = totp_base32_decode($secretBase32);
    // Counter 8-byte big-endian (32-bit tinggi selalu 0 hingga tahun ~2554).
    $bin = pack('N', 0) . pack('N', $counter);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | (ord($hash[$offset + 3]) & 0xFF);
    $code = $part % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

function totp_current_code(string $secretBase32, ?int $timestamp = null, int $period = TOTP_PERIOD, int $digits = TOTP_DIGITS): string {
    $timestamp = $timestamp ?? time();
    $counter = (int) floor($timestamp / $period);
    return totp_hotp($secretBase32, $counter, $digits);
}

// Verifikasi kode dengan toleransi pergeseran waktu (±1 langkah = ±30 detik).
function totp_verify(string $secretBase32, string $code, int $window = 1, int $period = TOTP_PERIOD, int $digits = TOTP_DIGITS): bool {
    if ($secretBase32 === '') return false;
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== $digits) return false;
    $counter = (int) floor(time() / $period);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_hotp($secretBase32, $counter + $i, $digits), $code)) return true;
    }
    return false;
}

// URI otpauth:// standar untuk di-scan aplikasi authenticator (QR code
// digambar di sisi klien / browser, secret tidak pernah dikirim ke pihak ketiga).
function totp_provisioning_uri(string $secretBase32, string $accountLabel, string $issuer): string {
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
    $params = http_build_query([
        'secret'    => $secretBase32,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => TOTP_DIGITS,
        'period'    => TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);
    return "otpauth://totp/{$label}?{$params}";
}

// ---- Kode cadangan (recovery codes) untuk berjaga bila HP hilang ----
function totp_generate_recovery_codes(int $count = 8): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) $codes[] = strtoupper(bin2hex(random_bytes(4)));
    return $codes;
}

// Simpan hanya dalam bentuk hash (seperti password), tidak pernah plaintext di DB.
function totp_hash_recovery_codes(array $plainCodes): string {
    return json_encode(array_map(static fn($c) => password_hash($c, PASSWORD_DEFAULT), $plainCodes));
}

// Cek & konsumsi (hapus setelah dipakai, sekali pakai) kode cadangan milik user.
function totp_consume_recovery_code(int $userId, string $inputCode, string $hashedJson): bool {
    $inputCode = strtoupper(trim($inputCode));
    if ($inputCode === '') return false;
    $hashes = json_decode($hashedJson ?: '[]', true);
    if (!is_array($hashes)) return false;
    foreach ($hashes as $idx => $hash) {
        if (is_string($hash) && password_verify($inputCode, $hash)) {
            unset($hashes[$idx]);
            db()->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ?")
                ->execute([json_encode(array_values($hashes)), $userId]);
            return true;
        }
    }
    return false;
}
