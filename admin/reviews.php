<?php
// ============================================
// ADMIN - MANAJEMEN ULASAN PRODUK
// Approve / tolak / hapus ulasan dari pengunjung.
// Ulasan baru masuk dengan is_active = 0 (menunggu
// persetujuan) hingga disetujui di halaman ini.
// Nadhira Napoleon Pekanbaru
// ============================================
$currentPage = 'reviews';
$pageTitle = 'Ulasan Produk';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
ensureReviewsSchema();
requirePermission('reviews', 'view');

// ============================================
// AKSI: Approve / Tolak / Hapus
// ============================================
if (isset($_GET['approve'])) {
    requirePermission('reviews', 'edit');
    $rid = (int)$_GET['approve'];
    $rp = $conn->query("SELECT product_id FROM product_reviews WHERE id = $rid LIMIT 1");
    $conn->query("UPDATE product_reviews SET is_active = 1 WHERE id = $rid");
    if ($rp && $rp->num_rows > 0) recalcProductRating((int)$rp->fetch_assoc()['product_id']);
    logActivity('edit', 'reviews', "Menyetujui ulasan produk #$rid");
    header('Location: reviews.php');
    exit;
}

if (isset($_GET['reject'])) {
    requirePermission('reviews', 'edit');
    $rid = (int)$_GET['reject'];
    $rp = $conn->query("SELECT product_id FROM product_reviews WHERE id = $rid LIMIT 1");
    $conn->query("UPDATE product_reviews SET is_active = 0 WHERE id = $rid");
    if ($rp && $rp->num_rows > 0) recalcProductRating((int)$rp->fetch_assoc()['product_id']);
    logActivity('edit', 'reviews', "Menolak ulasan produk #$rid");
    header('Location: reviews.php');
    exit;
}

if (isset($_GET['delete'])) {
    requirePermission('reviews', 'delete');
    $rid = (int)$_GET['delete'];
    $rp = $conn->query("SELECT product_id FROM product_reviews WHERE id = $rid LIMIT 1");
    $pid = ($rp && $rp->num_rows > 0) ? (int)$rp->fetch_assoc()['product_id'] : 0;
    $conn->query("DELETE FROM product_reviews WHERE id = $rid");
    if ($pid > 0) recalcProductRating($pid);
    logActivity('delete', 'reviews', "Menghapus ulasan produk #$rid");
    header('Location: reviews.php');
    exit;
}

// ============================================
// FILTER & DATA
// ============================================
$statusFilter = $_GET['status'] ?? '';
$where = "WHERE 1=1";
if ($statusFilter === 'pending') {
    $where .= " AND r.is_active = 0";
} elseif ($statusFilter === 'approved') {
    $where .= " AND r.is_active = 1";
}

$reviews = $conn->query("
    SELECT r.*, p.name AS product_name,
        CASE WHEN r.user_id IS NOT NULL
             THEN (SELECT COALESCE(NULLIF(full_name,''), username, email) FROM users u WHERE u.id = r.user_id)
             ELSE NULL END AS user_name
    FROM product_reviews r
    LEFT JOIN products p ON p.id = r.product_id
    $where
    ORDER BY r.created_at DESC
");

// Statistik untuk filter badge
$countAll = 0; $countPending = 0; $countApproved = 0;
$statR = $conn->query("SELECT is_active, COUNT(*) c FROM product_reviews GROUP BY is_active");
if ($statR) {
    while ($srow = $statR->fetch_assoc()) {
        if ((int)$srow['is_active'] === 1) $countApproved = (int)$srow['c'];
        else $countPending = (int)$srow['c'];
    }
}
$countAll = $countPending + $countApproved;

require_once __DIR__ . '/layout.php';
?>

            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                <a href="reviews.php" class="btn <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                    Semua (<?= $countAll ?>)
                </a>
                <a href="reviews.php?status=pending" class="btn <?= $statusFilter === 'pending' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                    ⏳ Menunggu (<?= $countPending ?>)
                </a>
                <a href="reviews.php?status=approved" class="btn <?= $statusFilter === 'approved' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                    ✅ Tampil (<?= $countApproved ?>)
                </a>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Produk</th>
                                <th>Pembeli</th>
                                <th>Rating</th>
                                <th>Ulasan</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                                <th style="width: 150px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($reviews && $reviews->num_rows > 0):
                                while ($rv = $reviews->fetch_assoc()):
                                    $rvStars = str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']);
                                    $buyerName = !empty($rv['user_name']) ? $rv['user_name'] : $rv['reviewer_name'];
                            ?>
                            <tr>
                                <td>#<?= (int)$rv['id'] ?></td>
                                <td style="max-width: 200px;">
                                    <a href="<?= SITE_URL ?>/pages/product-detail.php?id=<?= (int)$rv['product_id'] ?>" target="_blank" style="color: var(--text-dark); font-weight: 500;">
                                        <?= htmlspecialchars($rv['product_name'] ?: 'Produk #' . (int)$rv['product_id']) ?>
                                    </a>
                                </td>
                                <td style="max-width: 150px;">
                                    <?= htmlspecialchars($buyerName) ?>
                                    <?php if ((int)$rv['is_verified'] === 1): ?>
                                        <span title="Pembeli terverifikasi" style="color: #059669;"><i class="fas fa-check-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--soft-gold); white-space: nowrap; font-size: 13px;"><?= $rvStars ?></td>
                                <td style="max-width: 280px; font-size: 13px; color: var(--text-secondary);"><?= htmlspecialchars(mb_strimwidth((string)$rv['review'], 0, 120, '…')) ?></td>
                                <td>
                                    <?php if ((int)$rv['is_active'] === 1): ?>
                                    <span class="status-badge active">Tampil</span>
                                    <?php else: ?>
                                    <span class="status-badge inactive">Menunggu / Ditolak</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($rv['created_at'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <?php if ((int)$rv['is_active'] === 0): ?>
                                        <a href="reviews.php?approve=<?= (int)$rv['id'] ?>" class="btn btn-outline btn-sm" title="Setujui (tampilkan)"
                                           style="color: #059669; border-color: #059669;">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="reviews.php?reject=<?= (int)$rv['id'] ?>" class="btn btn-outline btn-sm" title="Tolak (sembunyikan)"
                                           style="color: #D97706; border-color: #D97706;"
                                           onclick="return confirm('Sembunyikan ulasan ini dari halaman produk?')">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="reviews.php?delete=<?= (int)$rv['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus ulasan <?= htmlspecialchars($buyerName) ?> secara permanen?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    <i class="fas fa-star" style="opacity: .4; display: block; margin-bottom: 10px; font-size: 28px;"></i>
                                    Belum ada ulasan <?= $statusFilter !== '' ? 'dengan filter ini' : '' ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
