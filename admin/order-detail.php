<?php
$currentPage = 'orders';
$pageTitle = 'Detail Pesanan';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/midtrans.php';

$conn = getConnection();
ensureMidtransSchema();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('orders', 'view');

$orderId = (int)($_GET['id'] ?? 0);

// Validate order BEFORE layout.php outputs HTML
if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

$order = $conn->query("SELECT * FROM orders WHERE id = $orderId LIMIT 1");
if (!$order || $order->num_rows === 0) {
    header('Location: orders.php');
    exit;
}
$order = $order->fetch_assoc();

// Handle POST requests BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    verifyCsrf();
    requirePermission('payments', 'verify');
    $confId = (int)$_POST['confirmation_id'];
    $action = $_POST['verify_action'] ?? '';
    $reason = $conn->real_escape_string(trim($_POST['rejection_reason'] ?? ''));
    $adminId = (int)$_SESSION['user_id'];

    if ($action === 'verified') {
        $conn->query("UPDATE payment_confirmations SET status = 'verified', verified_by = $adminId, verified_at = NOW() WHERE id = $confId");
        $conn->query("UPDATE orders SET payment_status = 'paid' WHERE id = $orderId");
        countOrderSold($orderId); // tambah jumlah terjual produk dari pesanan lunas ini
        deductOrderStock($orderId); // pastikan stok ter-reserve (idempoten)
        activateMembershipForOrder($orderId); // aktifkan langganan membership jika ada paketnya
        if (!empty($order['user_id'])) {
            awardOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId); // poin hanya saat lunas
        }
        // 🔔 Notifikasi LUNAS (jalur verifikasi manual) — hanya jika tadinya belum lunas
        if ($order['payment_status'] !== 'paid' && function_exists('notifyPaymentPaid')) {
            notifyPaymentPaid($orderId, $order['order_number'], $order['total']);
        }
    } elseif ($action === 'rejected') {
        $reason_e = $conn->real_escape_string($reason);
        $conn->query("UPDATE payment_confirmations SET status = 'rejected', verified_by = $adminId, verified_at = NOW(), rejection_reason = '$reason_e' WHERE id = $confId");
    }
    header("Location: order-detail.php?id=$orderId");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['verify_payment'])) {
    verifyCsrf();
    requirePermission('orders', 'edit');
    $updateFields = [];
    // Nomor resi lama (untuk deteksi perubahan → kirim email notifikasi resi)
    $oldTrackingNumber = (string)($order['tracking_number'] ?? '');
    if (isset($_POST['order_status'])) {
        $s = $conn->real_escape_string($_POST['order_status']);
        $updateFields[] = "order_status = '$s'";
    }
    if (isset($_POST['payment_status'])) {
        $s = $conn->real_escape_string($_POST['payment_status']);
        $updateFields[] = "payment_status = '$s'";
        if ($s === 'paid') {
            $updateFields[] = "paid_at = COALESCE(paid_at, NOW())";
        }
    }
    if (isset($_POST['tracking_number'])) {
        $s = $conn->real_escape_string($_POST['tracking_number']);
        $updateFields[] = "tracking_number = '$s'";
    }
    if (isset($_POST['courier_id'])) {
        $c = (int)$_POST['courier_id'];
        $updateFields[] = 'courier_id = ' . ($c > 0 ? $c : 'NULL');
    }
    if (!empty($updateFields)) {
        $conn->query("UPDATE orders SET " . implode(', ', $updateFields) . " WHERE id = $orderId");
    }
    // 📧 Email notifikasi nomor resi — dikirim saat resi diisi/berubah
    if (isset($_POST['tracking_number'])
        && trim($_POST['tracking_number']) !== ''
        && trim($_POST['tracking_number']) !== $oldTrackingNumber
        && function_exists('sendTrackingNumberEmail')) {
        sendTrackingNumberEmail($orderId, trim($_POST['tracking_number']));
    }
    // Sinkronkan langganan membership & jumlah terjual: aktif saat lunas, batal saat dibatalkan
    if (isset($_POST['payment_status']) && $_POST['payment_status'] === 'paid') {
        countOrderSold($orderId); // tambah jumlah terjual produk dari pesanan lunas ini
        deductOrderStock($orderId); // pastikan stok ter-reserve (idempoten)
        activateMembershipForOrder($orderId);
        if (!empty($order['user_id'])) {
            awardOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId); // poin hanya saat lunas
        }
        // 🔔 Notifikasi LUNAS (jalur update manual) — hanya jika tadinya belum lunas
        if ($order['payment_status'] !== 'paid' && function_exists('notifyPaymentPaid')) {
            notifyPaymentPaid($orderId, $order['order_number'], $order['total']);
        }
    }
    // Pembayaran gagal/refund (manual) → kembalikan stok yang di-reserve
    if (isset($_POST['payment_status']) && in_array($_POST['payment_status'], ['failed', 'refunded'], true)) {
        restoreOrderStock($orderId);
    }
    if (isset($_POST['order_status']) && $_POST['order_status'] === 'cancelled') {
        reverseOrderSold($orderId); // kembalikan jumlah terjual (hanya jika sudah pernah dihitung)
        if (function_exists('restoreOrderStock')) restoreOrderStock($orderId); // kembalikan stok (hanya jika pernah dikurangi)
        // Balik reward membership (poin belanja & total belanja) bila belum dibatalkan sebelumnya
        if ($order['order_status'] !== 'cancelled' && !empty($order['user_id'])) {
            reverseOrderRewards((int)$order['user_id'], $order['subtotal'], $order['order_number'], $orderId);
            logActivity('edit', 'membership', "Balik reward pesanan #$orderId yang dibatalkan");
        }
        refundPointsForOrder($orderId); // kembalikan poin yang ditukar jadi diskon
        cancelMembershipForOrder($orderId);
        // Kembalikan kuota pemakaian kode promo (agar batas pemakaian akurat)
        if (!empty($order['promo_code']) && function_exists('decrementPromoUsage')) {
            decrementPromoUsage($order['promo_code']);
        }
    }
    header("Location: order-detail.php?id=$orderId");
    exit;
}

