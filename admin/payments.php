<?php
$currentPage = 'payments';
$pageTitle = 'Pembayaran';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/../config/midtrans.php';

requirePermission('payments', 'view');
$conn = getConnection();
ensureMidtransSchema();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Verifikasi / tolak pembayaran
// ============================================
if (isset($_GET['verify']) || isset($_GET['reject'])) {
    verifyCsrf();
    requirePermission('payments', 'verify');
    $pcId = (int)($_GET['verify'] ?? $_GET['reject']);
    $action = isset($_GET['verify']) ? 'verified' : 'rejected';
    $reason = trim($_GET['reason'] ?? '');

    $r = $conn->query("SELECT * FROM payment_confirmations WHERE id = $pcId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $pc = $r->fetch_assoc();
        $uid = getCurrentUserId();
        $reason_e = $conn->real_escape_string($reason);
        $conn->query("UPDATE payment_confirmations SET status = '$action', verified_by = $uid, verified_at = NOW(), rejection_reason = '$reason_e' WHERE id = $pcId");

        if ($action === 'verified') {
            // Ambil data pesanan SEBELUM di-update — dipakai guard notifikasi agar hanya
            // berbunyi saat transisi pending → paid (bukan untuk order yang sudah lunas)
            $ord = $conn->query("SELECT user_id, subtotal, total, order_number, payment_status FROM orders WHERE id = " . (int)$pc['order_id'] . " LIMIT 1");
            // Tandai pesanan terbayar
            $conn->query("UPDATE orders SET payment_status = 'paid' WHERE id = " . (int)$pc['order_id']);
            // Tambah jumlah terjual produk dari pesanan lunas ini
            countOrderSold((int)$pc['order_id']);
            // Pastikan stok ter-reserve (idempoten)
            if (function_exists('deductOrderStock')) deductOrderStock((int)$pc['order_id']);
            // Beri poin & total belanja (idempoten — hanya sekali per pesanan)
            if ($ord && $ord->num_rows > 0) {
                $ow = $ord->fetch_assoc();
                if (!empty($ow['user_id'])) {
                    awardOrderRewards((int)$ow['user_id'], $ow['subtotal'], $ow['order_number'], (int)$pc['order_id']);
                }
                // 🔔 Notifikasi LUNAS (jalur verifikasi manual) — hanya jika tadinya belum lunas
                if (($ow['payment_status'] ?? '') !== 'paid' && function_exists('notifyPaymentPaid')) {
                    notifyPaymentPaid((int)$pc['order_id'], $ow['order_number'], $ow['total']);
                }
            }
            // Aktifkan langganan membership jika pesanan berisi paket membership
            $activated = activateMembershipForOrder((int)$pc['order_id']);
            if ($activated > 0) {
                $success = 'Pembayaran #' . $pcId . ' untuk pesanan ' . htmlspecialchars($pc['customer_name']) . ' berhasil diverifikasi! Langganan membership aktif.';
                logActivity('edit', 'membership', "Aktivasi langganan membership order {$pc['order_id']} ($activated paket)");
            } else {
                $success = 'Pembayaran #' . $pcId . ' untuk pesanan ' . htmlspecialchars($pc['customer_name']) . ' berhasil diverifikasi!';
            }
            logActivity('edit', 'payments', "Verifikasi pembayaran #$pcId (order {$pc['order_id']})");
        } else {
            $conn->query("UPDATE orders SET payment_status = 'failed' WHERE id = " . (int)$pc['order_id']);
            // Pembayaran ditolak → kembalikan stok yang di-reserve
            if (function_exists('restoreOrderStock')) restoreOrderStock((int)$pc['order_id']);
            $info = 'Pembayaran #' . $pcId . ' ditolak.';
            logActivity('edit', 'payments', "Menolak pembayaran #$pcId (order {$pc['order_id']})");
        }
    }
    header('Location: payments.php');
    exit;
}

// ============================================
// DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
if ($statusFilter) {
    $s = $conn->real_escape_string($statusFilter);
    $where .= " AND pc.status = '$s'";
}

