<?php
require_once 'config/database.php';
http_response_code(404);
$page_title = 'Halaman Tidak Ditemukan - 404';
include 'includes/header.php';
?>

<section style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding-top: calc(var(--navbar-total-height, 80px) + 8px);">
    <div class="container" style="text-align: center; max-width: 600px;">
        <div style="font-size: 8rem; font-family: var(--font-display); font-weight: 700; color: var(--soft-gold); line-height: 1; margin-bottom: var(--space-md);">404</div>
        <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 600; margin-bottom: var(--space-lg);">
            Halaman Tidak <span class="gold-text">Ditemukan</span>
        </h1>
        <p style="color: var(--text-muted); font-size: var(--text-lg); margin-bottom: var(--space-2xl);">
            Maaf, halaman yang Anda cari tidak ditemukan atau telah dipindahkan. 
            Silakan kembali ke halaman utama atau cari produk favorit Anda.
        </p>
        <div style="display: flex; justify-content: center; gap: var(--space-md); flex-wrap: wrap;">
            <a href="<?= SITE_URL ?>" class="btn btn-primary btn-lg">
                <i class="fas fa-home"></i>
                Kembali ke Beranda
            </a>
            <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-th-large"></i>
                Lihat Produk
            </a>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
