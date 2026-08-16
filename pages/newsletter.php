<?php
// ============================================
// NEWSLETTER SUBSCRIPTION HANDLER
// Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$page_title = 'Newsletter';
include '../includes/header.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } else {
        $conn = getConnection();
        if ($conn) {
            $email = $conn->real_escape_string($email);
            
            // Check if already subscribed
            $check = $conn->query("SELECT id FROM newsletter_subscribers WHERE email = '$email' LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $success = true; // Already subscribed, just show success
            } else {
                $result = $conn->query("INSERT INTO newsletter_subscribers (email) VALUES ('$email')");
                if ($result) {
                    $success = true;
                } else {
                    $error = 'Gagal berlangganan. Silakan coba lagi.';
                }
            }
        } else {
            $error = 'Koneksi database gagal. Silakan coba lagi.';
        }
    }
}
?>

<section style="min-height: 80vh; display: flex; align-items: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
    <div class="container">
        <div style="max-width: 600px; margin: 0 auto; text-align: center;">
            <?php if ($success): ?>
                <div style="width: 80px; height: 80px; border-radius: 50%; background: #D1FAE5; display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-xl);">
                    <i class="fas fa-envelope-open-text" style="font-size: 2.5rem; color: #059669;"></i>
                </div>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-lg);">
                    Berlangganan <span class="gold-text">Berhasil!</span>
                </h1>
                <p style="color: var(--text-muted); font-size: var(--text-lg); margin-bottom: var(--space-2xl);">
                    Terima kasih! Anda sekarang terdaftar di newsletter Nadhira Napoleon. Kami akan mengirimkan info promo dan produk terbaru ke email Anda.
                </p>
                <a href="<?= SITE_URL ?>" class="btn btn-primary btn-lg">
                    <i class="fas fa-home"></i>
                    Kembali ke Beranda
                </a>
            <?php else: ?>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-lg);">
                    Newsletter
                </h1>
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm);">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>#contact" class="btn btn-primary btn-lg">
                    <i class="fas fa-arrow-left"></i>
                    Kembali ke Beranda
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
