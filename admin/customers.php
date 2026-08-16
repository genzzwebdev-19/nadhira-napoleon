<?php
$currentPage = 'customers';
$pageTitle = 'Pelanggan';
require_once __DIR__ . '/layout.php';

$conn = getConnection();

$search = $_GET['search'] ?? '';

$where = "WHERE u.role = 'customer'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (u.full_name LIKE '%$s%' OR u.email LIKE '%$s%' OR u.phone LIKE '%$s%')";
}

$customers = $conn->query("
    SELECT u.*, 
        (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
        (SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = u.id AND order_status = 'delivered') as total_spent_db
    FROM users u 
    $where 
    ORDER BY u.created_at DESC
");
?>

            <div class="admin-card">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px;">Cari Pelanggan</label>
                        <input type="text" name="search" class="form-input" placeholder="Nama, email, atau telepon..." value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
                    <a href="customers.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
                </form>
            </div>

            <div class="admin-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                    <h3 class="admin-card-title" style="margin: 0;">Daftar Pelanggan</h3>
                    <span style="font-size: 12px; color: var(--text-muted);"><?= $customers ? $customers->num_rows : 0 ?> pelanggan</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Pelanggan</th>
                                <th>Kontak</th>
                                <th>Poin</th>
                                <th>Pesanan</th>
                                <th>Total Belanja</th>
                                <th>Bergabung</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers && $customers->num_rows > 0): 
                                while ($c = $customers->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 36px; height: 36px; border-radius: 50%; 
                                                    background: linear-gradient(135deg, #D4A853, #B8860B); 
                                                    display: flex; align-items: center; justify-content: center; 
                                                    color: #FFF; font-size: 14px; font-weight: 600; flex-shrink: 0;">
                                            <?= strtoupper(substr($c['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($c['full_name']) ?></strong>
                                            <br><small style="color: var(--text-light);">@<?= htmlspecialchars($c['username']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($c['email']) ?></small>
                                    <br><small style="color: var(--text-light);"><?= htmlspecialchars($c['phone'] ?? '-') ?></small>
                                </td>
                                <td><strong><?= number_format($c['points']) ?></strong></td>
                                <td><?= $c['total_orders'] ?>x</td>
                                <td><strong style="color: var(--warm-orange);">Rp <?= number_format($c['total_spent_db'], 0, ',', '.') ?></strong></td>
                                <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
                                <i class="fas fa-users" style="font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                                Belum ada pelanggan
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
