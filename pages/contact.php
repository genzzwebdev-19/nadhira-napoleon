<?php
// ============================================
// CONTACT FORM HANDLER
// Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Batas pengiriman pesan per IP (anti spam form kontak)
    if (function_exists('rateLimitIp') && !rateLimitIp('contact', 5, 3600)) {
        $error = 'Terlalu banyak pesan dikirim dari alamat ini. Silakan coba lagi nanti.';
    } elseif (empty($name) || empty($email) || empty($message)) {
        $error = 'Mohon isi nama, email, dan pesan';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } else {
        $conn = getConnection();
        if ($conn) {
            $name = $conn->real_escape_string($name);
            $email = $conn->real_escape_string($email);
            $phone = $conn->real_escape_string($phone);
            $message = $conn->real_escape_string($message);

            $result = $conn->query("INSERT INTO contacts (name, email, phone, message) VALUES ('$name', '$email', '$phone', '$message')");
            if ($result) {
                $success = true;
            } else {
                $error = 'Gagal mengirim pesan. Silakan coba lagi.';
            }
        } else {
            $error = 'Koneksi database gagal. Silakan coba lagi.';
        }
    }
}

$page_title = $success ? 'Pesan Terkirim' : 'Hubungi Kami';
include '../includes/header.php';
?>

<section style="min-height: 80vh; display: flex; align-items: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
    <div class="container">
        <div style="max-width: 600px; margin: 0 auto; text-align: center;">
            <?php if ($success): ?>
                <div style="width: 80px; height: 80px; border-radius: 50%; background: #D1FAE5; display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-xl);">
                    <i class="fas fa-check-circle" style="font-size: 2.5rem; color: #059669;"></i>
                </div>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-lg);">
                    Pesan <span class="gold-text">Terkirim!</span>
                </h1>
                <p style="color: var(--text-muted); font-size: var(--text-lg); margin-bottom: var(--space-2xl);">
                    Terima kasih <?= htmlspecialchars($name) ?>, pesan Anda sudah kami terima. Tim kami akan menghubungi Anda segera.
                </p>
                <a href="<?= SITE_URL ?>" class="btn btn-primary btn-lg">
                    <i class="fas fa-home"></i>
                    Kembali ke Beranda
                </a>
            <?php else: ?>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-lg);">
                    Hubungi <span class="gold-text">Kami</span>
                </h1>
                <p style="color: var(--text-muted); margin-bottom: var(--space-2xl);">
                    Silakan kirim pesan melalui form di halaman utama
                </p>
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; background: #FEE2E2; border-radius: var(--radius-md); margin-bottom: var(--space-lg); color: #DC2626; font-size: var(--text-sm);">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>#contact" class="btn btn-primary btn-lg">
                    <i class="fas fa-arrow-left"></i>
                    Kembali ke Form Kontak
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
