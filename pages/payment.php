<?php
// ============================================
// HALAMAN PEMBAYARAN MIDTRANS SNAP
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';
require_once '../config/midtrans.php';

ensureMidtransSchema();

$conn = getConnection();
$orderNumber = trim($_GET['order'] ?? '');

if ($orderNumber === '' || !$conn) {
    header('Location: ' . SITE_URL);
    exit;
}

$orderNumber = $conn->real_escape_string($orderNumber);
$r = $conn->query("SELECT * FROM orders WHERE order_number = '$orderNumber' LIMIT 1");
if (!$r || $r->num_rows === 0) {
    header('Location: ' . SITE_URL . '/404.php');
    exit;
}
$order = $r->fetch_assoc();

// Access control (sama seperti halaman invoice)
$email = trim($_GET['email'] ?? '');
$accessDenied = true;
if (!empty($email) && strtolower($email) === strtolower($order['customer_email'])) {
    $accessDenied = false;
}
if (isLoggedIn() && (int)$_SESSION['user_id'] === (int)$order['user_id']) {
    $accessDenied = false;
}
if ($accessDenied) {
    header('Location: ' . SITE_URL . '/pages/tracking.php');
    exit;
}

// Bukan pesanan Midtrans / sudah lunas → lempar ke invoice
if ($order['payment_method'] !== 'midtrans' || $order['payment_status'] === 'paid') {
    header('Location: ' . SITE_URL . '/pages/invoice.php?order=' . urlencode($order['order_number']) . '&email=' . urlencode($order['customer_email']));
    exit;
}

// Ambil item pesanan
$orderItems = [];
$itemsResult = $conn->query("SELECT * FROM order_items WHERE order_id = {$order['id']}");
if ($itemsResult) {
    while ($it = $itemsResult->fetch_assoc()) $orderItems[] = $it;
}

// Buat Snap token
$snapToken = '';
$snapError = '';
if (midtransClientKey() === '') {
    $snapError = 'Kunci Client Key Midtrans belum diisi di Pengaturan Admin.';
} else {
    $tokenResult = midtransCreateSnapToken($order, $orderItems);
    if ($tokenResult['success']) {
        $snapToken = $tokenResult['token'];
    } else {
        $snapError = $tokenResult['message'];
    }
}

$page_title = 'Pembayaran';
$meta_description = 'Selesaikan pembayaran pesanan Anda dengan Midtrans.';
include '../includes/header.php';
?>

