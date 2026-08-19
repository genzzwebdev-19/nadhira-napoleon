<?php
require_once __DIR__ . '/../config/rbac.php';

// Redirect balik (mis. dari checkout) — hanya path internal halaman (/pages/...) yang diizinkan.
// Tolak redirect eksternal, protokol-relative, backslash, dan encoding % (cegah open redirect).
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
if ($redirect !== '' && (strpos($redirect, 'http') === 0 || strpos($redirect, '//') === 0 || $redirect[0] !== '/' || strpos($redirect, '\\') !== false || strpos($redirect, '%') !== false || strpos($redirect, '/pages/') !== 0)) {
    $redirect = '';
}

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdminUser()) {
        header('Location: ' . SITE_URL . '/admin/index.php');
    } else {
        header('Location: ' . SITE_URL . ($redirect !== '' ? $redirect : ''));
    }
    exit;
}

$error = '';
$info = '';

// Notifikasi auto-logout karena idle/sesi berakhir
if (isset($_GET['expired'])) {
    $info = 'Sesi Anda berakhir karena tidak aktif. Silakan masuk kembali.';
}
// Password berhasil direset (dari auth/reset-password.php)
if (isset($_GET['reset'])) {
    $info = 'Password berhasil diubah. Silakan masuk dengan password baru Anda.';
}
// Datang dari checkout — ajak pengguna masuk untuk melanjutkan pesanan
if ($redirect !== '' && $info === '') {
    $info = 'Silakan masuk ke akun Anda untuk melanjutkan pemesanan.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Silakan isi email dan password';
    } elseif (function_exists('rateLimitIp') && !rateLimitIp('login', 20, 900)) {
        // Batas percobaan per IP (15 menit) — anti bruteforce lintas akun
        $error = 'Terlalu banyak percobaan masuk. Silakan coba lagi nanti.';
    } else {
        $conn = getConnection();
        if ($conn) {
            $email = $conn->real_escape_string($email);
            $result = $conn->query("SELECT * FROM users WHERE email = '$email' LIMIT 1");

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $uid = (int)$user['id'];

                // 1. Cek akun nonaktif (suspend)
                if (!(int)$user['is_active']) {
                    logLoginAttempt($uid, $email, false);
                    $error = 'Email atau password salah.';
                }
                // 2. Cek akun dikunci permanen
                elseif ((int)$user['is_locked']) {
                    logLoginAttempt($uid, $email, false);
                    $error = 'Email atau password salah.';
                }
                // 3. Cek lock sementara (terlalu banyak gagal login)
                elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                    logLoginAttempt($uid, $email, false);
                    $error = 'Email atau password salah.';
                }
                // 4. Verifikasi password
                elseif (!password_verify($password, $user['password'])) {
                    $newAttempts = (int)$user['failed_attempts'] + 1;
                    if ($newAttempts >= RBAC_MAX_FAILED_ATTEMPTS) {
                        $lockUntil = date('Y-m-d H:i:s', time() + RBAC_LOCK_MINUTES * 60);
                        $conn->query("UPDATE users SET failed_attempts = $newAttempts, locked_until = '$lockUntil' WHERE id = $uid");
                    } else {
                        $conn->query("UPDATE users SET failed_attempts = $newAttempts WHERE id = $uid");
                    }
                    logLoginAttempt($uid, $email, false);
                    // Pesan seragam untuk semua kegagalan — cegah enumerasi akun (OWASP)
                    $error = 'Email atau password salah.';
                }
                // 5. Berhasil login
                else {
                    // Reset counter gagal & kunci
                    $conn->query("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = $uid");

                    // ID sesi lama — keranjang tamu tersimpan dengan ID ini (sebelum regenerate)
                    $oldSid = session_id();

                    // Regenerasi ID sesi (cegah session fixation)
                    session_regenerate_id(true);

                    // Buat token sesi di database
                    $token = createUserSession($uid);
                    $_SESSION['user_id'] = $uid;
                    $_SESSION['user_name'] = $user['full_name'];
                    if ($token) $_SESSION['session_token'] = $token;

                    // Gabungkan keranjang tamu (sesi lama) ke keranjang akun agar tidak hilang
                    if (function_exists('mergeGuestCartToUser')) {
                        mergeGuestCartToUser($uid, $oldSid);
                    }

                    // Remember me — simpan preferensi user & cookie login (7 hari).
                    // Preferensi default AKTIF; jika dicentang → cookie dibuat agar
                    // tidak perlu login ulang di kunjungan berikutnya.
                    setRememberPreference($remember);
                    if ($remember && $token) {
                        setRememberCookie($token);
                    } else {
                        clearRememberCookie(); // buang cookie lama bila user memilih tidak diingat
                    }

                    // Audit & history
                    logLoginAttempt($uid, $email, true);
                    logActivity('login', 'auth', 'Login berhasil dari ' . getClientIp());

                    // Redirect admin ke dashboard; customer ke halaman asal (redirect) atau homepage
                    if (isAdminUser($uid)) {
                        header('Location: ' . SITE_URL . '/admin/index.php');
                    } elseif ($redirect !== '') {
                        header('Location: ' . SITE_URL . $redirect);
                    } else {
                        header('Location: ' . SITE_URL);
                    }
                    exit;
                }
            } else {
                logLoginAttempt(null, $email, false);
                $error = 'Email atau password salah';
            }
        } else {
            $error = 'Koneksi database gagal. Silakan coba lagi.';
        }
    }
}

$page_title = 'Masuk';
include __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div style="max-width: 480px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: var(--space-2xl);">
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                    Selamat <span class="gold-text">Datang</span>
                </h1>
                <p style="color: var(--text-muted);">Masuk ke akun Nadhira Napoleon Anda</p>
            </div>

            <div class="panel-card">
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if ($info): ?>
                    <div style="padding: 12px 16px; background: #DBEAFE; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #2563EB; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-info-circle"></i>
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" placeholder="Masukkan email Anda" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-input" placeholder="Masukkan password" required>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-xl); gap: var(--space-md); flex-wrap: wrap;">
                        <label style="display: flex; align-items: flex-start; gap: var(--space-sm); font-size: var(--text-sm); cursor: pointer;">
                            <input type="checkbox" name="remember" <?= rememberPrefEnabled() ? 'checked' : '' ?> style="width: 16px; height: 16px; margin-top: 2px; accent-color: var(--soft-gold);">
                            <span>
                                Ingat saya
                                <small style="display: block; color: var(--text-muted); font-size: 12px; margin-top: 2px;">Aktif secara default — biarkan dicentang agar tetap login di kunjungan berikutnya.</small>
                            </span>
                        </label>
                        <a href="<?= SITE_URL ?>/auth/forgot-password.php" style="font-size: var(--text-sm); color: var(--soft-gold); white-space: nowrap;">Lupa password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-sign-in-alt"></i>
                        Masuk
                    </button>
                </form>

                <div style="text-align: center; margin-top: var(--space-xl); padding-top: var(--space-xl); border-top: 1px solid var(--soft-grey);">
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">
                        Belum punya akun?
                        <a href="<?= SITE_URL ?>/auth/register.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>" style="color: var(--soft-gold); font-weight: 600;">Daftar Sekarang</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
