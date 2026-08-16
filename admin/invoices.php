<?php
$currentPage = 'invoices';
$pageTitle = 'Invoice';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('invoices', 'view');
$conn = getConnection();

// ============================================
// ACTION: Export CSV daftar invoice
// ============================================
if (isset($_GET['export'])) {
    verifyCsrf();
    requirePermission('invoices', 'export');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="daftar-invoice-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No. Invoice', 'Tanggal', 'Pelanggan', 'Metode Bayar', 'Status Bayar', 'Total']);
    $r = $conn->query("SELECT * FROM orders ORDER BY created_at DESC");
    if ($r) while ($o = $r->fetch_assoc()) {
        fputcsv($out, [$o['order_number'], $o['created_at'], $o['customer_name'], $o['payment_method'], $o['payment_status'], $o['total']]);
    }
    fclose($out);
    exit;
}

// ============================================
// DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where .= " AND o.payment_status = '$s'";
}
$invoices = $conn->query(
    "SELECT o.*,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
            (SELECT pc.status FROM payment_confirmations pc WHERE pc.order_id = o.id ORDER BY pc.id DESC LIMIT 1) AS latest_confirmation
     FROM orders o $where ORDER BY o.created_at DESC"
);

$totalInvoice = (int)$conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'];
$totalLunas = (float)$conn->query("SELECT COALESCE(SUM(total),0) c FROM orders WHERE payment_status = 'paid'")->fetch_assoc()['c'];
$pendingCount = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_status = 'pending'")->fetch_assoc()['c'];
$unconfirmed = (int)$conn->query(
    "SELECT COUNT(*) c FROM orders o
     WHERE o.payment_status = 'pending'
       AND NOT EXISTS (SELECT 1 FROM payment_confirmations pc WHERE pc.order_id = o.id)"
)->fetch_assoc()['c'];

$statusLabels = ['pending' => 'Menunggu', 'paid' => 'Lunas', 'failed' => 'Gagal', 'refunded' => 'Dikembalikan'];

require_once __DIR__ . '/layout.php';
?>

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-file-invoice"></i></div><div><div class="stat-card-value"><?= number_format($totalInvoice) ?></div></div></div><div class="stat-card-label">Total Invoice</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-rupiah-sign"></i></div><div><div class="stat-card-value" style="font-size: 20px;">Rp <?= number_format($totalLunas, 0, ',', '.') ?></div></div></div><div class="stat-card-label">Nilai Invoice Lunas</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-card-value"><?= $pendingCount ?></div></div></div><div class="stat-card-label">Menunggu Pembayaran</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon" style="background: rgba(239,68,68,0.12); color: #EF4444;"><i class="fas fa-bell"></i></div><div><div class="stat-card-value"><?= $unconfirmed ?></div></div></div><div class="stat-card-label">Belum Konfirmasi</div></div>
</div>

<!-- ============ FILTER & EXPORT ============ -->
<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
    <div style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Filter Status Pembayaran</label>
            <select class="form-select" onchange="location.href='invoices.php?status='+this.value;">
                <option value="">Semua Status</option>
                <?php foreach ($statusLabels as $val => $label): ?>
                <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="invoices.php" class="btn btn-outline btn-sm" style="margin-bottom: 1px;"><i class="fas fa-times"></i> Reset</a>
    </div>
    <?php if (hasPermission('invoices', 'export')): ?>
    <a href="invoices.php?export=1&csrf_token=<?= csrfToken() ?>" class="btn btn-primary btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
    <?php endif; ?>
</div>

<!-- ============ DAFTAR INVOICE ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Invoice (<?= $invoices ? $invoices->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>No. Invoice</th>
                    <th>Pelanggan</th>
                    <th>Item</th>
                    <th>Total</th>
                    <th>Pembayaran</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th style="width: 130px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($invoices && $invoices->num_rows > 0): while ($o = $invoices->fetch_assoc()): ?>
                <tr>
                    <td><strong style="color: var(--soft-gold);">#<?= htmlspecialchars($o['order_number']) ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($o['customer_name']) ?></strong>
                        <br><small style="color: var(--text-light);"><?= htmlspecialchars($o['customer_phone']) ?></small>
                    </td>
                    <td style="font-size: 12px;"><?= (int)$o['item_count'] ?> item</td>
                    <td><strong>Rp <?= number_format($o['total'], 0, ',', '.') ?></strong>
                        <?php if ($o['shipping_cost'] > 0): ?><br><small style="font-size: 11px; color: var(--text-light);">+ ongkir Rp <?= number_format($o['shipping_cost'], 0, ',', '.') ?></small><?php endif; ?>
                    </td>
                    <td style="font-size: 12px;">
                        <i class="fas <?= $o['payment_method'] === 'midtrans' ? 'fa-credit-card' : ($o['payment_method'] === 'transfer_bank' ? 'fa-building-columns' : ($o['payment_method'] === 'cod' ? 'fa-hand-holding-dollar' : 'fa-mobile-screen-button')) ?>" style="color: var(--soft-gold); width: 16px;"></i>
                        <?= ucwords(str_replace('_', ' ', $o['payment_method'])) ?>
                    </td>
                    <td>
                        <span class="status-badge <?= $o['payment_status'] ?>"><?= $statusLabels[$o['payment_status']] ?? $o['payment_status'] ?></span>
                        <?php if ($o['payment_status'] === 'pending' && $o['latest_confirmation']): ?>
                            <br><small style="font-size: 10px; color: #D97706;">Konfirmasi: <?= $o['latest_confirmation'] === 'verified' ? 'terverifikasi' : $o['latest_confirmation'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="<?= SITE_URL ?>/pages/invoice.php?order=<?= urlencode($o['order_number']) ?>&admin=1" target="_blank" class="btn btn-outline btn-sm" title="Lihat Invoice"><i class="fas fa-eye"></i> Invoice</a>
                            <a href="<?= SITE_URL ?>/pages/download-invoice-pdf.php?order=<?= urlencode($o['order_number']) ?>&email=<?= urlencode($o['customer_email']) ?>&admin=1" target="_blank" class="btn btn-outline btn-sm" title="Download PDF" style="color: #DC2626; border-color: #DC2626;"><i class="fas fa-file-pdf"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada invoice</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
