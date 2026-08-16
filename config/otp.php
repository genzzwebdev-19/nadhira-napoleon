<?php
// ============================================
// WHATSAPP OTP HELPER (Fonnte)
// Website Nadhira Napoleon Pekanbaru
// ============================================
// Verifikasi kode OTP via WhatsApp untuk pendaftaran akun.
// Penyedia: Fonnte (https://www.fonnte.com) — gateway WhatsApp Indonesia
// yang hanya membutuhkan token API (tanpa persetujuan template).
//
// Konfigurasi disimpan di tabel settings:
//   wa_otp_enabled          -> '1' = aktif, '0' = nonaktif (registrasi langsung)
//   wa_otp_token            -> Token API Fonnte
//   wa_otp_test_mode        -> '1' = mode uji (kode tampil di layar, TIDAK dikirim)
//   wa_otp_expiry_minutes   -> Masa berlaku kode (menit, default 5)
//
// Mode uji diaktifkan secara default agar sistem bisa dipakai & diuji
// sebelum pemilik toko mendapatkan token Fonnte.
// ============================================

require_once __DIR__ . '/database.php';

// ============================================
// SKEMA DATABASE - self-healing
// ============================================
function ensureOtpSchema() {
    static $done = false;
    if ($done) return true;
    $conn = getConnection();
    if (!$conn) return false;
    $done = true;

    // Tabel verifikasi OTP
    $conn->query("CREATE TABLE IF NOT EXISTS otp_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(64) NOT NULL,
        phone VARCHAR(20) NOT NULL DEFAULT '',
        code_hash VARCHAR(64) NOT NULL,
        purpose VARCHAR(30) NOT NULL DEFAULT 'register',
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        verified TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier (identifier, purpose)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Kunci settings default (self-healing untuk DB lama)
    $settings = [
        'wa_otp_enabled'        => '1',
        'wa_otp_token'          => '',
        'wa_otp_test_mode'      => '1',
        'wa_otp_expiry_minutes' => '5',
    ];
    foreach ($settings as $key => $default) {
        $chk = $conn->query("SELECT id FROM settings WHERE setting_key = '$key' LIMIT 1");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$default')");
        }
    }
    return true;
}

// ============================================
// KONFIGURASI
// ============================================
// OTP aktif jika setting aktif DAN (ada token ATAU mode uji aktif).
function otpIsEnabled() {
    if (getSetting('wa_otp_enabled', '1') !== '1') return false;
    $token = trim(getSetting('wa_otp_token', ''));
    $test  = getSetting('wa_otp_test_mode', '1') === '1';
    return ($token !== '' || $test);
}

function otpExpiryMinutes() {
    return max(1, min(30, (int)getSetting('wa_otp_expiry_minutes', '5')));
}

function otpIsTestMode() {
    return getSetting('wa_otp_test_mode', '1') === '1';
}

// ============================================
// NOMOR TELEPON
// ============================================
// Normalisasi nomor WA ke format internasional 62xxx.
// Hanya menerima nomor HP Indonesia yang valid (628 + 9-13 digit).
function normalizePhone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return '';
    if ($p[0] === '0') $p = '62' . substr($p, 1);
    elseif ($p[0] === '8') $p = '62' . $p;
    if (!preg_match('/^628[0-9]{9,13}$/', $p)) return '';
    return $p;
}

// Tampilkan nomor tersamarkan: 62812xxxx345
function maskPhone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($p) <= 6) return $p;
    return substr($p, 0, 5) . 'xxxx' . substr($p, -3);
}

// ============================================
// KODE OTP
// ============================================
function generateOtpCode() {
    return (string)random_int(100000, 999999);
}

function hashOtpCode($code) {
    return hash_hmac('sha256', (string)$code, 'nadhira-napoleon-otp');
}

