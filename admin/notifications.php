<?php
$currentPage = 'notifications';
$pageTitle = 'Notifikasi';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('notifications', 'view');
$conn = getConnection();
$uid = getCurrentUserId();

// Mark all read
if (isset($_GET['read_all'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $uid");
    header('Location: notifications.php');
    exit;
}

// Mark one read
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $nid AND user_id = $uid");
    header('Location: notifications.php');
    exit;
}

// Delete
if (isset($_GET['delete'])) {
    requirePermission('notifications', 'delete');
    $nid = (int)$_GET['delete'];
    $conn->query("DELETE FROM notifications WHERE id = $nid AND user_id = $uid");
    header('Location: notifications.php');
    exit;
}

$unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id = $uid AND is_read = 0")->fetch_assoc()['c'];
$notifs = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY is_read ASC, created_at DESC LIMIT 100");

require_once __DIR__ . '/layout.php';
?>

<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-bell" style="color: var(--soft-gold);"></i> Notifikasi Saya</h3>
        <div style="display: flex; gap: 8px;">
            <?php if ($unread > 0): ?>
            <a href="?read_all=1" class="btn btn-secondary btn-sm"><i class="fas fa-check-double"></i> Tandai Semua Dibaca</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($unread > 0): ?>
    <div class="alert alert-info" style="margin-bottom: 16px;">
        <i class="fas fa-info-circle"></i> Anda memiliki <strong><?= $unread ?></strong> notifikasi belum dibaca.
    </div>
    <?php endif; ?>

    <?php if ($notifs && $notifs->num_rows > 0): while ($n = $notifs->fetch_assoc()): ?>
    <div style="display: flex; align-items: flex-start; gap: 14px; padding: 16px 18px; border-radius: 12px; margin-bottom: 10px; border: 1px solid <?= $n['is_read'] ? '#f0f0f0' : 'rgba(212,168,83,0.4)' ?>; background: <?= $n['is_read'] ? '#fff' : '#FFFBEB' ?>;">
        <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(212,168,83,0.12); color: #D4A853; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i class="fas <?= $n['type'] === 'success' ? 'fa-check-circle' : ($n['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle') ?>"></i>
        </div>
        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                <strong style="font-size: 14px; color: var(--text-dark);"><?= htmlspecialchars($n['title']) ?></strong>
                <small style="color: var(--text-light); font-size: 11px;"><?= formatDate($n['created_at'], 'd M Y H:i') ?></small>
            </div>
            <?php if ($n['message']): ?>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($n['message']) ?></p>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 4px; flex-shrink: 0;">
            <?php if (!$n['is_read']): ?>
            <a href="?read=<?= $n['id'] ?>" class="btn btn-outline btn-sm" title="Tandai dibaca"><i class="fas fa-check"></i></a>
            <?php endif; ?>
            <a href="?delete=<?= $n['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
               onclick="return confirm('Hapus notifikasi ini?')" style="color: #EF4444; border-color: #EF4444;">
                <i class="fas fa-trash"></i>
            </a>
        </div>
    </div>
    <?php endwhile; else: ?>
    <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
        <i class="fas fa-bell-slash" style="font-size: 40px; color: var(--soft-gold); margin-bottom: 14px; display: block;"></i>
        Tidak ada notifikasi
    </div>
    <?php endif; ?>
</div>
        </main>
    </div>
</body>
</html>
