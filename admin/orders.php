<?php
$currentPage = 'orders';
$pageTitle = 'Pesanan';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('orders', 'view');

// Handle status update (BEFORE layout.php outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    requirePermission('orders', 'edit');
    verifyCsrf();
    $orderId = (int)$_POST['order_id'];
    $newStatus = $conn->real_escape_string($_POST['order_status']);
    $trackingNum = $conn->real_escape_string(trim($_POST['tracking_number'] ?? ''));

    // Data order diambil sekali untuk logika pembatalan & notifikasi email resi
    $orderRow = null;
    $ord = $conn->query("SELECT * FROM orders WHERE id = $orderId LIMIT 1");
    if ($ord && $ord->num_rows > 0) $orderRow = $ord->fetch_assoc();

    // Jika pesanan dibatalkan, kembalikan jumlah terjual & reward membership (poin/total belanja)
    if ($newStatus === 'cancelled') {
        reverseOrderSold($orderId); // kembalikan jumlah terjual (hanya jika sudah pernah dihitung)
        if (function_exists('restoreOrderStock')) restoreOrderStock($orderId); // kembalikan stok (hanya jika pernah dikurangi)
        if ($orderRow) {
            if ($orderRow['order_status'] !== 'cancelled' && !empty($orderRow['user_id'])) {
                // Poin & total belanja harus dibalik sesuai basis award (subtotal, bukan total+ongkir)
                reverseOrderRewards((int)$orderRow['user_id'], $orderRow['subtotal'], $orderRow['order_number'], $orderId);
                logActivity('edit', 'membership', "Balik reward pesanan #$orderId yang dibatalkan");
            }
            refundPointsForOrder($orderId); // kembalikan poin yang ditukar jadi diskon
            cancelMembershipForOrder($orderId); // batalkan langganan membership dari pesanan ini
        }
        // Kembalikan kuota pemakaian kode promo (agar batas pemakaian akurat)
        if ($orderRow && !empty($orderRow['promo_code']) && function_exists('decrementPromoUsage')) {
            decrementPromoUsage($orderRow['promo_code']);
        }
    }

    $updateSql = "UPDATE orders SET order_status = '$newStatus'";
    if ($trackingNum) $updateSql .= ", tracking_number = '$trackingNum'";
    $updateSql .= " WHERE id = $orderId";
    $conn->query($updateSql);
    logActivity('edit', 'orders', "Mengubah status pesanan #$orderId menjadi $newStatus");

    // 📧 Email notifikasi nomor resi — dikirim saat resi diisi/berubah
    if ($trackingNum !== '' && $orderRow && ($orderRow['tracking_number'] ?? '') !== $trackingNum && function_exists('sendTrackingNumberEmail')) {
        sendTrackingNumberEmail($orderId, $trackingNum);
    }

    header('Location: orders.php');
    exit;
}

// Search & filter
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (order_number LIKE '%$s%' OR customer_name LIKE '%$s%' OR customer_phone LIKE '%$s%')";
}
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where .= " AND order_status = '$s'";
}

$orders = $conn->query("SELECT * FROM orders $where ORDER BY created_at DESC");
$statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

require_once __DIR__ . '/layout.php';
?>

            <div class="admin-card">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px;">Cari Pesanan</label>
                        <input type="text" name="search" class="form-input" placeholder="No. pesanan, nama, atau telepon..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div style="min-width: 150px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px;">Filter Status</label>
                        <select name="status" class="form-select">
                            <option value="">Semua Status</option>
                            <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
                    <a href="orders.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
                </form>
            </div>

            <div class="admin-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                    <h3 class="admin-card-title" style="margin: 0;">Daftar Pesanan</h3>
                    <span style="font-size: 12px; color: var(--text-muted);"><?= $orders ? $orders->num_rows : 0 ?> pesanan</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>No. Pesanan</th>
                                <th>Pelanggan</th>
                                <th>Total</th>
                                <th>Pembayaran</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders && $orders->num_rows > 0): 
                                while ($o = $orders->fetch_assoc()): ?>
                            <tr>
                                <td><strong style="color: var(--soft-gold);">#<?= htmlspecialchars($o['order_number']) ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($o['customer_name']) ?></strong>
                                    <br><small style="color: var(--text-light);"><?= htmlspecialchars($o['customer_phone']) ?></small>
                                </td>
                                <td><strong>Rp <?= number_format($o['total'], 0, ',', '.') ?></strong></td>
                                <td>
                                    <span class="status-badge <?= $o['payment_status'] ?>">
                                        <?= ucfirst($o['payment_status']) ?>
                                    </span>
                                    <br><small style="font-size: 11px; color: var(--text-light);"><?= str_replace('_', ' ', $o['payment_method']) ?></small>
                                </td>
                                <td><span class="status-badge <?= $o['order_status'] ?>"><?= ucfirst($o['order_status']) ?></span></td>
                                <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <a href="order-detail.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm" title="Lihat Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <form method="POST" style="display: inline-flex; gap: 4px; align-items: center;" 
                                              onsubmit="return confirm('Update status pesanan menjadi '+this.order_status.options[this.order_status.selectedIndex].text+'?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <select name="order_status" class="form-select" 
                                                    style="width: auto; padding: 6px 8px; font-size: 12px; display: inline; min-width: 90px;">
                                                <?php foreach ($statuses as $s): ?>
                                                <option value="<?= $s ?>" <?= $o['order_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm" title="Simpan">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                                Belum ada pesanan
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
