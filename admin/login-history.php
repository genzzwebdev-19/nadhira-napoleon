<?php
$currentPage = 'login_history';
$pageTitle = 'Riwayat Login';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('login_history', 'view');
$conn = getConnection();

// ============================================
// EXPORT CSV
// ============================================
if (isset($_GET['export'])) {
    requirePermission('login_history', 'export');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="login-history-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Waktu', 'Email', 'User ID', 'Status', 'IP', 'Perangkat', 'Browser']);
    $r = $conn->query("SELECT * FROM login_history ORDER BY id DESC LIMIT 5000");
    if ($r) while ($row = $r->fetch_assoc()) {
        fputcsv($out, [$row['id'], $row['created_at'], $row['email'], $row['user_id'], $row['success'] ? 'Berhasil' : 'Gagal', $row['ip_address'], $row['device'], $row['browser']]);
    }
    fclose($out);
    exit;
}

$where = '';
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = (int)$_GET['status'];
    $where = " AND success = $status";
}
$range = $_GET['range'] ?? '';
if ($range === 'today') $where .= " AND DATE(created_at) = CURDATE()";
if ($range === '7d') $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
if ($range === '30d') $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)$conn->query("SELECT COUNT(*) c FROM login_history WHERE 1=1 $where")->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logs = $conn->query("SELECT * FROM login_history WHERE 1=1 $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");

$failed24h = (int)$conn->query("SELECT COUNT(*) c FROM login_history WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['c'];

require_once __DIR__ . '/layout.php';
?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card">
        <div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-sign-in-alt"></i></div><div><div class="stat-card-value"><?= number_format($total) ?></div></div></div>
        <div class="stat-card-label">Total Percobaan Login</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-card-value"><?= $failed24h ?></div></div></div>
        <div class="stat-card-label">Login Gagal (24 jam)</div>
    </div>
</div>

<!-- ============ FILTER ============ -->
<form method="GET" class="filter-bar">
    <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="">Semua</option>
            <option value="1" <?= ($_GET['status'] ?? '') === '1' ? 'selected' : '' ?>>Berhasil</option>
            <option value="0" <?= ($_GET['status'] ?? '') === '0' ? 'selected' : '' ?>>Gagal</option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Periode</label>
        <select name="range" class="form-select">
            <option value="">Semua waktu</option>
            <option value="today" <?= ($_GET['range'] ?? '') === 'today' ? 'selected' : '' ?>>Hari ini</option>
            <option value="7d" <?= ($_GET['range'] ?? '') === '7d' ? 'selected' : '' ?>>7 hari</option>
            <option value="30d" <?= ($_GET['range'] ?? '') === '30d' ? 'selected' : '' ?>>30 hari</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filter</button>
    <?php if (hasPermission('login_history', 'export')): ?>
    <a href="?export=1" class="btn btn-outline"><i class="fas fa-download"></i> Export CSV</a>
    <?php endif; ?>
</form>

<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-fingerprint" style="color: var(--soft-gold);"></i> Riwayat Login</h3>
        <span class="status-badge processing"><?= number_format($total) ?> catatan</span>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 90px;">Waktu</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>IP Address</th>
                    <th>Perangkat</th>
                    <th>Browser</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): while ($l = $logs->fetch_assoc()): ?>
                <tr>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($l['email'] ?: '-') ?></strong>
                        <?php if ($l['user_id']): ?><br><small style="font-size: 11px; color: var(--text-light);">user #<?= $l['user_id'] ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['success']): ?>
                            <span class="status-badge active"><i class="fas fa-check"></i> Berhasil</span>
                        <?php else: ?>
                            <span class="status-badge rejected"><i class="fas fa-times"></i> Gagal</span>
                        <?php endif; ?>
                    </td>
                    <td><code style="background: var(--soft-grey); padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?= htmlspecialchars($l['ip_address']) ?></code></td>
                    <td style="font-size: 12px;">
                        <i class="fas <?= $l['device'] === 'Mobile' ? 'fa-mobile-alt' : ($l['device'] === 'Tablet' ? 'fa-tablet-alt' : 'fa-desktop') ?>" style="color: var(--soft-gold);"></i>
                        <?= htmlspecialchars($l['device'] ?: '-') ?>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($l['browser'] ?: '-') ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada riwayat login</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="btn btn-primary btn-sm" style="cursor: default;"><?= $i ?></span>
            <?php else: ?>
                <a class="btn btn-outline btn-sm" href="?<?= http_build_query(array_merge(array_filter($_GET, fn($v) => $v !== 'page'), ['page' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
        </main>
    </div>
</body>
</html>
