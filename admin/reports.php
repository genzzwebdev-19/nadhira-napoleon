<?php
$currentPage = 'reports';
$pageTitle = 'Laporan';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('reports', 'view');
$conn = getConnection();

// ============================================
// ACTION: Export CSV
// ============================================
if (isset($_GET['export'])) {
    verifyCsrf();
    requirePermission('reports', 'export');
    $type = $_GET['export'] === 'orders' ? 'orders' : 'products';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-' . $type . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM agar Excel mengenali UTF-8

    if ($type === 'orders') {
        fputcsv($out, ['No. Pesanan', 'Tanggal', 'Pelanggan', 'Telepon', 'Metode Bayar', 'Status Bayar', 'Status', 'Subtotal', 'Ongkir', 'Diskon', 'Total']);
        $r = $conn->query("SELECT * FROM orders ORDER BY created_at DESC");
        if ($r) while ($o = $r->fetch_assoc()) {
            fputcsv($out, [
                $o['order_number'], $o['created_at'], $o['customer_name'], $o['customer_phone'],
                $o['payment_method'], $o['payment_status'], $o['order_status'],
                $o['subtotal'], $o['shipping_cost'], $o['discount'], $o['total'],
            ]);
        }
    } else {
        fputcsv($out, ['Produk', 'Kategori', 'Harga', 'Stok', 'Terjual', 'Rating']);
        $r = $conn->query(
            "SELECT p.name, c.name AS category_name, p.price, p.stock, p.total_sold, p.rating
             FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
             ORDER BY p.total_sold DESC"
        );
        if ($r) while ($p = $r->fetch_assoc()) {
            fputcsv($out, [$p['name'], $p['category_name'], $p['price'], $p['stock'], $p['total_sold'], $p['rating']]);
        }
    }
    fclose($out);
    exit;
}

// ============================================
// DATA
// ============================================
// Ringkasan penjualan (hanya order berstatus paid/delivered)
$revenue = (float)$conn->query("SELECT COALESCE(SUM(total),0) c FROM orders WHERE payment_status = 'paid'")->fetch_assoc()['c'];
$orderCount = (int)$conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'];
$paidCount = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_status = 'paid'")->fetch_assoc()['c'];
$avgOrder = $paidCount > 0 ? $revenue / $paidCount : 0;

// Order per status
$statusCounts = [];
$r = $conn->query("SELECT order_status, COUNT(*) c FROM orders GROUP BY order_status");
if ($r) while ($row = $r->fetch_assoc()) $statusCounts[$row['order_status']] = (int)$row['c'];

// Revenue per metode pembayaran
$payMethod = [];
$r = $conn->query("SELECT payment_method, COUNT(*) c, COALESCE(SUM(total),0) t FROM orders WHERE payment_status = 'paid' GROUP BY payment_method");
if ($r) while ($row = $r->fetch_assoc()) $payMethod[$row['payment_method']] = ['count' => (int)$row['c'], 'total' => (float)$row['t']];

