<?php
$currentPage = 'activity_logs';
$pageTitle = 'Audit Log';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('activity_logs', 'view');
$conn = getConnection();

// ============================================
// EXPORT CSV
// ============================================
if (isset($_GET['export'])) {
    requirePermission('activity_logs', 'export');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM untuk Excel
    fputcsv($out, ['ID', 'Waktu', 'User', 'Aksi', 'Modul', 'Deskripsi', 'IP']);

    $where = buildWhere();
    $r = $conn->query("SELECT * FROM activity_logs WHERE 1=1 $where ORDER BY id DESC LIMIT 5000");
    if ($r) while ($row = $r->fetch_assoc()) {
        fputcsv($out, [$row['id'], $row['created_at'], $row['user_name'], $row['action'], $row['module'], $row['description'], $row['ip_address']]);
    }
    fclose($out);
    exit;
}

function buildWhere() {
    $parts = [];
    $action = $_GET['action'] ?? '';
    $module = $_GET['module'] ?? '';
    $q = trim($_GET['q'] ?? '');
    $range = $_GET['range'] ?? '';

    if ($action !== '') $parts[] = "action = '" . getConnection()->real_escape_string($action) . "'";
    if ($module !== '') $parts[] = "module = '" . getConnection()->real_escape_string($module) . "'";
    if ($q !== '') {
        $qe = getConnection()->real_escape_string($q);
        $parts[] = "(user_name LIKE '%$qe%' OR description LIKE '%$qe%')";
    }
    if ($range === 'today') $parts[] = "DATE(created_at) = CURDATE()";
    if ($range === '7d') $parts[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    if ($range === '30d') $parts[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    return $parts ? ' AND ' . implode(' AND ', $parts) : '';
}

$where = buildWhere();
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)$conn->query("SELECT COUNT(*) c FROM activity_logs WHERE 1=1 $where")->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logs = $conn->query("SELECT * FROM activity_logs WHERE 1=1 $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");

// Daftar aksi & modul untuk filter
$actions = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
$modules = $conn->query("SELECT DISTINCT module FROM activity_logs WHERE module <> '' ORDER BY module");

require_once __DIR__ . '/layout.php';
?>

<!-- ============ FILTER ============ -->
<form method="GET" class="filter-bar">
    <div class="form-group">
        <label class="form-label">Cari</label>
        <input type="text" name="q" class="form-input" placeholder="Nama user / deskripsi" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">Aksi</label>
        <select name="action" class="form-select">
            <option value="">Semua</option>
            <?php if ($actions): while ($a = $actions->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($a['action']) ?>" <?= ($_GET['action'] ?? '') === $a['action'] ? 'selected' : '' ?>><?= htmlspecialchars($a['action']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Modul</label>
        <select name="module" class="form-select">
            <option value="">Semua</option>
            <?php if ($modules): while ($m = $modules->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($m['module']) ?>" <?= ($_GET['module'] ?? '') === $m['module'] ? 'selected' : '' ?>><?= htmlspecialchars($m['module']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Periode</label>
        <select name="range" class="form-select">
            <option value="">Semua waktu</option>
            <option value="today" <?= ($_GET['range'] ?? '') === 'today' ? 'selected' : '' ?>>Hari ini</option>
            <option value="7d" <?= ($_GET['range'] ?? '') === '7d' ? 'selected' : '' ?>>7 hari terakhir</option>
            <option value="30d" <?= ($_GET['range'] ?? '') === '30d' ? 'selected' : '' ?>>30 hari terakhir</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filter</button>
    <?php if (hasPermission('activity_logs', 'export')): ?>
    <a href="?export=1<?= $where ? '&' . http_build_query(array_filter($_GET, fn($v) => $v !== 'export' && $v !== 'page')) : '' ?>" class="btn btn-outline"><i class="fas fa-download"></i> Export CSV</a>
    <?php endif; ?>
</form>

<!-- ============ TABEL ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-clipboard-list" style="color: var(--soft-gold);"></i> Riwayat Aktivitas</h3>
        <span class="status-badge processing"><?= number_format($total) ?> catatan</span>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 80px;">Waktu</th>
                    <th>User</th>
                    <th>Aksi</th>
                    <th>Modul</th>
                    <th>Deskripsi</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && $logs->num_rows > 0): while ($l = $logs->fetch_assoc()): ?>
                <tr>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($l['user_name'] ?: '-') ?></strong>
                        <br><small style="font-size: 11px; color: var(--text-light);">#<?= $l['user_id'] ?: '-' ?></small>
                    </td>
                    <td><span class="status-badge <?= in_array($l['action'], ['delete', 'logout'], true) ? 'rejected' : (in_array($l['action'], ['create', 'login', 'backup'], true) ? 'active' : 'pending') ?>"><?= htmlspecialchars($l['action']) ?></span></td>
                    <td><code style="background: var(--soft-grey); padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?= htmlspecialchars($l['module'] ?: '-') ?></code></td>
                    <td style="font-size: 12px; max-width: 320px;"><?= htmlspecialchars(mb_substr($l['description'] ?? '', 0, 160)) ?></td>
                    <td style="font-size: 12px;"><code><?= htmlspecialchars($l['ip_address']) ?></code></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada aktivitas tercatat</td></tr>
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
