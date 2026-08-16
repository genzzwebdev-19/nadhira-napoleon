<?php
$currentPage = 'shipping';
$pageTitle = 'Pengiriman';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('shipping', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Update tracking & status pengiriman
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_shipping'])) {
    verifyCsrf();
    requirePermission('shipping', 'edit');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $tracking = trim($_POST['tracking_number'] ?? '');
    $orderStatus = in_array($_POST['order_status'] ?? '', ['pending', 'processing', 'shipped', 'delivered', 'cancelled'], true) ? $_POST['order_status'] : 'shipped';

    $tracking_e = $conn->real_escape_string($tracking);
    $conn->query("UPDATE orders SET tracking_number = '$tracking_e', order_status = '$orderStatus' WHERE id = $orderId");
    $success = "Pengiriman pesanan #$orderId diperbarui (status: $orderStatus).";
    logActivity('edit', 'shipping', "Update pengiriman order #$orderId -> $orderStatus");
    header('Location: shipping.php');
    exit;
}

// ============================================
// ACTION: Simpan kurir
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_courier'])) {
    verifyCsrf();
    requirePermission('shipping', 'create');
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        $errors[] = 'Nama kurir wajib diisi';
    } else {
        $name_e = $conn->real_escape_string($name);
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('courier_" . generateSlug($name) . "', '$name_e') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $success = 'Kurir "' . htmlspecialchars($name) . '" ditambahkan!';
        logActivity('create', 'shipping', "Menambahkan kurir: $name");
        header('Location: shipping.php');
        exit;
    }
}

// ============================================
// DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE o.order_status IN ('processing', 'shipped')";
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where = "WHERE o.order_status = '$s'";
}

$orders = $conn->query("
    SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o $where ORDER BY o.created_at DESC
");

$statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
$couriers = [];
$rCouriers = $conn->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'courier_%' ORDER BY setting_value");
if ($rCouriers) while ($row = $rCouriers->fetch_assoc()) $couriers[] = $row['setting_value'];

$stats = [
    'processing' => (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE order_status = 'processing'")->fetch_assoc()['c'],
    'shipped' => (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE order_status = 'shipped'")->fetch_assoc()['c'],
    'delivered' => (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE order_status = 'delivered'")->fetch_assoc()['c'],
];

require_once __DIR__ . '/layout.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($info): ?>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= $info ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-box-open"></i></div><div><div class="stat-card-value"><?= $stats['processing'] ?></div></div></div><div class="stat-card-label">Siap Kirim (Processing)</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-truck"></i></div><div><div class="stat-card-value"><?= $stats['shipped'] ?></div></div></div><div class="stat-card-label">Dalam Pengiriman</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-check-double"></i></div><div><div class="stat-card-value"><?= $stats['delivered'] ?></div></div></div><div class="stat-card-label">Terkirim</div></div>
</div>

<!-- ============ TAMBAH KURIR ============ -->
<?php if (hasPermission('shipping', 'create')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-truck-fast" style="color: var(--soft-gold);"></i> Kelola Kurir</h3>
    <form method="POST" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="save_courier" value="1">
        <?= csrfField() ?>
        <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
            <label class="form-label">Nama Kurir</label>
            <input type="text" name="name" class="form-input" placeholder="Contoh: JNE, J&T, SiCepat, AnterAja">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Kurir</button>
    </form>
    <?php if (!empty($couriers)): ?>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px;">
        <?php foreach ($couriers as $c): ?>
        <span class="status-badge gold"><i class="fas fa-truck"></i> <?= htmlspecialchars($c) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ FILTER ============ -->
<div class="filter-bar">
    <div class="form-group">
        <label class="form-label">Filter Status</label>
        <select class="form-select" onchange="location.href='shipping.php?status='+this.value;">
            <option value="">Proses & Dikirim</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <a href="shipping.php" class="btn btn-outline btn-sm" style="margin-bottom: 1px;"><i class="fas fa-times"></i> Reset</a>
</div>

<!-- ============ DAFTAR PENGIRIMAN ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Pengiriman (<?= $orders ? $orders->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Alamat</th>
                    <th>Item</th>
                    <th>Tracking</th>
                    <th>Status</th>
                    <th style="width: 260px;">Update Pengiriman</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($orders && $orders->num_rows > 0): while ($o = $orders->fetch_assoc()): ?>
                <tr>
                    <td><strong style="color: var(--soft-gold);">#<?= htmlspecialchars($o['order_number']) ?></strong><br>
                        <small style="font-size: 11px; color: var(--text-light);"><?= date('d/m/Y', strtotime($o['created_at'])) ?></small>
                    </td>
                    <td style="font-size: 12px;">
                        <strong><?= htmlspecialchars($o['customer_name']) ?></strong><br>
                        <?= htmlspecialchars($o['customer_phone']) ?>
                    </td>
                    <td style="max-width: 220px; font-size: 12px;">
                        <span style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= htmlspecialchars($o['shipping_address']) ?>
                        </span>
                        <br><small style="color: var(--text-light);"><?= htmlspecialchars($o['shipping_city']) ?></small>
                    </td>
                    <td style="text-align: center;"><?= (int)$o['item_count'] ?> item</td>
                    <td>
                        <?php if ($o['tracking_number']): ?>
                            <code style="font-size: 11px;"><?= htmlspecialchars($o['tracking_number']) ?></code>
                        <?php else: ?>
                            <span style="color: var(--text-light); font-size: 12px;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="status-badge <?= $o['order_status'] ?>"><?= ucfirst($o['order_status']) ?></span></td>
                    <td>
                        <form method="POST" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                            <input type="hidden" name="update_shipping" value="1">
                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                            <?= csrfField() ?>
                            <select name="order_status" class="form-select" style="width: auto; padding: 6px 8px; font-size: 12px; display: inline; min-width: 100px;">
                                <?php foreach ($statuses as $s): ?>
                                <option value="<?= $s ?>" <?= $o['order_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="tracking_number" class="form-input" placeholder="No. resi" value="<?= htmlspecialchars($o['tracking_number'] ?? '') ?>" style="width: 130px; padding: 6px 8px; font-size: 12px;">
                            <button type="submit" class="btn btn-primary btn-sm" title="Simpan"><i class="fas fa-check"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada pesanan yang perlu dikirim</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
