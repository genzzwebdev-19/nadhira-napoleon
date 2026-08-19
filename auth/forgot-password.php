<?php
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/mail.php';

$error = '';
$info  = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Silakan isi email Anda';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } elseif ((function_exists('rateLimitIp') && !rateLimitIp('forgot', 5, 3600))
              || (function_exists('rateLimitAllow') && !rateLimitAllow('forgot-email:' . strtolower($email), 3, 3600))) {
        // Batas permintaan reset per IP & per email (anti spam email / pemakaian kuota SMTP)
        $error = 'Terlalu banyak permintaan reset password. Silakan coba lagi nanti.';
    } else {
        $res = sendPasswordResetEmail($email);
        if ($res['ok']) {
            // Pesan netral (juga untuk email yang tidak terdaftar) — anti enumerasi akun
            $info = 'Jika email tersebut terdaftar, link reset password sudah kami kirim. Periksa kotak masuk (atau folder spam) Anda.';
        } else {
            $error = 'Gagal mengirim email: ' . htmlspecialchars($res['error']);
        }
    }
}

$page_title = 'Lupa Password';
include __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div style="max-width: 480px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: var(--space-2xl);">
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                    Lupa <span class="gold-text">Password</span>
                </h1>
                <p style="color: var(--text-muted);">Masukkan email akun Anda — kami kirimkan link untuk membuat password baru.</p>
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
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" placeholder="Masukkan email Anda" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-paper-plane"></i>
                        Kirim Link Reset
                    </button>
                </form>

                <div style="text-align: center; margin-top: var(--space-xl); padding-top: var(--space-xl); border-top: 1px solid var(--soft-grey);">
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">
                        Sudah ingat password?
                        <a href="<?= SITE_URL ?>/auth/login.php" style="color: var(--soft-gold); font-weight: 600;">Masuk</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
