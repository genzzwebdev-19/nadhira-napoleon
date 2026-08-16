<?php
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/mail.php';

$error = '';
$info  = '';

// Token & email dibawa dari link di email (GET) atau dari form (POST)
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));

$valid = isValidPasswordResetToken($email, $token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$valid) {
        $error = 'Link reset password tidak valid atau sudah kedaluwarsa. Silakan minta link baru dari halaman Lupa Password.';
    } else {
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 6) {
            $error = 'Password minimal 6 karakter';
        } elseif ($password !== $confirmPassword) {
            $error = 'Konfirmasi password tidak cocok';
        } else {
            $conn = getConnection();
            $email_e = $conn->real_escape_string($email);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET password = '$hash' WHERE email = '$email_e'");
            consumePasswordResetToken($email, $token); // token hanya bisa dipakai sekali

            // Bersihkan sesi login lama agar tidak bentrok dengan password baru
            if (isLoggedIn()) {
                session_regenerate_id(true);
                unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['session_token']);
            }

            header('Location: ' . SITE_URL . '/auth/login.php?reset=1');
            exit;
        }
    }
}

$page_title = 'Buat Password Baru';
include __DIR__ . '/../includes/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div style="max-width: 480px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: var(--space-2xl);">
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                    Password <span class="gold-text">Baru</span>
                </h1>
                <p style="color: var(--text-muted);">Buat password baru untuk akun Anda.</p>
            </div>

            <div class="panel-card">
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($valid): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">

                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password" class="form-input" placeholder="Minimal 6 karakter" required minlength="6" autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Konfirmasi Password</label>
                        <input type="password" name="confirm_password" class="form-input" placeholder="Ulangi password baru" required minlength="6" autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-key"></i>
                        Simpan Password Baru
                    </button>
                </form>

                <div style="text-align: center; margin-top: var(--space-xl); padding-top: var(--space-xl); border-top: 1px solid var(--soft-grey);">
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">
                        <a href="<?= SITE_URL ?>/auth/login.php" style="color: var(--soft-gold); font-weight: 600;">Kembali ke halaman masuk</a>
                    </p>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: var(--space-lg) 0;">
                    <p style="color: var(--text-muted); margin-bottom: var(--space-lg);">
                        <i class="fas fa-exclamation-triangle" style="color: var(--warm-orange); font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                        Link reset password tidak valid, sudah dipakai, atau kedaluwarsa.
                    </p>
                    <a href="<?= SITE_URL ?>/auth/forgot-password.php" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Minta Link Baru
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