$orderItems = $conn->query("SELECT * FROM order_items WHERE order_id = $orderId");

$statusLabels = [
    'pending' => 'Pesanan Dibuat', 'processing' => 'Diproses',
    'shipped' => 'Dikirim', 'delivered' => 'Selesai', 'cancelled' => 'Dibatalkan'
];
$paymentStatuses = ['pending', 'paid', 'failed', 'refunded'];
$orderStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

$paymentConfirmations = $conn->query("SELECT pc.*, u.full_name as verifier_name FROM payment_confirmations pc LEFT JOIN users u ON pc.verified_by = u.id WHERE pc.order_id = $orderId ORDER BY pc.created_at DESC");

// Daftar kurir aktif untuk assignment
$couriers = $conn->query("SELECT * FROM couriers WHERE is_active = 1 ORDER BY name ASC");

require_once __DIR__ . '/layout.php';
?>

            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
                <div>
                    <div style="display: flex; gap: 8px; align-items: center; font-size: 13px; margin-bottom: 12px;">
                        <a href="orders.php" style="color: var(--text-muted); text-decoration: none;">Pesanan</a>
                        <span style="color: var(--text-light);">/</span>
                        <span style="color: var(--soft-gold); font-weight: 500;"><?= htmlspecialchars($order['order_number']) ?></span>
                    </div>
                    <h2 style="font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; margin: 0;">
                        Pesanan #<?= htmlspecialchars($order['order_number']) ?>
                    </h2>
                    <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0;">
                        Dibuat pada <?= formatDate($order['created_at'], 'd F Y H:i') ?> WIB
                    </p>
                </div>
                <div style="text-align: right;">
                    <span class="status-badge <?= $order['order_status'] ?>" style="font-size: 14px; padding: 8px 20px;">
                        <?= $statusLabels[$order['order_status']] ?? ucfirst($order['order_status']) ?>
                    </span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-xl);">
                <!-- Left Column: Items -->
                <div>
                    <div class="admin-card">
                        <h3 class="admin-card-title">Item Pesanan</h3>
                        <div style="overflow-x: auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th>Harga</th>
                                        <th>Jumlah</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($orderItems): while ($item = $orderItems->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td>Rp <?= number_format($item['price'], 0, ',', '.') ?></td>
                                        <td>x<?= $item['quantity'] ?></td>
                                        <td><strong>Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></strong></td>
                                    </tr>
                                    <?php endwhile; endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="3" style="text-align: right;">Subtotal</td><td>Rp <?= number_format($order['subtotal'], 0, ',', '.') ?></td></tr>
                                    <tr><td colspan="3" style="text-align: right;">Ongkir</td><td><?= $order['shipping_cost'] > 0 ? 'Rp ' . number_format($order['shipping_cost'], 0, ',', '.') : '<strong style="color: #059669;">GRATIS</strong>' ?></td></tr>
                                    <?php if ($order['discount'] > 0): ?>
                                    <tr><td colspan="3" style="text-align: right;">Diskon<?= !empty($order['promo_code']) ? ' (kode promo)' : '' ?></td><td style="color: #059669;">-Rp <?= number_format($order['discount'], 0, ',', '.') ?></td></tr>
                                    <?php endif; ?>
                                    <?php if (!empty($order['promo_code'])): ?>
                                    <tr><td colspan="3" style="text-align: right; font-size: var(--text-xs); color: var(--text-muted);">Kode promo</td><td style="font-size: var(--text-xs); font-weight: 600;"><?= htmlspecialchars($order['promo_code']) ?></td></tr>
                                    <?php endif; ?>
                                    <tr><td colspan="3" style="text-align: right; font-size: var(--text-lg); font-weight: 700;">Total</td>
                                        <td style="font-size: var(--text-lg); font-weight: 700; color: var(--warm-orange);">Rp <?= number_format($order['total'], 0, ',', '.') ?></td></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Shipping Info -->
                    <div class="admin-card">
                        <h3 class="admin-card-title">Informasi Pengiriman</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Nama</p>
                                <p style="font-weight: 500;"><?= htmlspecialchars($order['customer_name']) ?></p>
                            </div>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Telepon</p>
                                <p style="font-weight: 500;"><?= htmlspecialchars($order['customer_phone']) ?></p>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Alamat</p>
                                <p><?= nl2br(htmlspecialchars($order['shipping_address'])) ?></p>
                                <p><?= htmlspecialchars($order['shipping_city']) ?>, <?= htmlspecialchars($order['shipping_province']) ?> <?= htmlspecialchars($order['shipping_postal_code']) ?></p>
                            </div>
                            <?php if ($order['notes']): ?>
                            <div style="grid-column: 1 / -1;">
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Catatan</p>
                                <p><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lokasi Pengiriman (Peta) -->
                    <?php
                    $locBranch = null;
                    if (!empty($order['latitude']) && !empty($order['longitude']) && !empty($order['branch_id'])) {
                        $br = $conn->query("SELECT * FROM branches WHERE id = " . (int)$order['branch_id'] . " LIMIT 1");
                        if ($br && $br->num_rows > 0) $locBranch = $br->fetch_assoc();
                    }
                    if (!empty($order['latitude']) && !empty($order['longitude'])): ?>
                    <div class="admin-card">
                        <h3 class="admin-card-title"><i class="fas fa-map-marked-alt" style="color: var(--soft-gold);"></i> Lokasi Pengiriman</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md); margin-bottom: var(--space-md);">
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Jarak (rute jalan)</p>
                                <p style="font-weight: 500;"><?= $order['distance_km'] ? number_format((float)$order['distance_km'], 2, ',', '.') . ' km' : '-' ?></p>
                            </div>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Estimasi Waktu</p>
                                <p style="font-weight: 500;"><?= $order['distance_km'] ? formatDuration(estimateDeliveryMinutes((float)$order['distance_km'])) : '-' ?></p>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Titik Pengiriman</p>
                                <p style="font-weight: 500;"><?= $locBranch ? htmlspecialchars($locBranch['name']) : '-' ?></p>
                            </div>
                        </div>
                        <div id="order-map" style="height: 320px; border-radius: 10px; border: 1px solid #e5e0db;"></div>
                        <div style="margin-top: var(--space-md);">
                            <a href="https://www.google.com/maps?q=<?= (float)$order['latitude'] ?>,<?= (float)$order['longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">
                                <i class="fas fa-external-link-alt"></i> Buka Lokasi Customer di Google Maps
                            </a>
                        </div>
                        <input type="hidden" id="order-customer-lat" value="<?= (float)$order['latitude'] ?>">
                        <input type="hidden" id="order-customer-lng" value="<?= (float)$order['longitude'] ?>">
                        <?php if ($locBranch): ?>
                        <input type="hidden" id="order-branch-lat" value="<?= (float)$locBranch['latitude'] ?>">
                        <input type="hidden" id="order-branch-lng" value="<?= (float)$locBranch['longitude'] ?>">
                        <input type="hidden" id="order-branch-name" value="<?= htmlspecialchars($locBranch['name'], ENT_QUOTES) ?>">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Actions -->
                <div>
                    <div class="admin-card">
                        <h3 class="admin-card-title">Update Status</h3>
                        <form method="POST">
                            <?= csrfField() ?>
                            <div class="form-group">
                                <label class="form-label">Status Pesanan</label>
                                <select name="order_status" class="form-select">
                                    <?php foreach ($orderStatuses as $s): ?>
                                    <option value="<?= $s ?>" <?= $order['order_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status Pembayaran</label>
                                <select name="payment_status" class="form-select">
                                    <?php foreach ($paymentStatuses as $s): ?>
                                    <option value="<?= $s ?>" <?= $order['payment_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">No. Resi</label>
                                <input type="text" name="tracking_number" class="form-input" value="<?= htmlspecialchars($order['tracking_number'] ?? '') ?>" placeholder="Masukkan no. resi">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Kurir Pengantar</label>
                                <select name="courier_id" class="form-select">
                                    <option value="0">— Belum ditugaskan —</option>
                                    <?php if ($couriers): while ($c = $couriers->fetch_assoc()): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)($order['courier_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?><?= !empty($c['phone']) ? ' · ' . htmlspecialchars($c['phone']) : '' ?>
                                    </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <small style="color: var(--text-muted);">Kurir yang ditugaskan akan terlihat posisinya oleh customer di halaman tracking (real-time).</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-full">
                                <i class="fas fa-save"></i> Simpan Perubahan
                            </button>
                            <a href="<?= SITE_URL ?>/pages/download-invoice-pdf.php?order=<?= urlencode($order['order_number']) ?>&admin=1" class="btn btn-outline w-full" style="margin-top: var(--space-md);" target="_blank">
                                <i class="fas fa-file-pdf"></i> Download Invoice PDF
                            </a>
                        </form>
                    </div>

                    <!-- Payment Info -->
                    <div class="admin-card">
                        <h3 class="admin-card-title">Pembayaran</h3>
                        <div style="display: grid; gap: var(--space-md);">
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Metode</p>
                                <p style="font-weight: 500; text-transform: capitalize;"><?= str_replace('_', ' ', $order['payment_method']) ?></p>
                            </div>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Status</p>
                                <span class="status-badge <?= $order['payment_status'] ?>"><?= ucfirst($order['payment_status']) ?></span>
                            </div>
                            <?php if ($order['payment_method'] === 'midtrans' && $order['payment_status'] === 'paid'): ?>
                            <div style="background: #D1FAE5; border: 1px solid #A7F3D0; color: #065F46; border-radius: 10px; padding: 10px 12px; font-size: 12px;">
                                <i class="fas fa-check-circle"></i> Otomatis terverifikasi oleh Midtrans — tanpa verifikasi manual.
                            </div>
                            <?php endif; ?>
                            <?php if ($order['payment_method'] === 'midtrans'): ?>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Kanal (Midtrans)</p>
                                <p style="font-weight: 500;"><?= !empty($order['midtrans_payment_type']) ? htmlspecialchars(midtransPaymentLabel($order['midtrans_payment_type'])) : 'Belum dipilih' ?></p>
                            </div>
                            <?php if (!empty($order['midtrans_va_number'])): ?>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">No. Virtual Account</p>
                                <p style="font-weight: 500; font-family: monospace;"><?= htmlspecialchars($order['midtrans_va_number']) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order['midtrans_transaction_id'])): ?>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Ref. Transaksi</p>
                                <p style="font-weight: 500; font-size: var(--text-xs); word-break: break-all;"><?= htmlspecialchars($order['midtrans_transaction_id']) ?></p>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div>
                                <p style="font-size: var(--text-sm); color: var(--text-muted);">Email</p>
                                <p><?= htmlspecialchars($order['customer_email']) ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Confirmations -->
                    <?php if ($paymentConfirmations && $paymentConfirmations->num_rows > 0): ?>
                    <div class="admin-card">
                        <h3 class="admin-card-title">
                            <i class="fas fa-receipt"></i> Konfirmasi Pembayaran
                            <?php 
                            $pendingCount = 0;
                            $paymentConfirmations->data_seek(0);
                            while ($pc = $paymentConfirmations->fetch_assoc()) {
                                if ($pc['status'] === 'pending') $pendingCount++;
                            }
                            $paymentConfirmations->data_seek(0);
                            if ($pendingCount > 0): ?>
                                <span class="status-badge pending" style="font-size: 11px; margin-left: 8px;"><?= $pendingCount ?> menunggu</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php while ($pc = $paymentConfirmations->fetch_assoc()): 
                            $isPending = $pc['status'] === 'pending';
                            $isVerified = $pc['status'] === 'verified';
                            $isRejected = $pc['status'] === 'rejected';
                        ?>
                        <div style="border: 1px solid <?= $isVerified ? '#A7F3D0' : ($isRejected ? '#FECACA' : '#FDE68A') ?>; border-radius: 12px; padding: 16px; margin-bottom: 12px; background: <?= $isVerified ? '#F0FDF4' : ($isRejected ? '#FEF2F2' : '#FFFBEB') ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 12px;">
                                <div>
                                    <strong style="font-size: 13px;">Konfirmasi #<?= $pc['id'] ?></strong>
                                    <span class="status-badge <?= $pc['status'] ?>" style="margin-left: 8px; font-size: 10px; padding: 2px 10px;">
                                        <?= $isVerified ? 'Terverifikasi' : ($isRejected ? 'Ditolak' : 'Menunggu') ?>
                                    </span>
                                </div>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= formatDate($pc['created_at'], 'd M Y H:i') ?></span>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; margin-bottom: 12px;">
                                <div><span style="color: var(--text-muted);">Bank:</span> <?= htmlspecialchars($pc['bank_name']) ?></div>
                                <div><span style="color: var(--text-muted);">No. Rek:</span> <?= htmlspecialchars($pc['account_number']) ?></div>
                                <div><span style="color: var(--text-muted);">A/N:</span> <?= htmlspecialchars($pc['account_name']) ?></div>
                                <div><span style="color: var(--text-muted);">Jumlah:</span> <strong>Rp <?= number_format($pc['amount'], 0, ',', '.') ?></strong></div>
                                <div><span style="color: var(--text-muted);">Tgl Transfer:</span> <?= formatDate($pc['transfer_date'], 'd M Y') ?></div>
                                <?php if ($pc['notes']): ?>
                                <div style="grid-column: 1 / -1;"><span style="color: var(--text-muted);">Catatan:</span> <?= htmlspecialchars($pc['notes']) ?></div>
                                <?php endif; ?>
                                <?php if ($isVerified && $pc['verifier_name']): ?>
                                <div style="grid-column: 1 / -1; color: #059669;">
                                    <i class="fas fa-check-circle"></i> Diverifikasi oleh <?= htmlspecialchars($pc['verifier_name']) ?> pada <?= formatDate($pc['verified_at'], 'd M Y H:i') ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($isRejected && $pc['rejection_reason']): ?>
                                <div style="grid-column: 1 / -1; color: #DC2626;">
                                    <i class="fas fa-times-circle"></i> Alasan: <?= htmlspecialchars($pc['rejection_reason']) ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($pc['proof_image']): ?>
                            <?php
                            // URL Cloudinary penuh, atau path relatif uploads/payments/...
                            $proofSrc = (stripos($pc['proof_image'], 'http') === 0) ? $pc['proof_image'] : SITE_URL . '/' . $pc['proof_image'];
                            ?>
                            <div style="margin-bottom: 12px;">
                                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 6px;">Bukti Transfer:</p>
                                <a href="<?= htmlspecialchars($proofSrc) ?>" target="_blank" style="display: inline-block;">
                                    <img src="<?= htmlspecialchars($proofSrc) ?>" alt="Bukti Transfer" 
                                         style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid #e5e0db; cursor: pointer;"
                                         onmouseover="this.style.maxWidth='400px'; this.style.maxHeight='300px'"
                                         onmouseout="this.style.maxWidth='200px'; this.style.maxHeight='150px'">
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if ($isPending): ?>
                            <form method="POST" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <?= csrfField() ?>
                                <input type="hidden" name="verify_payment" value="1">
                                <input type="hidden" name="confirmation_id" value="<?= $pc['id'] ?>">
                                <button type="submit" name="verify_action" value="verified" class="btn btn-primary btn-sm"
                                        onclick="return confirm('Konfirmasi pembayaran ini? Status pesanan akan berubah menjadi LUNAS.')">
                                    <i class="fas fa-check"></i> Verifikasi (LUNAS)
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" style="color: #EF4444; border-color: #EF4444;"
                                        onclick="showRejectForm(<?= $pc['id'] ?>)">
                                    <i class="fas fa-times"></i> Tolak
                                </button>
                            </form>
                            <!-- Reject Form (hidden) -->
                            <form method="POST" id="reject-form-<?= $pc['id'] ?>" style="display: none; margin-top: 8px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="verify_payment" value="1">
                                <input type="hidden" name="confirmation_id" value="<?= $pc['id'] ?>">
                                <input type="hidden" name="verify_action" value="rejected">
                                <div style="display: flex; gap: 8px; align-items: flex-start;">
                                    <input type="text" name="rejection_reason" class="form-input" placeholder="Alasan penolakan..." style="flex: 1;" required>
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: #EF4444; border-color: #EF4444;"
                                            onclick="return confirm('Tolak konfirmasi pembayaran ini?')">
                                        <i class="fas fa-times"></i> Konfirmasi Tolak
                                    </button>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="hideRejectForm(<?= $pc['id'] ?>)">Batal</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </main>
        <script>
        function showRejectForm(id) {
            document.getElementById('reject-form-' + id).style.display = 'block';
        }
        function hideRejectForm(id) {
            document.getElementById('reject-form-' + id).style.display = 'none';
        }
        </script>
        <?php if (!empty($order['latitude']) && !empty($order['longitude'])): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <style>#order-map .leaflet-top, #order-map .leaflet-bottom { z-index: 1; }</style>
        <script>
        (function () {
            var lat = parseFloat(document.getElementById('order-customer-lat').value);
            var lng = parseFloat(document.getElementById('order-customer-lng').value);
            if (isNaN(lat) || isNaN(lng) || typeof L === 'undefined') return;
            var map = L.map('order-map').setView([lat, lng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            var customerIcon = L.divIcon({ className: '', html: '<div style="background:#EF4444;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.35);"><i class="fas fa-map-marker-alt" style="font-size:14px;"></i></div>', iconSize: [28, 28], iconAnchor: [14, 14] });
            L.marker([lat, lng], { icon: customerIcon }).addTo(map).bindPopup('<strong>Customer</strong>').openPopup();

            var bl = document.getElementById('order-branch-lat');
            if (bl) {
                var bLat = parseFloat(bl.value);
                var bLng = parseFloat(document.getElementById('order-branch-lng').value);
                var bn = document.getElementById('order-branch-name');
                var branchIcon = L.divIcon({ className: '', html: '<div style="background:linear-gradient(135deg,#D4A030,#B8940F);color:#fff;width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.3);"><i class="fas fa-store" style="transform:rotate(45deg);font-size:13px;"></i></div>', iconSize: [30, 30], iconAnchor: [15, 30] });
                L.marker([bLat, bLng], { icon: branchIcon }).addTo(map).bindPopup('<strong>' + (bn ? bn.value : 'Cabang') + '</strong>');
                map.fitBounds([[lat, lng], [bLat, bLng]], { padding: [40, 40] });

                // Rute via OSRM (gratis, tanpa API key)
                var url = 'https://router.project-osrm.org/route/v1/driving/' + lng + ',' + lat + ';' + bLng + ',' + bLat + '?overview=full&geometries=geojson';
                fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                    if (d.routes && d.routes[0] && d.routes[0].geometry) {
                        var coords = d.routes[0].geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
                        L.polyline(coords, { color: '#B8940F', weight: 4, opacity: 0.85 }).addTo(map);
                    }
                }).catch(function () {});
            }
        })();
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
