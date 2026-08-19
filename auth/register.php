<?php
require_once '../config/rbac.php'; // untuk auto-login (createUserSession) setelah daftar
require_once '../config/otp.php';  // verifikasi OTP WhatsApp (Fonnte)

$otpEnabled = otpIsEnabled();

if (isLoggedIn()) {
    header('Location: ' . SITE_URL);
    exit;
}

// Redirect balik (mis. dari checkout) — hanya path internal halaman (/pages/...) yang diizinkan.
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
if ($redirect !== '' && (strpos($redirect, 'http') === 0 || strpos($redirect, '//') === 0 || $redirect[0] !== '/' || strpos($redirect, '\\') !== false || strpos($redirect, '%') !== false || strpos($redirect, '/pages/') !== 0)) {
    $redirect = '';
}

$error = '';
$info = '';
$step = 'form'; // 'form' | 'verify'

// Finalisasi registrasi: buat akun + auto-login + redirect.
// Dipakai oleh alur langsung (OTP nonaktif) & alur setelah OTP diverifikasi.
$finalizeRegistration = function ($conn, $data) {
    $fullName = $conn->real_escape_string($data['full_name']);
    $username = $conn->real_escape_string($data['username']);
    $email = $conn->real_escape_string($data['email']);
    $phone = $conn->real_escape_string($data['phone'] ?? '');
    $hashedPassword = $data['password_hash'];
    $redirect = $data['redirect'] ?? '';

    $result = $conn->query("INSERT INTO users (username, email, password, full_name, phone) VALUES ('$username', '$email', '$hashedPassword', '$fullName', '$phone')");
    if (!$result) return false;

    // AUTO-LOGIN setelah registrasi — langsung lanjut checkout tanpa 2 langkah
    $newUid = (int)$conn->insert_id;
    $conn->query("UPDATE users SET last_login = NOW() WHERE id = $newUid");

    $oldSid = session_id(); // keranjang tamu tersimpan dengan ID ini (sebelum regenerate)
    session_regenerate_id(true);
    $token = function_exists('createUserSession') ? createUserSession($newUid) : null;
    $_SESSION['user_id'] = $newUid;
    $_SESSION['user_name'] = $data['full_name'];
    if ($token) $_SESSION['session_token'] = $token;

    // Pindahkan keranjang tamu ke akun baru agar tidak hilang
    if (function_exists('mergeGuestCartToUser')) {
        mergeGuestCartToUser($newUid, $oldSid);
    }

    // 'Ingat saya' aktif secara default
    setRememberPreference(true);
    if ($token && function_exists('setRememberCookie')) {
        setRememberCookie($token);
    }

    unset($_SESSION['pending_reg']);

    // Redirect: admin → dashboard; customer → halaman asal (mis. checkout) atau homepage
    if (function_exists('isAdminUser') && isAdminUser($newUid)) {
        header('Location: ' . SITE_URL . '/admin/index.php');
    } elseif ($redirect !== '') {
        header('Location: ' . SITE_URL . $redirect);
    } else {
        header('Location: ' . SITE_URL);
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $_SESSION['pending_reg'] ?? null;
    $submittedCode = trim($_POST['otp_code'] ?? '');
    $resend = isset($_POST['resend']);
    $cancel = isset($_POST['cancel']);

    // Batal / ganti data — kembali ke form, hapus sesi pending
    if ($cancel) {
        unset($_SESSION['pending_reg']);
        $step = 'form';
    }
    // ---- STEP 2: verifikasi OTP / kirim ulang ----
    elseif (($submittedCode !== '' || $resend) && $pending && !empty($pending['otp_id'])) {
        if ($resend) {
            if (otpSendLimitReached($pending['phone'])) {
                $error = 'Terlalu banyak kode OTP dikirim ke nomor ini. Silakan coba lagi nanti.';
            } else {
                $code = generateOtpCode();
                storeOtp($pending['otp_id'], $pending['phone'], 'register', $code);
                $send = sendOtpWhatsApp($pending['phone'], $code);
                if (!$send['ok']) {
                    $error = 'Tidak dapat mengirim ulang kode: ' . $send['message'];
                } else {
                    $pending['test_code'] = $send['code'] ?? '';
                    $_SESSION['pending_reg'] = $pending;
                    $info = 'Kode OTP baru telah dikirim ke WhatsApp ' . maskPhone($pending['phone']) . '.';
                }
            }
            $step = 'verify';
        } else {
            $v = verifyOtpCode($pending['otp_id'], 'register', $submittedCode);
            if ($v['ok']) {
                $finalizeRegistration(getConnection(), $pending); // exit di dalam
            } else {
                $error = $v['error'];
                $step = 'verify';
            }
        }
    }
    // ---- STEP 1: validasi & (kirim OTP atau langsung daftar) ----
    else {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $agree = isset($_POST['agree']);

        // Batas pendaftaran per IP (anti spam akun)
        if (function_exists('rateLimitIp') && !rateLimitIp('register', 5, 3600)) {
            $error = 'Terlalu banyak pendaftaran dari alamat ini. Silakan coba lagi nanti.';
        } elseif (empty($fullName) || empty($username) || empty($email) || empty($password)) {
            $error = 'Silakan isi semua field yang wajib diisi';
        } elseif ($password !== $confirmPassword) {
            $error = 'Konfirmasi password tidak cocok';
        } elseif (strlen($password) < 6) {
            $error = 'Password minimal 6 karakter';
        } elseif (!$agree) {
            $error = 'Anda harus menyetujui syarat & ketentuan';
        } else {
            $conn = getConnection();
            if ($conn) {
                $usernameE = $conn->real_escape_string($username);
                $emailE = $conn->real_escape_string($email);
                $check = $conn->query("SELECT id FROM users WHERE username = '$usernameE' OR email = '$emailE' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $error = 'Username atau email sudah terdaftar';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                    if ($otpEnabled) {
                        // Alur 2 langkah: kirim OTP ke WhatsApp, akun dibuat setelah diverifikasi
                        $normPhone = normalizePhone($phone);
                        if ($normPhone === '') {
                            $error = 'Nomor WhatsApp wajib diisi dengan benar (nomor HP Indonesia, mis. 0812xxxxxxx)';
                        } elseif (otpSendLimitReached($normPhone)) {
                            $error = 'Terlalu banyak kode OTP dikirim ke nomor ini. Silakan coba lagi nanti.';
                        } else {
                            $otpId = bin2hex(random_bytes(16));
                            $code = generateOtpCode();
                            storeOtp($otpId, $normPhone, 'register', $code);
                            $send = sendOtpWhatsApp($normPhone, $code);
                            if (!$send['ok']) {
                                $error = 'Tidak dapat mengirim kode OTP: ' . $send['message'];
                            } else {
                                $_SESSION['pending_reg'] = [
                                    'otp_id'        => $otpId,
                                    'full_name'     => $fullName,
                                    'username'      => $username,
                                    'email'         => $email,
                                    'phone'         => $normPhone,
                                    'password_hash' => $hashedPassword,
                                    'redirect'      => $redirect,
                                    'test_code'     => $send['code'] ?? '',
                                ];
                                $info = 'Kode OTP telah dikirim ke WhatsApp ' . maskPhone($normPhone) . '.';
                                $step = 'verify';
                            }
                        }
                    } else {
                        // OTP nonaktif — registrasi langsung (perilaku sebelumnya)
                        $finalizeRegistration($conn, [
                            'full_name' => $fullName,
                            'username'  => $username,
                            'email'     => $email,
                            'phone'     => $phone,
                            'password_hash' => $hashedPassword,
                            'redirect'  => $redirect,
                        ]);
                    }
                }
            } else {
                $error = 'Koneksi database gagal. Silakan coba lagi.';
            }
        }
    }
}