$confirmations = $conn->query("
    SELECT pc.*, o.order_number, o.total AS order_total,
           CONCAT(o.customer_name, ' / ', o.customer_phone) AS customer_info
    FROM payment_confirmations pc
    JOIN orders o ON o.id = pc.order_id
    $where ORDER BY pc.created_at DESC
");

$pendingCount = $conn->query("SELECT COUNT(*) c FROM payment_confirmations WHERE status = 'pending'")->fetch_assoc()['c'];
$verifiedCount = $conn->query("SELECT COUNT(*) c FROM payment_confirmations WHERE status = 'verified'")->fetch_assoc()['c'];
$rejectedCount = $conn->query("SELECT COUNT(*) c FROM payment_confirmations WHERE status = 'rejected'")->fetch_assoc()['c'];

// ============================================
// DATA - Pembayaran Midtrans (otomatis terverifikasi)
// ============================================
$midtransPaidCount = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_method = 'midtrans' AND payment_status = 'paid'")->fetch_assoc()['c'];
$midtransPendingCount = (int)$conn->query("SELECT COUNT(*) c FROM orders WHERE payment_method = 'midtrans' AND payment_status = 'pending'")->fetch_assoc()['c'];
$midtransPayments = $conn->query("
    SELECT id AS order_id, order_number, customer_name, customer_phone, total, payment_status, paid_at,
           midtrans_payment_type, midtrans_va_number, midtrans_bank, midtrans_transaction_id
    FROM orders
    WHERE payment_method = 'midtrans'
    ORDER BY COALESCE(paid_at, created_at) DESC
    LIMIT 25
");

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

<!-- ============ INFO MIDTRANS ============ -->
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Pembayaran via Midtrans otomatis terverifikasi.</strong>
    Saat pelanggan sudah membayar, status pesanan langsung menjadi <strong>LUNAS</strong> tanpa perlu verifikasi manual —
    baik lewat notifikasi webhook maupun cek status otomatis. Tidak ada aksi yang diperlukan di halaman ini.
</div>

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-credit-card"></i></div><div><div class="stat-card-value"><?= $midtransPaidCount ?></div></div></div><div class="stat-card-label">Lunas via Midtrans</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-card-value"><?= $midtransPendingCount ?></div></div></div><div class="stat-card-label">Menunggu Bayar (Midtrans)</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-card-value"><?= $pendingCount ?></div></div></div><div class="stat-card-label">Verifikasi Manual Menunggu</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-check-circle"></i></div><div><div class="stat-card-value"><?= $verifiedCount ?></div></div></div><div class="stat-card-label">Manual Terverifikasi</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon" style="background: rgba(239,68,68,0.12); color: #EF4444;"><i class="fas fa-times-circle"></i></div><div><div class="stat-card-value"><?= $rejectedCount ?></div></div></div><div class="stat-card-label">Manual Ditolak</div></div>
</div>

<!-- ============ PEMBAYARAN MIDTRANS ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">
        <i class="fas fa-credit-card" style="color: #10B981; margin-right: 8px;"></i>
        Pembayaran Midtrans Terbaru
        <span style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400; margin-left: 8px;">(otomatis terverifikasi — hanya info, tanpa aksi)</span>
    </h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Kanal</th>
                    <th>VA / Bank</th>
                    <th>Ref. Transaksi</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Dibayar</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($midtransPayments && $midtransPayments->num_rows > 0): while ($mp = $midtransPayments->fetch_assoc()): ?>
                <tr>
                    <td>
                        <a href="order-detail.php?id=<?= (int)$mp['order_id'] ?>" style="color: var(--soft-gold); font-weight: 600; text-decoration: none;"><?= htmlspecialchars($mp['order_number']) ?></a>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($mp['customer_name']) ?><br><small style="color: var(--text-light);"><?= htmlspecialchars($mp['customer_phone']) ?></small></td>
                    <td style="font-size: 12px;"><?= !empty($mp['midtrans_payment_type']) ? htmlspecialchars(midtransPaymentLabel($mp['midtrans_payment_type'])) : '<span style="color: var(--text-light);">-</span>' ?></td>
                    <td style="font-size: 12px;">
                        <?php if (!empty($mp['midtrans_va_number'])): ?>
                            <strong><?= htmlspecialchars($mp['midtrans_va_number']) ?></strong>
                            <?php if (!empty($mp['midtrans_bank'])): ?><br><small style="color: var(--text-light);"><?= htmlspecialchars(strtoupper($mp['midtrans_bank'])) ?></small><?php endif; ?>
                        <?php else: ?><span style="color: var(--text-light);">-</span><?php endif; ?>
                    </td>
                    <td style="font-size: 11px; color: var(--text-light); word-break: break-all; max-width: 180px;"><?= htmlspecialchars($mp['midtrans_transaction_id'] ?? '-') ?></td>
                    <td><strong>Rp <?= number_format($mp['total'], 0, ',', '.') ?></strong></td>
                    <td>
                        <?php if ($mp['payment_status'] === 'paid'): ?>
                            <span class="status-badge paid">LUNAS</span>
                            <br><small style="font-size: 10px; color: #059669;">✓ otomatis, tanpa verifikasi</small>
                        <?php elseif ($mp['payment_status'] === 'pending'): ?>
                            <span class="status-badge pending">Menunggu Bayar</span>
                        <?php else: ?>
                            <span class="status-badge <?= $mp['payment_status'] ?>"><?= ucfirst($mp['payment_status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= !empty($mp['paid_at']) ? date('d/m/Y H:i', strtotime($mp['paid_at'])) : '-' ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada pembayaran Midtrans</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ FILTER ============ -->
<div class="filter-bar">
    <div class="form-group">
        <label class="form-label">Filter Status</label>
        <select class="form-select" onchange="location.href='payments.php?status='+this.value;">
            <option value="">Semua Status</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Menunggu</option>
            <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Terverifikasi</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
    </div>
    <a href="payments.php" class="btn btn-outline btn-sm" style="margin-bottom: 1px;"><i class="fas fa-times"></i> Reset</a>
</div>

<!-- ============ DAFTAR KONFIRMASI MANUAL (LEGACY) ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Konfirmasi Pembayaran Manual — Bukti Transfer (<?= $confirmations ? $confirmations->num_rows : 0 ?>) <span style="font-size: 11px; color: var(--text-muted); font-family: 'Inter', sans-serif; font-weight: 400;">(hanya pesanan lama yang memakai transfer bank)</span></h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Pesanan</th>
                    <th>Pengirim</th>
                    <th>Detail Bank</th>
                    <th>Jumlah</th>
                    <th>Bukti</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th style="width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($confirmations && $confirmations->num_rows > 0): while ($pc = $confirmations->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $pc['id'] ?></td>
                    <td>
                        <strong style="color: var(--soft-gold);"><?= htmlspecialchars($pc['order_number']) ?></strong>
                        <br><small style="font-size: 11px; color: var(--text-light);"><?= htmlspecialchars($pc['customer_info']) ?></small>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($pc['customer_name']) ?></td>
                    <td style="font-size: 12px;">
                        <strong><?= htmlspecialchars($pc['bank_name']) ?></strong><br>
                        <?= htmlspecialchars($pc['account_number']) ?> a.n. <?= htmlspecialchars($pc['account_name']) ?>
                    </td>
                    <td><strong>Rp <?= number_format($pc['amount'], 0, ',', '.') ?></strong><br>
                        <small style="font-size: 11px; color: var(--text-light);">Order: Rp <?= number_format($pc['order_total'], 0, ',', '.') ?></small>
                    </td>
                    <td>
                        <?php if ($pc['proof_image']): ?>
                            <?php
                            // URL Cloudinary penuh, atau path relatif uploads/payments/...
                            $proofHref = (stripos($pc['proof_image'], 'http') === 0) ? $pc['proof_image'] : UPLOADS_URL . '/payments/' . $pc['proof_image'];
                            ?>
                            <a href="<?= htmlspecialchars($proofHref) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-image"></i> Lihat</a>
                        <?php else: ?>
                            <span style="color: var(--text-light); font-size: 12px;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?= $pc['status'] ?>"><?= ucfirst($pc['status']) ?></span>
                        <?php if ($pc['status'] === 'rejected' && $pc['rejection_reason']): ?>
                            <br><small style="font-size: 10px; color: #DC2626;"><?= htmlspecialchars(mb_substr($pc['rejection_reason'], 0, 40)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($pc['created_at'])) ?></td>
                    <td>
                        <?php if ($pc['status'] === 'pending' && hasPermission('payments', 'verify')): ?>
                            <div style="display: flex; gap: 4px; flex-direction: column;">
                                <a href="payments.php?verify=<?= $pc['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-primary btn-sm"
                                   onclick="return confirm('Verifikasi pembayaran #<?= $pc['id'] ?> dan tandai pesanan terbayar?')">
                                    <i class="fas fa-check"></i> Verifikasi
                                </a>
                                <a href="payments.php?reject=<?= $pc['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm"
                                   onclick="var r=prompt('Alasan penolakan (opsional):'); if(r===null)return false; location.href='payments.php?reject=<?= $pc['id'] ?>&csrf_token=<?= csrfToken() ?>&reason='+encodeURIComponent(r); return false;"
                                   style="color: #EF4444; border-color: #EF4444;">
                                    <i class="fas fa-times"></i> Tolak
                                </a>
                            </div>
                        <?php else: ?>
                            <a href="order-detail.php?id=<?= (int)$pc['order_id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Order</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada konfirmasi pembayaran</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