// Revenue 6 bulan terakhir
$monthly = [];
for ($i = 5; $i >= 0; $i--) {
    $monthly[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
$r = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COALESCE(SUM(total),0) t
     FROM orders WHERE payment_status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY ym"
);
if ($r) while ($row = $r->fetch_assoc()) {
    if (isset($monthly[$row['ym']])) $monthly[$row['ym']] = (float)$row['t'];
}

// Produk terlaris
$topProducts = $conn->query(
    "SELECT p.name, p.price, p.stock, p.total_sold, p.rating, c.name AS category_name
     FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
     WHERE p.is_active = TRUE ORDER BY p.total_sold DESC LIMIT 10"
);

// Pesanan terbaru
$recentOrders = $conn->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 10");

$statusLabels = ['pending' => 'Pending', 'processing' => 'Diproses', 'shipped' => 'Dikirim', 'delivered' => 'Selesai', 'cancelled' => 'Dibatalkan'];
$methodLabels = ['midtrans' => 'Midtrans', 'transfer_bank' => 'Transfer Bank', 'cod' => 'COD', 'e_wallet' => 'E-Wallet'];

require_once __DIR__ . '/layout.php';
?>

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-rupiah-sign"></i></div><div><div class="stat-card-value" style="font-size: 20px;">Rp <?= number_format($revenue, 0, ',', '.') ?></div></div></div><div class="stat-card-label">Total Pendapatan (Lunas)</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-receipt"></i></div><div><div class="stat-card-value"><?= number_format($orderCount) ?></div></div></div><div class="stat-card-label">Total Pesanan</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-cart-shopping"></i></div><div><div class="stat-card-value"><?= number_format($paidCount) ?></div></div></div><div class="stat-card-label">Pesanan Lunas</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-chart-line"></i></div><div><div class="stat-card-value" style="font-size: 20px;">Rp <?= number_format($avgOrder, 0, ',', '.') ?></div></div></div><div class="stat-card-label">Rata-rata per Order</div></div>
</div>

<!-- ============ EXPORT ============ -->
<?php if (hasPermission('reports', 'export')): ?>
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
    <a href="reports.php?export=orders&csrf_token=<?= csrfToken() ?>" class="btn btn-primary btn-sm"><i class="fas fa-file-csv"></i> Export Data Pesanan</a>
    <a href="reports.php?export=products&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export Data Produk</a>
</div>
<?php endif; ?>

<!-- ============ REVENUE 6 BULAN ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-chart-bar" style="color: var(--soft-gold);"></i> Pendapatan 6 Bulan Terakhir</h3>
    <div style="display: flex; align-items: flex-end; gap: 8px; min-height: 180px; padding: 10px 4px 0;">
        <?php $max = max(1, max($monthly)); ?>
        <?php foreach ($monthly as $ym => $val): ?>
        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px;">
            <div style="font-size: 11px; color: var(--text-muted); white-space: nowrap;">
                Rp <?= number_format($val / 1000, 0, ',', '.') ?>rb
            </div>
            <div style="width: 100%; max-width: 46px; height: <?= max(4, round($val / $max * 100)) ?>px; border-radius: 6px 6px 0 0; background: linear-gradient(180deg, var(--soft-gold), #E8853B); transition: height 0.3s ease;"></div>
            <div style="font-size: 11px; color: var(--text-muted);"><?= date('M', strtotime($ym . '-01')) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============ STATUS & METODE ============ -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
    <div class="admin-card" style="margin: 0;">
        <h3 class="admin-card-title"><i class="fas fa-clipboard-list" style="color: var(--soft-gold);"></i> Pesanan per Status</h3>
        <table class="admin-table">
            <tbody>
                <?php foreach ($statusLabels as $key => $label): ?>
                <tr>
                    <td><span class="status-badge <?= $key ?>"><?= $label ?></span></td>
                    <td style="text-align: right;"><strong><?= number_format($statusCounts[$key] ?? 0) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="admin-card" style="margin: 0;">
        <h3 class="admin-card-title"><i class="fas fa-credit-card" style="color: var(--soft-gold);"></i> Metode Pembayaran (Lunas)</h3>
        <table class="admin-table">
            <tbody>
                <?php foreach ($methodLabels as $key => $label): ?>
                <tr>
                    <td><i class="fas <?= $key === 'midtrans' ? 'fa-credit-card' : ($key === 'transfer_bank' ? 'fa-building-columns' : ($key === 'cod' ? 'fa-hand-holding-dollar' : 'fa-mobile-screen-button')) ?>" style="color: var(--soft-gold); width: 18px;"></i> <?= $label ?></td>
                    <td style="text-align: right;"><strong><?= number_format($payMethod[$key]['count'] ?? 0) ?></strong><br><small style="color: var(--text-light);">Rp <?= number_format($payMethod[$key]['total'] ?? 0, 0, ',', '.') ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ PRODUK TERLARIS ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-trophy" style="color: var(--soft-gold);"></i> Produk Terlaris (Top 10)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th>Harga</th>
                    <th>Terjual</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($topProducts && $topProducts->num_rows > 0): $i = 0; while ($p = $topProducts->fetch_assoc()): $i++; ?>
                <tr>
                    <td style="font-weight: 700; color: <?= $i <= 3 ? 'var(--soft-gold)' : 'var(--text-muted)' ?>;"><?= $i ?></td>
                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($p['category_name'] ?: '-') ?></td>
                    <td>Rp <?= number_format($p['price'], 0, ',', '.') ?></td>
                    <td><strong style="color: var(--soft-gold);"><?= number_format((int)$p['total_sold']) ?></strong></td>
                    <td><i class="fas fa-star" style="color: #F59E0B;"></i> <?= number_format((float)$p['rating'], 1, ',', '.') ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada data penjualan</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ PESANAN TERBARU ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-clock" style="color: var(--soft-gold);"></i> Pesanan Terbaru</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>No. Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentOrders && $recentOrders->num_rows > 0): while ($o = $recentOrders->fetch_assoc()): ?>
                <tr>
                    <td><strong style="color: var(--soft-gold);">#<?= htmlspecialchars($o['order_number']) ?></strong></td>
                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                    <td>Rp <?= number_format($o['total'], 0, ',', '.') ?></td>
                    <td><span class="status-badge <?= $o['order_status'] ?>"><?= $statusLabels[$o['order_status']] ?? $o['order_status'] ?></span></td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada pesanan</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