$page_title = 'Daftar Akun';
include '../includes/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div style="max-width: 560px; margin: 0 auto;">
            <?php if ($step === 'verify'): ?>
                <!-- ============ STEP 2: VERIFIKASI OTP ============ -->
                <?php $pending = $_SESSION['pending_reg'] ?? null; ?>
                <div style="text-align: center; margin-bottom: var(--space-2xl);">
                    <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                        Verifikasi <span class="gold-text">WhatsApp</span>
                    </h1>
                    <p style="color: var(--text-muted);">
                        Kode OTP telah dikirim ke <strong><?= htmlspecialchars(maskPhone($pending['phone'] ?? '')) ?></strong>
                    </p>
                </div>

                <?php if ($info): ?>
                    <div style="padding: 12px 16px; background: #DBEAFE; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #2563EB; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-info-circle"></i>
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pending['test_code'])): ?>
                    <div style="padding: 14px 16px; background: #FEF3C7; border: 1px dashed #F59E0B; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #92400E; font-size: var(--text-sm);">
                        <i class="fas fa-flask"></i> <strong>MODE UJI:</strong> kode OTP Anda adalah
                        <div style="font-size: 1.6rem; font-weight: 700; letter-spacing: 6px; margin: 6px 0;"><?= htmlspecialchars($pending['test_code']) ?></div>
                        <small>Matikan "Mode Uji" di Pengaturan admin agar kode benar-benar dikirim ke WhatsApp.</small>
                    </div>
                <?php endif; ?>

                <div class="panel-card">
                    <form method="POST" action="">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                        <div class="form-group">
                            <label class="form-label">Kode OTP (6 digit)</label>
                            <input type="text" name="otp_code" class="form-input"
                                   placeholder="• • • • • •" required
                                   inputmode="numeric" autocomplete="one-time-code"
                                   maxlength="6" pattern="[0-9]{6}"
                                   style="text-align: center; font-size: 1.5rem; letter-spacing: 8px; font-weight: 700;"
                                   value="<?= htmlspecialchars($_POST['otp_code'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-full">
                            <i class="fas fa-check-circle"></i>
                            Verifikasi &amp; Daftar
                        </button>
                    </form>

                    <div class="otp-actions">
                        <form method="POST" action="" style="margin: 0;">
                            <button type="submit" name="resend" value="1" class="btn btn-outline" style="font-size: var(--text-sm);">
                                <i class="fas fa-rotate-right"></i> Kirim Ulang
                            </button>
                        </form>
                        <form method="POST" action="" style="margin: 0;">
                            <button type="submit" name="cancel" value="1" class="btn btn-outline" style="font-size: var(--text-sm); color: var(--text-muted);">
                                <i class="fas fa-pen"></i> Ubah Data / Nomor
                            </button>
                        </form>
                    </div>
                </div>

                <div style="text-align: center; margin-top: var(--space-xl);">
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">
                        Sudah punya akun?
                        <a href="<?= SITE_URL ?>/auth/login.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>" style="color: var(--soft-gold); font-weight: 600;">Masuk</a>
                    </p>
                </div>

            <?php else: ?>
                <!-- ============ STEP 1: FORM PENDAFTARAN ============ -->
                <div style="text-align: center; margin-bottom: var(--space-2xl);">
                    <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                        Daftar <span class="gold-text">Akun</span>
                    </h1>
                    <p style="color: var(--text-muted);">Daftar akun Nadhira Napoleon dan nikmati kemudahan berbelanja</p>
                </div>

                <div class="panel-card">
                    <?php if ($error): ?>
                        <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($otpEnabled): ?>
                        <div style="padding: 12px 16px; background: #ECFDF5; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #047857; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                            <i class="fab fa-whatsapp"></i>
                            Setelah mengisi form, Anda akan menerima <strong>kode OTP via WhatsApp</strong> untuk verifikasi.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Nama Lengkap <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="full_name" class="form-input" placeholder="Nama lengkap" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Username <span style="color: #EF4444;">*</span></label>
                                <input type="text" name="username" class="form-input" placeholder="Username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Email <span style="color: #EF4444;">*</span></label>
                                <input type="email" name="email" class="form-input" placeholder="Masukkan email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">No. WhatsApp <?= $otpEnabled ? '<span style="color: #EF4444;">*</span>' : '' ?></label>
                                <input type="tel" name="phone" class="form-input" placeholder="contoh: 0812xxxxxxx" <?= $otpEnabled ? 'required' : '' ?> value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                <?php if ($otpEnabled): ?>
                                    <small style="color: var(--text-muted); font-size: 11px;"><i class="fab fa-whatsapp"></i> Nomor WA aktif untuk menerima kode OTP</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="grid grid-2" style="gap: var(--space-lg);">
                            <div class="form-group">
                                <label class="form-label">Password <span style="color: #EF4444;">*</span></label>
                                <input type="password" name="password" class="form-input" placeholder="Buat password" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Konfirmasi Password <span style="color: #EF4444;">*</span></label>
                                <input type="password" name="confirm_password" class="form-input" placeholder="Ulangi password" required minlength="6">
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="display: flex; align-items: flex-start; gap: var(--space-sm); font-size: var(--text-sm); cursor: pointer;">
                                <input type="checkbox" name="agree" required style="width: 16px; height: 16px; accent-color: var(--soft-gold); margin-top: 2px;">
                                <span>Saya setuju dengan <a href="<?= SITE_URL ?>/pages/terms.php" target="_blank" rel="noopener" style="color: var(--soft-gold); text-decoration: underline;">Syarat & Ketentuan</a> dan <a href="<?= SITE_URL ?>/pages/privacy.php" target="_blank" rel="noopener" style="color: var(--soft-gold); text-decoration: underline;">Kebijakan Privasi</a> Nadhira Napoleon</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-full">
                            <i class="fas fa-user-plus"></i>
                            Daftar Sekarang
                        </button>
                    </form>

                    <div style="margin-top: var(--space-xl);">
                        <p style="text-align: center; font-size: var(--text-sm); color: var(--text-muted);">
                            Sudah punya akun? 
                            <a href="<?= SITE_URL ?>/auth/login.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>" style="color: var(--soft-gold); font-weight: 600;">Masuk</a>
                        </p>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