<section class="checkout-section" style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <a href="<?= SITE_URL ?>/pages/invoice.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($order['customer_email']) ?>">Invoice</a>
            <span class="separator">/</span>
            <span class="current">Pembayaran</span>
        </div>

        <div style="text-align: center; margin-bottom: var(--space-2xl);">
            <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-sm);">
                Selesaikan <span class="gold-text">Pembayaran</span>
            </h1>
            <p style="color: var(--text-muted); font-size: var(--text-lg);">
                Pilih metode pembayaran favorit Anda — Virtual Account, QRIS, E-Wallet, atau Kartu Kredit
            </p>
        </div>

        <?php if (!midtransIsProduction()): ?>
            <!-- Panduan Mode Uji Coba (Sandbox) -->
            <div style="max-width: 720px; margin: 0 auto var(--space-xl); background: #FFFBEB; border: 1px dashed #D4A853; border-radius: var(--radius-lg); padding: var(--space-lg) var(--space-xl);">
                <div style="display: flex; gap: var(--space-md); align-items: flex-start; flex-wrap: wrap;">
                    <i class="fas fa-flask" style="color: #B8860B; font-size: 20px; margin-top: 3px;"></i>
                    <div style="font-size: var(--text-sm); color: #6B5B2E;">
                        <strong style="font-size: var(--text-base);">Mode Uji Coba (Sandbox)</strong> — ini bukan pembayaran sungguhan, tidak ada uang yang dipindahkan.
                        <ul style="margin: var(--space-sm) 0 0; padding-left: 18px; line-height: 1.8;">
                            <li><strong>Kartu Kredit:</strong> gunakan nomor uji <code>4811 1111 1111 1114</code>, CVV bebas, kadaluarsa masa depan (mis. 12/30) → lalu pilih <em>Approve</em> di layar konfirmasi.</li>
                            <li><strong>Virtual Account:</strong> pilih bank mana saja (mis. BCA) → di popup Snap ada tombol <em>Bayar Simulasi</em> / <em>Simulate</em> untuk menandai lunas (atau tunggu ±2 menit, sandbox otomatis melunasi).</li>
                            <li><strong>QRIS / GoPay / DANA:</strong> aplikasi asli (DANA, GoPay, dll.) <strong>tidak bisa</strong> membayar kode QR sandbox. Gunakan tombol simulasi di dalam popup Snap saja.</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($snapToken !== ''): ?>
            <!-- Ringkasan Pesanan -->
            <div style="max-width: 720px; margin: 0 auto var(--space-xl); background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-xl) var(--space-2xl); box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-md);">
                    <div>
                        <div style="font-size: var(--text-sm); color: var(--text-muted);">Nomor Pesanan</div>
                        <div style="font-weight: 700; letter-spacing: 1px; font-size: var(--text-lg); color: var(--text-primary);"><?= htmlspecialchars($order['order_number']) ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: var(--text-sm); color: var(--text-muted);">Total Pembayaran</div>
                        <div style="font-weight: 700; font-size: var(--text-2xl); color: var(--warm-orange);">Rp <?= number_format($order['total'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-md); margin-top: var(--space-lg); padding-top: var(--space-lg); border-top: 1px dashed var(--soft-grey);">
                    <div style="font-size: var(--text-sm); color: var(--text-muted);">
                        <i class="fas fa-shield-alt" style="color: var(--soft-gold);"></i>
                        Pembayaran diproses aman oleh <strong style="color: var(--text-primary);">Midtrans</strong>
                    </div>
                    <a href="<?= SITE_URL ?>/pages/invoice.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($order['customer_email']) ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-arrow-left"></i> Kembali ke Invoice
                    </a>
                </div>
            </div>

            <!-- Container Snap (form pembayaran Midtrans) -->
            <div id="snap-container" style="max-width: 720px; margin: 0 auto;"></div>

            <div style="text-align: center; margin-top: var(--space-xl);">
                <button id="pay-button" class="btn btn-primary btn-lg">
                    <i class="fas fa-credit-card"></i> Buka Halaman Pembayaran
                </button>
                <p style="font-size: var(--text-xs); color: var(--text-light); margin-top: var(--space-md);">
                    <i class="fas fa-info-circle"></i>
                    Jika formulir pembayaran tidak muncul, klik tombol di atas
                </p>
            </div>
        <?php else: ?>
            <!-- Gagal membuat token -->
            <div style="max-width: 560px; margin: 0 auto;">
                <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-md); text-align: center;">
                    <div style="width: 72px; height: 72px; margin: 0 auto var(--space-lg); border-radius: 50%; background: #FEF2F2; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 28px; color: #DC2626;"></i>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-md);">
                        Pembayaran Belum Tersedia
                    </h3>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); margin-bottom: var(--space-xl);">
                        <?= htmlspecialchars($snapError) ?>
                    </p>
                    <div style="display: flex; gap: var(--space-md); justify-content: center; flex-wrap: wrap;">
                        <a href="<?= SITE_URL ?>/pages/invoice.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($order['customer_email']) ?>" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Kembali ke Invoice
                        </a>
                        <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/\D/', '', getSetting('contact_whatsapp', '6282112345678'))) ?>?text=<?= urlencode('Halo, saya ingin menyelesaikan pembayaran pesanan ' . $order['order_number']) ?>" target="_blank" class="btn btn-outline">
                            <i class="fab fa-whatsapp"></i> Hubungi Admin
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($snapToken !== ''): ?>
<script src="<?= midtransBaseUrl() ?>/snap/snap.js" data-client-key="<?= htmlspecialchars(midtransClientKey()) ?>"></script>
<script type="text/javascript">
(function () {
    // Setelah pembayaran selesai, arahkan langsung ke homepage (dengan penanda hasil)
    var homeUrl = '<?= SITE_URL ?>' + '/?pay=';

    var callbacks = {
        onSuccess: function (result) {
            window.location.href = homeUrl + 'success';
        },
        onPending: function (result) {
            window.location.href = homeUrl + 'pending';
        },
        onError: function (result) {
            window.location.href = homeUrl + 'error';
        },
        onClose: function () {
            // Pelanggan menutup popup tanpa menyelesaikan pembayaran
        }
    };

    var snapToken = <?= json_encode($snapToken) ?>;

    function startPay() {
        window.snap.pay(snapToken, callbacks);
    }

    // Tombol cadangan
    document.getElementById('pay-button').addEventListener('click', startPay);

    // Form pembayaran inline
    try {
        window.snap.embed(snapToken, {
            embedId: 'snap-container',
            onSuccess: callbacks.onSuccess,
            onPending: callbacks.onPending,
            onError: callbacks.onError,
            onClose: callbacks.onClose
        });
    } catch (e) {
        // embed gagal → pengguna bisa memakai tombol cadangan
    }
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
