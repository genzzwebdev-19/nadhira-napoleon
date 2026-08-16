<?php
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require_once __DIR__ . '/layout.php';

$conn = getConnection();

// ============================================
// WIDGET DATA (hanya untuk widget yang tampil)
// ============================================
$widgets = getUserWidgetSlugs();
$allWidgets = getAllWidgets();
if ($widgets === null) {
    $widgets = array_column($allWidgets, 'slug');
}

$data = [];

if (in_array('stats_revenue', $widgets, true)) {
    $r = $conn->query("SELECT COALESCE(SUM(total),0) rev FROM orders WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND order_status='delivered'");
    $data['revenue'] = $r ? (float)$r->fetch_assoc()['rev'] : 0;
}
if (in_array('stats_orders', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM orders");
    $data['orders'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('stats_products', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM products WHERE is_active = TRUE");
    $data['products'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('stats_customers', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'customer'");
    $data['customers'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('low_stock', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM products WHERE stock > 0 AND stock <= 5 AND is_active = TRUE");
    $data['low_stock'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('unread_messages', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM contacts WHERE is_read = FALSE");
    $data['unread_messages'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('pending_payments', $widgets, true)) {
    $data['pending_payments'] = 0;
    $check = $conn->query("SHOW TABLES LIKE 'payment_confirmations'");
    if ($check && $check->num_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) c FROM payment_confirmations WHERE status = 'pending'");
        $data['pending_payments'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
    }
}
if (in_array('promo_status', $widgets, true)) {
    $r = $conn->query("SELECT COUNT(*) c FROM promotions WHERE is_active = TRUE AND end_date >= NOW()");
    $data['promo_active'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
if (in_array('recent_orders', $widgets, true)) {
    $data['recent_orders'] = $conn->query("SELECT o.id, o.order_number, o.customer_name, o.total, o.order_status, o.created_at FROM orders o ORDER BY o.created_at DESC LIMIT 5");
}
if (in_array('top_products', $widgets, true)) {
    $data['top_products'] = $conn->query("SELECT name, price, total_sold FROM products WHERE is_active = TRUE ORDER BY total_sold DESC LIMIT 5");
}
if (in_array('sales_chart', $widgets, true)) {
    $chartLabels = [];
    $chartValues = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        $chartLabels[] = date('d M', strtotime($day));
        $r = $conn->query("SELECT COALESCE(SUM(total),0) rev FROM orders WHERE DATE(created_at) = '$day' AND order_status = 'delivered'");
        $chartValues[] = $r ? (float)$r->fetch_assoc()['rev'] : 0;
    }
    $data['chart_labels'] = $chartLabels;
    $data['chart_values'] = $chartValues;
}
if (in_array('membership_stats', $widgets, true)) {
    $levels = ['silver', 'gold', 'platinum', 'diamond'];
    $data['membership'] = [];
    foreach ($levels as $lv) {
        $r = $conn->query("SELECT COUNT(*) c FROM users WHERE membership = '$lv'");
        $data['membership'][$lv] = $r ? (int)$r->fetch_assoc()['c'] : 0;
    }
}
if (in_array('dashboard_summary', $widgets, true)) {
    $u = getCurrentUser();
    $data['summary'] = [
        'name' => $u['full_name'] ?? '',
        'role' => getPrimaryRoleName(),
        'branch' => empty(getAccessibleBranchIds()) ? 'Semua Cabang' : (count(getAccessibleBranchIds()) . ' Cabang'),
        'last_login' => $u['last_login'] ?? '',
    ];
}
if (in_array('profile_summary', $widgets, true)) {
    $u = getCurrentUser();
    $data['profile'] = [
        'name' => $u['full_name'] ?? '',
        'email' => $u['email'] ?? '',
        'phone' => $u['phone'] ?? '',
        'role' => getPrimaryRoleName(),
        'last_login' => $u['last_login'] ?? '',
    ];
}
if (in_array('notifications_list', $widgets, true)) {
    $uid = getCurrentUserId();
    $r = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY created_at DESC LIMIT 5");
    $data['notifications'] = [];
    if ($r) while ($row = $r->fetch_assoc()) $data['notifications'][] = $row;
}

// ============================================
// RENDER WIDGET
// ============================================
$widgetMeta = [];
foreach ($allWidgets as $w) $widgetMeta[$w['slug']] = $w;

function renderWidget($slug, $data, $widgetMeta = []) {
    switch ($slug) {
        case 'stats_revenue':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-dollar-sign"></i></div><div><div class="stat-card-value">Rp ' . number_format($data['revenue'] ?? 0, 0, ',', '.') . '</div></div></div><div class="stat-card-label">Pendapatan Bulan Ini</div></div>';
        case 'stats_orders':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-shopping-cart"></i></div><div><div class="stat-card-value">' . number_format($data['orders'] ?? 0) . '</div></div></div><div class="stat-card-label">Total Pesanan</div></div>';
        case 'stats_products':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-shopping-bag"></i></div><div><div class="stat-card-value">' . number_format($data['products'] ?? 0) . '</div></div></div><div class="stat-card-label">Total Produk Aktif</div></div>';
        case 'stats_customers':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon success"><i class="fas fa-users"></i></div><div><div class="stat-card-value">' . number_format($data['customers'] ?? 0) . '</div></div></div><div class="stat-card-label">Pelanggan Terdaftar</div></div>';
        case 'low_stock':
            $n = $data['low_stock'] ?? 0;
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-card-value">' . $n . '</div></div></div><div class="stat-card-label">Produk Hampir Habis</div><div class="stat-card-change ' . ($n > 0 ? 'down' : 'up') . '"><i class="fas ' . ($n > 0 ? 'fa-exclamation' : 'fa-check') . '"></i> ' . ($n > 0 ? 'Perlu restock' : 'Stok aman') . '</div></div>';
        case 'unread_messages':
            $n = $data['unread_messages'] ?? 0;
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-envelope"></i></div><div><div class="stat-card-value">' . $n . '</div></div></div><div class="stat-card-label">Pesan Baru</div><a href="messages.php" class="btn btn-outline btn-sm" style="margin-top: 12px;">Lihat Pesan</a></div>';
        case 'pending_payments':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-money-bill-wave"></i></div><div><div class="stat-card-value">' . ($data['pending_payments'] ?? 0) . '</div></div></div><div class="stat-card-label">Konfirmasi Pembayaran Menunggu</div></div>';
        case 'promo_status':
            return '<div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-percent"></i></div><div><div class="stat-card-value">' . ($data['promo_active'] ?? 0) . '</div></div></div><div class="stat-card-label">Promo Berjalan</div><a href="promo.php" class="btn btn-outline btn-sm" style="margin-top: 12px;">Kelola Promo</a></div>';
        case 'recent_orders':
            ob_start();
            ?>
            <div class="admin-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
                    <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-clock" style="color: var(--soft-gold);"></i> Pesanan Terbaru</h3>
                    <a href="orders.php" class="btn btn-outline btn-sm">Lihat Semua</a>
                </div>
                <table class="admin-table">
                    <thead><tr><th>No. Pesanan</th><th>Pelanggan</th><th>Total</th><th>Status</th><th>Tanggal</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if (isset($data['recent_orders']) && $data['recent_orders'] && $data['recent_orders']->num_rows > 0): while ($o = $data['recent_orders']->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                            <td><?= htmlspecialchars($o['customer_name']) ?></td>
                            <td>Rp <?= number_format($o['total'], 0, ',', '.') ?></td>
                            <td><span class="status-badge <?= $o['order_status'] ?>"><?= ucfirst($o['order_status']) ?></span></td>
                            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                            <td><a href="order-detail.php?id=<?= $o['id'] ?>" class="btn btn-outline btn-sm">Detail</a></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">Belum ada pesanan</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            return ob_get_clean();
        case 'top_products':
            ob_start();
            ?>
            <div class="admin-card">
                <h3 class="admin-card-title"><i class="fas fa-trophy" style="color: var(--soft-gold);"></i> Produk Terlaris</h3>
                <table class="admin-table">
                    <thead><tr><th>Produk</th><th>Harga</th><th>Terjual</th></tr></thead>
                    <tbody>
                    <?php if (isset($data['top_products']) && $data['top_products'] && $data['top_products']->num_rows > 0): while ($p = $data['top_products']->fetch_assoc()): ?>
                        <tr><td><strong><?= htmlspecialchars($p['name']) ?></strong></td><td>Rp <?= number_format($p['price'], 0, ',', '.') ?></td><td><span class="status-badge active"><?= (int)$p['total_sold'] ?> terjual</span></td></tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="3" style="text-align: center; color: var(--text-muted);">Belum ada data</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            return ob_get_clean();
        case 'sales_chart':
            ob_start();
            ?>
            <div class="admin-card">
                <h3 class="admin-card-title"><i class="fas fa-chart-line" style="color: var(--soft-gold);"></i> Grafik Penjualan 7 Hari Terakhir</h3>
                <canvas id="salesChart" height="90"></canvas>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
            (function () {
                var canvas = document.getElementById('salesChart');
                if (!canvas || typeof Chart === 'undefined') return;
                var labels = <?= json_encode($data['chart_labels'] ?? []) ?>;
                var values = <?= json_encode($data['chart_values'] ?? []) ?>;
                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Pendapatan (Rp)',
                            data: values,
                            borderColor: '#D4A853',
                            backgroundColor: 'rgba(212,168,83,0.15)',
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#B8860B',
                            pointRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: function (v) { return 'Rp ' + (v / 1000).toFixed(0) + 'k'; } } }
                        }
                    }
                });
            })();
            </script>
            <?php
            return ob_get_clean();
        case 'membership_stats':
            ob_start();
            $m = $data['membership'] ?? ['silver' => 0, 'gold' => 0, 'platinum' => 0, 'diamond' => 0];
            ?>
            <div class="admin-card">
                <h3 class="admin-card-title"><i class="fas fa-crown" style="color: var(--soft-gold);"></i> Distribusi Membership</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px;">
                    <?php foreach ($m as $level => $count): ?>
                    <div style="background: var(--warm-white); border-radius: 12px; padding: 16px; text-align: center;">
                        <div class="stat-card-value" style="font-size: 22px;"><?= number_format($count) ?></div>
                        <div style="margin-top: 4px;"><span class="status-badge <?= $level ?>"><?= ucfirst($level) ?></span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        case 'dashboard_summary':
            $s = $data['summary'] ?? ['name' => 'Admin', 'role' => '', 'branch' => '', 'last_login' => ''];
            return '<div class="stat-card" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #fff;">'
                . '<div class="stat-card-header" style="color: #fff;"><div class="stat-card-icon" style="background: rgba(212,168,83,0.2); color: #D4A853;"><i class="fas fa-chart-pie"></i></div><div><div class="stat-card-value" style="color: #fff; font-size: 20px;">Halo, ' . htmlspecialchars(mb_substr($s['name'], 0, 20)) . '!</div></div></div>'
                . '<div class="stat-card-label" style="color: rgba(255,248,240,0.7);">'
                . '<i class="fas fa-user-shield"></i> ' . htmlspecialchars($s['role']) . '<br>'
                . '<i class="fas fa-store"></i> ' . htmlspecialchars($s['branch']) . '<br>'
                . ($s['last_login'] ? '<i class="fas fa-clock"></i> Login terakhir: ' . formatDate($s['last_login'], 'd M Y H:i') : '')
                . '</div>'
                . '<a href="profile.php" class="btn btn-outline btn-sm" style="margin-top: 12px; color: #D4A853; border-color: rgba(212,168,83,0.4);">Lihat Profil</a>'
                . '</div>';
        case 'profile_summary':
            $p = $data['profile'] ?? ['name' => '', 'email' => '', 'phone' => '', 'role' => '', 'last_login' => ''];
            $initial = strtoupper(substr($p['name'] ?: 'A', 0, 1));
            return '<div class="stat-card"><div style="display: flex; align-items: center; gap: 14px;">'
                . '<div class="admin-avatar" style="width: 52px; height: 52px; font-size: 22px;">' . htmlspecialchars($initial) . '</div>'
                . '<div style="min-width: 0;"><div class="stat-card-value" style="font-size: 18px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' . htmlspecialchars($p['name']) . '</div>'
                . '<div class="stat-card-label">' . htmlspecialchars($p['role']) . '</div></div></div>'
                . '<div style="margin-top: 14px; font-size: 12px; color: var(--text-muted); line-height: 1.9;">'
                . '<div><i class="fas fa-envelope" style="width: 16px;"></i> ' . htmlspecialchars($p['email']) . '</div>'
                . ($p['phone'] ? '<div><i class="fas fa-phone" style="width: 16px;"></i> ' . htmlspecialchars($p['phone']) . '</div>' : '')
                . '</div>'
                . '<a href="profile.php" class="btn btn-outline btn-sm" style="margin-top: 12px;">Kelola Profil</a>'
                . '</div>';
        case 'notifications_list':
            $items = $data['notifications'] ?? [];
            $unread = 0;
            foreach ($items as $n) if (!(int)$n['is_read']) $unread++;
            ob_start();
            ?>
            <div class="admin-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-bell" style="color: var(--soft-gold);"></i> Notifikasi Terbaru</h3>
                    <?php if ($unread > 0): ?><span class="nav-badge danger"><?= $unread ?> baru</span><?php endif; ?>
                </div>
                <?php if (empty($items)): ?>
                    <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px;"><i class="fas fa-bell-slash" style="font-size: 22px; margin-bottom: 8px; display: block;"></i>Tidak ada notifikasi</div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column;">
                    <?php foreach ($items as $n): ?>
                        <a href="<?= htmlspecialchars($n['link'] ?: 'notifications.php') ?>" class="notif-row" style="display: flex; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: inherit; transition: background 0.15s; <?= $n['is_read'] ? '' : 'background: rgba(212,168,83,0.08);' ?>">
                            <i class="fas fa-circle" style="font-size: 8px; color: <?= $n['is_read'] ? 'var(--text-light)' : '#D4A853' ?>; margin-top: 6px;"></i>
                            <div style="min-width: 0;">
                                <div style="font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($n['title']) ?></div>
                                <?php if ($n['message']): ?><div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars(mb_substr($n['message'], 0, 80)) ?></div><?php endif; ?>
                                <div style="font-size: 11px; color: var(--text-light);"><?= formatDate($n['created_at'], 'd M Y H:i') ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    </div>
                    <a href="notifications.php" class="btn btn-outline btn-sm" style="margin-top: 12px;">Lihat Semua</a>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        default:
            // Widget baru/belum ada render khusus -> kartu fallback
            $meta = $widgetMeta[$slug] ?? [];
            $icon = $meta['icon'] ?? 'fa-chart-bar';
            $title = $meta['title'] ?? ucfirst(str_replace('_', ' ', $slug));
            $desc = $meta['description'] ?? 'Widget ini belum memiliki render khusus. Hubungi Developer untuk menambahkan logika render-nya di admin/index.php.';
            return '<div class="admin-card" style="text-align: center; padding: 40px;">'
                . '<div class="stat-card-icon" style="margin: 0 auto 16px;"><i class="fas ' . htmlspecialchars($icon) . '"></i></div>'
                . '<h3 class="admin-card-title" style="margin-bottom: 8px;">' . htmlspecialchars($title) . '</h3>'
                . '<p style="color: var(--text-muted); font-size: 13px; max-width: 420px; margin: 0 auto;">' . htmlspecialchars($desc) . '</p>'
                . '</div>';
    }
}
?>

<?php
// Render: widget small -> stats-grid, lainnya -> stacked
$smallWidgets = [];
$otherWidgets = [];
foreach ($widgets as $slug) {
    $size = 'small';
    foreach ($allWidgets as $w) {
        if ($w['slug'] === $slug) { $size = $w['size']; break; }
    }
    if ($size === 'small') $smallWidgets[] = $slug; else $otherWidgets[] = $slug;
}
?>

<?php if (!empty($smallWidgets)): ?>
    <div class="stats-grid">
        <?php foreach ($smallWidgets as $slug): ?>
            <?= renderWidget($slug, $data, $widgetMeta) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach ($otherWidgets as $slug): ?>
    <?= renderWidget($slug, $data, $widgetMeta) ?>
<?php endforeach; ?>

<?php if (empty($widgets)): ?>
    <div class="admin-card" style="text-align: center; padding: 60px;">
        <i class="fas fa-chart-bar" style="font-size: 40px; color: var(--soft-gold); margin-bottom: 16px; display: block;"></i>
        <p style="color: var(--text-muted);">Tidak ada widget dashboard untuk role Anda. Hubungi Super Admin untuk mengatur widget.</p>
    </div>
<?php endif; ?>
        </main>
    </div>
</body>
</html>
