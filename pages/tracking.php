<?php
// ============================================
// TRACKING ORDER - NADHIRA NAPOLEON
// Lacak status pesanan Anda
// ============================================
require_once '../config/database.php';
require_once '../config/midtrans.php';

ensureMidtransSchema();

$page_title = 'Tracking Order';
$meta_description = 'Lacak status pesanan Anda di Nadhira Napoleon Pekanbaru. Cek status pengiriman dan riwayat pesanan.';

$order = null;
$orderItems = [];
$error = '';
$searched = false;
$liveCourier = null;
$liveCourierLoc = null;

// Handle search
if (isset($_GET['order_number']) || isset($_POST['order_number'])) {
    $searched = true;
    $orderNumber = trim($_GET['order_number'] ?? '');
    $email = trim($_GET['email'] ?? '');

    if (empty($orderNumber)) {
        $error = 'Silakan masukkan nomor pesanan';
    } else {
        $conn = getConnection();
        if ($conn) {
            $orderNumber = $conn->real_escape_string($orderNumber);
            
            $query = "SELECT * FROM orders WHERE order_number = '$orderNumber'";
            if (!empty($email)) {
                $email = $conn->real_escape_string($email);
                $query .= " AND customer_email = '$email'";
            }
            $query .= " LIMIT 1";

            $result = $conn->query($query);
            
            if ($result && $result->num_rows > 0) {
                $order = $result->fetch_assoc();
                
                // Get order items
                $itemsResult = $conn->query("SELECT * FROM order_items WHERE order_id = {$order['id']}");
                if ($itemsResult) {
                    while ($item = $itemsResult->fetch_assoc()) {
                        $orderItems[] = $item;
                    }
                }
            } else {
                $error = 'Pesanan dengan nomor "' . htmlspecialchars($orderNumber) . '" tidak ditemukan.';
                if (!empty($email)) {
                    $error .= ' Periksa kembali nomor pesanan dan email Anda.';
                }
            }
        } else {
            $error = 'Koneksi database gagal. Silakan coba lagi.';
        }
    }
}

// Order status timeline config
$statusFlow = ['pending', 'processing', 'shipped', 'delivered'];
$statusLabels = [
    'pending' => 'Pesanan Dibuat',
    'processing' => 'Diproses',
    'shipped' => 'Dikirim',
    'delivered' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];
$statusIcons = [
    'pending' => 'fa-file-invoice',
    'processing' => 'fa-box',
    'shipped' => 'fa-truck',
    'delivered' => 'fa-check-circle',
    'cancelled' => 'fa-times-circle'
];