// Simpan kode OTP (hash) ke tabel. Mengembalikan id (0 jika gagal).
// Kedaluwarsa dihitung dengan NOW() MySQL (konsisten dengan pengecekan di verify).
// Baris lama untuk identifier yang sama dibersihkan & data lapuk di-prune ringan.
function storeOtp($identifier, $phone, $purpose, $code) {
    $conn = getConnection();
    if (!$conn) return 0;
    $identifier = $conn->real_escape_string((string)$identifier);
    $phone = $conn->real_escape_string((string)$phone);
    $purpose = $conn->real_escape_string((string)$purpose);
    $hash = $conn->real_escape_string(hashOtpCode($code));
    $minutes = otpExpiryMinutes();
    $conn->query("INSERT INTO otp_verifications (identifier, phone, code_hash, purpose, expires_at)
                  VALUES ('$identifier', '$phone', '$hash', '$purpose', DATE_ADD(NOW(), INTERVAL $minutes MINUTE))");
    $id = (int)$conn->insert_id;
    if ($id > 0) {
        $conn->query("DELETE FROM otp_verifications WHERE identifier = '$identifier' AND purpose = '$purpose' AND id <> $id");
        $conn->query("DELETE FROM otp_verifications WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
    return $id;
}

// Verifikasi kode OTP. Maksimal 5 percobaan & berlaku sampai expires_at.
// Mengembalikan ['ok'=>true,'phone'=>...] atau ['ok'=>false,'error'=>...].
function verifyOtpCode($identifier, $purpose, $code) {
    $conn = getConnection();
    if (!$conn) return ['ok' => false, 'error' => 'Koneksi database gagal.'];
    $identifier = $conn->real_escape_string((string)$identifier);
    $purpose = $conn->real_escape_string((string)$purpose);
    $code = trim((string)$code);

    $r = $conn->query("SELECT id, phone, code_hash, expires_at, attempts, verified FROM otp_verifications
                       WHERE identifier = '$identifier' AND purpose = '$purpose'
                       ORDER BY id DESC LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        return ['ok' => false, 'error' => 'Kode OTP tidak ditemukan. Silakan kirim ulang.'];
    }
    $row = $r->fetch_assoc();

    // Waktu sekarang dari MySQL agar konsisten dengan expires_at (hindari selisih jam PHP/DB)
    $dbNow = date('Y-m-d H:i:s');
    $nowR = $conn->query("SELECT NOW() AS n");
    if ($nowR && $nowR->num_rows > 0) $dbNow = $nowR->fetch_assoc()['n'];

    if ((int)$row['verified'] === 1) {
        return ['ok' => false, 'error' => 'Kode OTP sudah pernah dipakai.'];
    }
    if ($row['expires_at'] <= $dbNow) {
        return ['ok' => false, 'error' => 'Kode OTP sudah kedaluwarsa. Silakan kirim ulang.'];
    }
    if ((int)$row['attempts'] >= 5) {
        return ['ok' => false, 'error' => 'Terlalu banyak percobaan. Silakan kirim ulang kode.'];
    }

    if (hash_equals($row['code_hash'], hashOtpCode($code))) {
        $conn->query("UPDATE otp_verifications SET verified = 1 WHERE id = " . (int)$row['id']);
        return ['ok' => true, 'phone' => $row['phone']];
    }

    // Naikkan counter secara atomik (batas 5) agar aman dari balapan permintaan
    $conn->query("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = " . (int)$row['id'] . " AND attempts < 5");
    $newAttempts = (int)$row['attempts'] + 1;
    return ['ok' => false, 'error' => 'Kode OTP salah. Sisa percobaan: ' . max(0, 5 - $newAttempts) . '.'];
}

// Teks pesan WhatsApp
function otpMessageText($code) {
    return 'Kode OTP pendaftaran Nadhira Napoleon Anda: ' . $code . "\n"
         . 'Berlaku ' . otpExpiryMinutes() . ' menit. JANGAN berikan kode ini kepada siapa pun, termasuk yang mengaku dari kami.';
}

// Jumlah OTP yang dikirim ke sebuah nomor dalam X menit terakhir (anti SMS-bombing).
function otpSendCountRecent($phone, $minutes = 60) {
    $conn = getConnection();
    if (!$conn) return 0;
    $phone = $conn->real_escape_string((string)$phone);
    $r = $conn->query("SELECT COUNT(*) c FROM otp_verifications WHERE phone = '$phone' AND created_at > DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)");
    return $r ? (int)$r->fetch_assoc()['c'] : 0;
}

// Batas maksimal kirim OTP per nomor per jam (cegah penyalahgunaan)
function otpSendLimitReached($phone) {
    return otpSendCountRecent($phone, 60) >= 3;
}

// Kirim OTP via Fonnte. Mode uji: tidak benar-benar mengirim, kode dikembalikan.
// Mengembalikan ['ok'=>bool, 'test_mode'=>bool, 'code'=>string|null, 'message'=>string].
function sendOtpWhatsApp($phone, $code) {
    if (otpIsTestMode()) {
        return [
            'ok'        => true,
            'test_mode' => true,
            'code'      => (string)$code,
            'message'   => 'MODE UJI: kode OTP tidak dikirim ke WhatsApp — tampil di layar.',
        ];
    }

    $token = trim(getSetting('wa_otp_token', ''));
    if ($token === '') {
        return ['ok' => false, 'test_mode' => false, 'code' => null, 'message' => 'Token API WhatsApp belum diatur di Pengaturan.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'test_mode' => false, 'code' => null, 'message' => 'Ekstensi cURL tidak tersedia di server.'];
    }

    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'target'  => $phone,
            'message' => otpMessageText($code),
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
        CURLOPT_TIMEOUT    => 15,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $resp === '') {
        return ['ok' => false, 'test_mode' => false, 'code' => null, 'message' => 'Gagal terhubung ke Fonnte: ' . ($curlErr ?: 'respon kosong')];
    }

    $data = json_decode($resp, true);
    if (is_array($data) && !empty($data['status'])) {
        return ['ok' => true, 'test_mode' => false, 'code' => null, 'message' => 'Kode OTP terkirim ke WhatsApp.'];
    }
    $detail = $data['detail'] ?? ($data['reason'] ?? $resp);
    $detail = is_string($detail) ? $detail : $resp;
    return ['ok' => false, 'test_mode' => false, 'code' => null, 'message' => 'Fonnte menolak pengiriman: ' . $detail];
}

ensureOtpSchema();