include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); min-height: 100vh;">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?= SITE_URL ?>">Home</a>
            <span class="separator">/</span>
            <span class="current">Tracking Order</span>
        </div>

        <div style="max-width: 800px; margin: 0 auto;">
            <!-- Header -->
            <div style="text-align: center; margin-bottom: var(--space-2xl);">
                <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); font-weight: 700; margin-bottom: var(--space-sm);">
                    Tracking <span class="gold-text">Order</span>
                </h1>
                <p style="color: var(--text-muted); font-size: var(--text-lg);">
                    Masukkan nomor pesanan untuk melacak status pengiriman Anda
                </p>
            </div>

            <!-- Search Form -->
            <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-md); margin-bottom: var(--space-2xl);" data-aos="fade-up">
                <form method="GET" action="">
                    <div class="grid grid-2" style="gap: var(--space-lg);">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Nomor Pesanan <span style="color: #EF4444;">*</span></label>
                            <input type="text" 
                                   name="order_number" 
                                   class="form-input" 
                                   placeholder="Contoh: INV-2024-001" 
                                   value="<?= htmlspecialchars($_GET['order_number'] ?? '') ?>"
                                   required
                                   style="font-weight: 600;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Email (opsional)</label>
                            <input type="email" 
                                   name="email" 
                                   class="form-input" 
                                   placeholder="Email saat pemesanan"
                                   value="<?= htmlspecialchars($_GET['email'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top: var(--space-lg);">
                        <i class="fas fa-search"></i>
                        Lacak Pesanan
                    </button>
                </form>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div style="padding: 20px; background: #FEF2F2; border: 1px solid #FEE2E2; border-radius: var(--radius-lg); text-align: center; margin-bottom: var(--space-xl);" data-aos="fade-up">
                    <div style="font-size: 3rem; margin-bottom: var(--space-md);">🔍</div>
                    <p style="color: #DC2626; font-weight: 500; margin-bottom: var(--space-sm);"><?= $error ?></p>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">
                        Tips: Nomor pesanan dapat ditemukan di email konfirmasi pesanan Anda.
                    </p>
                </div>
            <?php endif; ?>

            <!-- Order Result -->
            <?php if ($order): ?>
                <div data-aos="fade-up">
                    <!-- Status Banner -->
                    <div class="track-banner" style="background: linear-gradient(135deg, <?= $order['order_status'] === 'delivered' ? '#059669' : ($order['order_status'] === 'cancelled' ? '#DC2626' : '#D4A853') ?> 0%, <?= $order['order_status'] === 'delivered' ? '#10B981' : ($order['order_status'] === 'cancelled' ? '#EF4444' : '#E8853B') ?> 100%); border-radius: var(--radius-xl); padding: var(--space-2xl); color: var(--text-white); margin-bottom: var(--space-xl);">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-md);">
                            <div>
                                <p style="font-size: var(--text-sm); opacity: 0.8; margin-bottom: 4px;">Status Pesanan</p>
                                <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; color: var(--text-white);">
                                    <?= $statusLabels[$order['order_status']] ?? ucfirst($order['order_status']) ?>
                                </h2>
                            </div>
                            <div style="text-align: right;">
                                <p style="font-size: var(--text-sm); opacity: 0.8; margin-bottom: 4px;">Nomor Pesanan</p>
                                <p class="track-banner-number" style="font-size: var(--text-xl); font-weight: 700;"><?= htmlspecialchars($order['order_number']) ?></p>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Data kurir untuk live tracking
                    $liveCourier = null;
                    $liveCourierLoc = null;
                    if (!empty($order['courier_id']) && in_array($order['order_status'], ['processing', 'shipped'])) {
                        $liveCourier = getCourier((int)$order['courier_id']);
                        if ($liveCourier) {
                            $liveCourierLoc = getLatestCourierLocation((int)$liveCourier['id']);
                        }
                    }
                    ?>
                    <?php if ($liveCourier): ?>
                    <!-- Live Courier Tracking -->
                    <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm); margin-bottom: var(--space-xl);">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-lg);">
                            <i class="fas fa-satellite-dish" style="color: var(--soft-gold);"></i>
                            Lacak Kurir (Real-Time)
                        </h3>
                        <p style="font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-md);">
                            Kurir: <strong style="color: var(--warm-orange);"><?= htmlspecialchars($liveCourier['name']) ?></strong>
                            <?php if (!empty($order['distance_km'])): ?> · Jarak pengiriman ± <?= number_format((float)$order['distance_km'], 1, ',', '.') ?> km · Estimasi <?= formatDuration(estimateDeliveryMinutes((float)$order['distance_km'])) ?><?php endif; ?>
                        </p>
                        <div id="track-map" class="track-map" style="border-radius: var(--radius-lg); border: 1px solid var(--soft-grey);"></div>
                        <p id="track-status" style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-md);">
                            <i class="fas fa-satellite"></i> Mencari posisi kurir...
                        </p>
                    </div>
                    <input type="hidden" id="track-courier-id" value="<?= (int)$liveCourier['id'] ?>">
                    <input type="hidden" id="track-courier-name" value="<?= htmlspecialchars($liveCourier['name'], ENT_QUOTES) ?>">
                    <input type="hidden" id="track-cust-lat" value="<?= (float)($order['latitude'] ?? 0) ?>">
                    <input type="hidden" id="track-cust-lng" value="<?= (float)($order['longitude'] ?? 0) ?>">
                    <input type="hidden" id="track-init-lat" value="<?= $liveCourierLoc ? (float)$liveCourierLoc['latitude'] : '' ?>">
                    <input type="hidden" id="track-init-lng" value="<?= $liveCourierLoc ? (float)$liveCourierLoc['longitude'] : '' ?>">
                    <?php endif; ?>

                    <div class="track-grid-2">
                        <!-- Timeline -->
                        <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
                            <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-xl);">
                                <i class="fas fa-clock" style="color: var(--soft-gold);"></i>
                                Timeline Pesanan
                            </h3>

                            <div style="position: relative; padding-left: 32px;">
                                <!-- Vertical Line -->
                                <div style="position: absolute; left: 12px; top: 8px; bottom: 8px; width: 2px; background: var(--soft-grey);"></div>

                                <?php 
                                $currentStatus = $order['order_status'];
                                $currentIndex = array_search($currentStatus, $statusFlow);
                                
                                foreach ($statusFlow as $index => $status): 
                                    $isActive = $index <= $currentIndex;
                                    $isCurrent = $status === $currentStatus;
                                ?>
                                    <div style="position: relative; padding-bottom: var(--space-xl); <?= $index === count($statusFlow) - 1 ? 'padding-bottom: 0;' : '' ?>">
                                        <!-- Dot -->
                                        <div style="position: absolute; left: -26px; top: 4px; width: 24px; height: 24px; border-radius: 50%; background: <?= $isActive ? 'var(--luxury-gradient)' : 'var(--soft-grey)' ?>; display: flex; align-items: center; justify-content: center; border: 3px solid <?= $isActive ? '#D4A853' : 'var(--warm-white)' ?>; z-index: 2;">
                                            <i class="fas <?= $statusIcons[$status] ?>" style="font-size: 10px; color: <?= $isActive ? '#FFF' : '#A0886A' ?>;"></i>
                                        </div>
                                        <!-- Content -->
                                        <div>
                                            <p style="font-weight: 600; font-size: var(--text-base); color: <?= $isCurrent ? 'var(--warm-orange)' : ($isActive ? 'var(--text-primary)' : 'var(--text-light)') ?>;">
                                                <?= $statusLabels[$status] ?>
                                                <?php if ($isCurrent): ?>
                                                    <span style="display: inline-block; margin-left: 8px; padding: 2px 8px; background: var(--luxury-gradient); color: #FFF; border-radius: var(--radius-full); font-size: 10px;">Saat Ini</span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($isActive): ?>
                                                <p style="font-size: var(--text-sm); color: var(--text-muted); margin-top: 2px;">
                                                    <?php if ($status === 'pending'): ?>
                                                        Pesanan telah dibuat dan menunggu konfirmasi
                                                    <?php elseif ($status === 'processing'): ?>
                                                        Pesanan sedang diproses oleh tim kami
                                                    <?php elseif ($status === 'shipped'): ?>
                                                        Pesanan telah dikirim dan dalam perjalanan
                                                    <?php elseif ($status === 'delivered'): ?>
                                                        Pesanan telah diterima. Selamat menikmati!
                                                    <?php endif; ?>
                                                </p>
                                            <?php else: ?>
                                                <p style="font-size: var(--text-sm); color: var(--text-light); margin-top: 2px; font-style: italic;">Menunggu</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($order['order_status'] === 'cancelled'): ?>
                                <div style="margin-top: var(--space-lg); padding: var(--space-lg); background: #FEF2F2; border-radius: var(--radius-md);">
                                    <p style="color: #DC2626; font-weight: 500;">
                                        <i class="fas fa-times-circle"></i>
                                        Pesanan ini telah dibatalkan.
                                    </p>
                                    <?php if ($order['notes']): ?>
                                        <p style="font-size: var(--text-sm); color: var(--text-muted); margin-top: var(--space-sm);">
                                            Alasan: <?= htmlspecialchars($order['notes']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Order Info -->
                        <div style="display: flex; flex-direction: column; gap: var(--space-xl);">
                            <!-- Shipping Info -->
                            <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
                                <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-lg);">
                                    <i class="fas fa-truck" style="color: var(--soft-gold);"></i>
                                    Informasi Pengiriman
                                </h3>
                                <?php if ($order['tracking_number']): ?>
                                <div style="margin-bottom: var(--space-lg);">
                                    <p style="font-size: var(--text-sm); color: var(--text-muted);">No. Resi</p>
                                    <p style="font-weight: 600; font-size: var(--text-lg);"><?= htmlspecialchars($order['tracking_number']) ?></p>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Alamat</p>
                                    <p style="font-weight: 500;"><?= htmlspecialchars($order['customer_name']) ?></p>
                                    <p style="font-size: var(--text-sm); color: var(--text-secondary);"><?= htmlspecialchars($order['shipping_address']) ?></p>
                                    <p style="font-size: var(--text-sm); color: var(--text-secondary);">
                                        <?= htmlspecialchars($order['shipping_city']) ?>, <?= htmlspecialchars($order['shipping_province']) ?> <?= htmlspecialchars($order['shipping_postal_code']) ?>
                                    </p>
                                    <p style="font-size: var(--text-sm); color: var(--text-secondary);"><?= htmlspecialchars($order['customer_phone']) ?></p>
                                </div>
                            </div>

                            <!-- Payment Info -->
                            <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
                                <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-lg);">
                                    <i class="fas fa-credit-card" style="color: var(--soft-gold);"></i>
                                    Informasi Pembayaran
                                </h3>
                                <div class="track-pay-grid">
                                    <div>
                                        <p style="font-size: var(--text-sm); color: var(--text-muted);">Metode</p>
                                        <p style="font-weight: 500; text-transform: capitalize;"><?= str_replace('_', ' ', $order['payment_method']) ?></p>
                                    </div>
                                    <div>
                                        <p style="font-size: var(--text-sm); color: var(--text-muted);">Status</p>
                                        <span style="display: inline-block; padding: 4px 12px; border-radius: var(--radius-full); font-size: var(--text-xs); font-weight: 500; background: <?= $order['payment_status'] === 'paid' ? '#D1FAE5' : ($order['payment_status'] === 'pending' ? '#FEF3C7' : '#FEE2E2') ?>; color: <?= $order['payment_status'] === 'paid' ? '#059669' : ($order['payment_status'] === 'pending' ? '#D97706' : '#DC2626') ?>;">
                                            <?= ucfirst($order['payment_status']) ?>
                                        </span>
                                    </div>
                                    <?php if ($order['payment_method'] === 'midtrans' && !empty($order['midtrans_payment_type'])): ?>
                                    <div>
                                        <p style="font-size: var(--text-sm); color: var(--text-muted);">Kanal Pembayaran</p>
                                        <p style="font-weight: 500;"><?= htmlspecialchars(midtransPaymentLabel($order['midtrans_payment_type'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($order['payment_method'] === 'midtrans' && !empty($order['midtrans_va_number'])): ?>
                                    <div>
                                        <p style="font-size: var(--text-sm); color: var(--text-muted);">No. Virtual Account</p>
                                        <p style="font-weight: 500; font-family: monospace;"><?= htmlspecialchars($order['midtrans_va_number']) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div style="background: var(--warm-white); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm); margin-bottom: var(--space-xl);">
                        <h3 style="font-family: var(--font-display); font-size: var(--text-xl); font-weight: 600; margin-bottom: var(--space-xl);">
                            <i class="fas fa-shopping-bag" style="color: var(--soft-gold);"></i>
                            Detail Pesanan
                        </h3>

                        <div style="overflow-x: auto;">
                            <table class="track-items-table" style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="border-bottom: 2px solid var(--soft-grey);">
                                        <th style="text-align: left; padding: var(--space-md); font-size: var(--text-sm); color: var(--text-muted);">Produk</th>
                                        <th style="text-align: center; padding: var(--space-md); font-size: var(--text-sm); color: var(--text-muted);">Harga</th>
                                        <th style="text-align: center; padding: var(--space-md); font-size: var(--text-sm); color: var(--text-muted);">Jumlah</th>
                                        <th style="text-align: right; padding: var(--space-md); font-size: var(--text-sm); color: var(--text-muted);">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orderItems as $item): ?>
                                    <tr style="border-bottom: 1px solid var(--soft-grey);">
                                        <td style="padding: var(--space-md); display: flex; align-items: center; gap: var(--space-md);">
                                            <?php if ($item['product_image']): ?>
                                                <img src="<?= htmlspecialchars($item['product_image']) ?>" alt="" style="width: 48px; height: 48px; border-radius: var(--radius-sm); object-fit: cover;" loading="lazy">
                                            <?php else: ?>
                                                <div style="width: 48px; height: 48px; border-radius: var(--radius-sm); background: var(--soft-grey); display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-box" style="color: var(--text-light);"></i>
                                                </div>
                                            <?php endif; ?>
                                            <span style="font-weight: 500;"><?= htmlspecialchars($item['product_name']) ?></span>
                                        </td>
                                        <td style="padding: var(--space-md); text-align: center; color: var(--text-muted);">Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
                                        <td style="padding: var(--space-md); text-align: center;">x<?= $item['quantity'] ?></td>
                                        <td style="padding: var(--space-md); text-align: right; font-weight: 600;">Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="padding: var(--space-md); text-align: right; color: var(--text-muted);">Subtotal</td>
                                        <td style="padding: var(--space-md); text-align: right;">Rp <?= number_format($order['subtotal'], 0, ',', '.') ?></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" style="padding: var(--space-md); text-align: right; color: var(--text-muted);">Ongkos Kirim</td>
                                        <td style="padding: var(--space-md); text-align: right;"><?= $order['shipping_cost'] > 0 ? 'Rp ' . number_format($order['shipping_cost'], 0, ',', '.') : '<strong style="color: #059669;">GRATIS</strong>' ?></td>
                                    </tr>
                                    <?php if ($order['discount'] > 0): ?>
                                    <tr>
                                        <td colspan="3" style="padding: var(--space-md); text-align: right; color: #059669;">Diskon<?= !empty($order['promo_code']) ? ' (kode promo)' : '' ?></td>
                                        <td style="padding: var(--space-md); text-align: right; color: #059669;">-Rp <?= number_format($order['discount'], 0, ',', '.') ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($order['promo_code'])): ?>
                                    <tr>
                                        <td colspan="3" style="padding: var(--space-md); text-align: right; color: var(--text-muted); font-size: var(--text-xs);">Kode promo</td>
                                        <td style="padding: var(--space-md); text-align: right; color: var(--text-secondary); font-size: var(--text-xs); font-weight: 600;"><?= htmlspecialchars($order['promo_code']) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr style="border-top: 2px solid var(--soft-grey);">
                                        <td colspan="3" style="padding: var(--space-md); text-align: right; font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700;">Total</td>
                                        <td style="padding: var(--space-md); text-align: right; font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; color: var(--warm-orange);">Rp <?= number_format($order['total'], 0, ',', '.') ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Order Date -->
                    <div style="text-align: center; color: var(--text-muted); font-size: var(--text-sm);">
                        <i class="fas fa-calendar-alt"></i>
                        Pesanan dibuat pada <?= formatDate($order['created_at'], 'd F Y H:i') ?> WIB
                    </div>

                    <!-- Action Buttons -->
                    <div style="text-align: center; margin-top: var(--space-xl); display: flex; justify-content: center; gap: var(--space-md); flex-wrap: wrap;">                            <a href="<?= SITE_URL ?>/pages/products.php" class="btn btn-primary">
                            <i class="fas fa-shopping-bag"></i>
                            Belanja Lagi
                        </a>
                        <?php if ($order['payment_status'] === 'pending' && $order['order_status'] !== 'cancelled'): ?>
                            <a href="<?= SITE_URL ?>/pages/payment-confirm.php?order=<?= urlencode($order['order_number']) ?>" class="btn btn-secondary">
                                <i class="fas fa-credit-card"></i>
                                Konfirmasi Pembayaran
                            </a>
                        <?php endif; ?>
                        <a href="<?= SITE_URL ?>/pages/download-invoice-pdf.php?order=<?= urlencode($order['order_number']) ?>&email=<?= urlencode($order['customer_email']) ?>" class="btn btn-outline" target="_blank">
                            <i class="fas fa-file-pdf"></i>
                            Download Invoice PDF
                        </a>
                        <?php if (isLoggedIn() && (int)$_SESSION['user_id'] === (int)$order['user_id']): ?>
                            <?php if (in_array($order['order_status'], ['pending'], true) && $order['payment_status'] !== 'paid'): ?>
                                <button type="button" class="btn btn-outline" style="color: #EF4444; border-color: #FECACA;"
                                        onclick="userOrderAction('cancel_order', <?= (int)$order['id'] ?>, 'Batalkan pesanan ini? Stok produk akan dikembalikan.')">
                                    <i class="fas fa-times-circle"></i> Batalkan Pesanan
                                </button>
                            <?php endif; ?>
                            <?php if ($order['order_status'] === 'shipped'): ?>
                                <button type="button" class="btn btn-primary"
                                        onclick="userOrderAction('confirm_received', <?= (int)$order['id'] ?>, 'Konfirmasi bahwa pesanan sudah Anda terima?')">
                                    <i class="fas fa-check-circle"></i> Konfirmasi Terima
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?= SITE_URL ?>/pages/tracking.php" class="btn btn-outline">
                            <i class="fas fa-search"></i>
                            Lacak Pesanan Lain
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help Section (shown when no search has been made) -->
            <?php if (!$searched): ?>
            <div class="track-help-grid" data-aos="fade-up" data-aos-delay="100">
                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Cari Pesanan</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Masukkan nomor pesanan yang tertera di email konfirmasi</p>
                </div>

                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-truck"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Cek Status</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Lihat status terbaru pesanan Anda secara real-time</p>
                </div>

                <div style="text-align: center; padding: var(--space-2xl); background: var(--warm-white); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                    <div style="width: 64px; height: 64px; margin: 0 auto var(--space-lg); background: var(--soft-gold-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--soft-gold);">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h4 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-sm);">Butuh Bantuan?</h4>
                    <p style="font-size: var(--text-sm); color: var(--text-muted);">Hubungi kami via WhatsApp untuk bantuan lebih lanjut</p>
                    <a href="https://wa.me/6282112345678" class="btn btn-primary btn-sm" style="margin-top: var(--space-md);" target="_blank">
                        <i class="fab fa-whatsapp"></i> Chat WhatsApp
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($liveCourier): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>#track-map .leaflet-top, #track-map .leaflet-bottom { z-index: 1; }</style>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var cid = document.getElementById('track-courier-id').value;
    var cName = document.getElementById('track-courier-name').value;
    var custLat = parseFloat(document.getElementById('track-cust-lat').value);
    var custLng = parseFloat(document.getElementById('track-cust-lng').value);
    var initLat = parseFloat(document.getElementById('track-init-lat').value);
    var initLng = parseFloat(document.getElementById('track-init-lng').value);
    if (!cid || typeof L === 'undefined') return;

    var hasInit = !isNaN(initLat) && !isNaN(initLng);
    var map = L.map('track-map').setView(hasInit ? [initLat, initLng] : [0.5071, 101.4478], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    var courierIcon = L.divIcon({ className: '', html: '<div style="background:#2563EB;color:#fff;width:34px;height:34px;border-radius:50%;border:3px solid #fff;box-shadow:0 3px 12px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;"><i class="fas fa-motorcycle" style="font-size:16px;"></i></div>', iconSize: [34, 34], iconAnchor: [17, 17] });
    var courierMarker = L.marker(hasInit ? [initLat, initLng] : [0, 0], { icon: courierIcon }).addTo(map).bindPopup('<b>📦 Kurir: ' + cName + '</b>');
    if (hasInit) courierMarker.openPopup();

    // Jangan gambar marker customer bila pesanan tanpa koordinat GPS (hindari titik 0,0)
    if (!isNaN(custLat) && !isNaN(custLng) && custLat !== 0 && custLng !== 0) {
        var custIcon = L.divIcon({ className: '', html: '<div style="background:#EF4444;color:#fff;width:26px;height:26px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;"><i class="fas fa-user" style="font-size:12px;"></i></div>', iconSize: [26, 26], iconAnchor: [13, 13] });
        L.marker([custLat, custLng], { icon: custIcon }).addTo(map).bindPopup('<b>📍 Alamat Anda</b>');
        if (hasInit) map.fitBounds([[custLat, custLng], [initLat, initLng]], { padding: [50, 50] });
    }

    var statusEl = document.getElementById('track-status');
    function poll() {
        fetch('<?= SITE_URL ?>/ajax/courier-location-get.php?courier_id=' + cid)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    var ll = [d.latitude, d.longitude];
                    courierMarker.setLatLng(ll);
                    if (statusEl) {
                        var t = String(d.recorded_at).replace(' ', 'T'); // ISO agar konsisten di semua browser
                        statusEl.innerHTML = '<i class="fas fa-dot-circle" style="color:#2563EB;"></i> Kurir <b>' + cName + '</b> terakhir diperbarui pukul ' + new Date(t).toLocaleTimeString('id-ID') + ' (akurasi ±' + Math.round(d.accuracy) + ' m)';
                    }
                    if (map.getZoom() < 14) map.setView(ll, 14);
                } else if (statusEl) {
                    statusEl.innerHTML = '<i class="fas fa-satellite"></i> Kurir belum mengaktifkan live GPS — posisi akan muncul saat kurir mulai mengirim lokasi.';
                }
            })
            .catch(function () {});
    }
    poll();
    setInterval(poll, 10000);
})();
</script>
<?php endif; ?>

<script>
// Aksi pesanan oleh user: batalkan pesanan / konfirmasi terima (AJAX ke ajax/order-action.php)
function userOrderAction(action, orderId, confirmMsg) {
    if (!confirm(confirmMsg)) return;

    var body = 'action=' + encodeURIComponent(action) + '&order_id=' + orderId + '&csrf_token=' + encodeURIComponent('<?= csrfToken() ?>');

    fetch('<?= SITE_URL ?>/ajax/order-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(function () { location.reload(); }, 900);
        } else {
            showToast(data.message || 'Aksi gagal. Silakan coba lagi.', 'error');
        }
    })
    .catch(function () {
        showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
    });
}
</script>

<?php include '../includes/footer.php'; ?>
