<?php
$currentPage = 'messages';
$pageTitle = 'Pesan Masuk';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('messages', 'view');

// Handle actions BEFORE layout.php outputs HTML
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $conn->query("UPDATE contacts SET is_read = TRUE WHERE id = $id");
    header('Location: messages.php');
    exit;
}

if (isset($_GET['unread'])) {
    $id = (int)$_GET['unread'];
    $conn->query("UPDATE contacts SET is_read = FALSE WHERE id = $id");
    header('Location: messages.php');
    exit;
}

if (isset($_GET['delete'])) {
    requirePermission('messages', 'delete');
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM contacts WHERE id = $id");
    logActivity('delete', 'messages', "Menghapus pesan #$id");
    header('Location: messages.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$where = "WHERE 1=1";
if ($filter === 'unread') $where .= " AND is_read = FALSE";
elseif ($filter === 'read') $where .= " AND is_read = TRUE";

$messages = $conn->query("SELECT * FROM contacts $where ORDER BY created_at DESC");

// Count stats
$totalAll = $conn->query("SELECT COUNT(*) as c FROM contacts")->fetch_assoc()['c'];
$totalUnread = $conn->query("SELECT COUNT(*) as c FROM contacts WHERE is_read = FALSE")->fetch_assoc()['c'];

require_once __DIR__ . '/layout.php';
?>

            <div class="admin-card">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="messages.php?filter=all" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                        <i class="fas fa-inbox"></i> Semua (<?= $totalAll ?>)
                    </a>
                    <a href="messages.php?filter=unread" class="btn <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                        <i class="fas fa-envelope"></i> Belum Dibaca (<?= $totalUnread ?>)
                    </a>
                    <a href="messages.php?filter=read" class="btn <?= $filter === 'read' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                        <i class="fas fa-envelope-open"></i> Sudah Dibaca (<?= $totalAll - $totalUnread ?>)
                    </a>
                </div>
            </div>

            <div class="admin-card" style="padding: 16px;">
                <?php if ($messages && $messages->num_rows > 0): 
                    while ($m = $messages->fetch_assoc()): 
                    $isUnread = !$m['is_read'];
                ?>
                <div style="display: flex; gap: 16px; padding: 20px; 
                            background: <?= $isUnread ? '#fffbeb' : '#fff' ?>; 
                            border-radius: 12px; margin-bottom: 8px; 
                            border: 1px solid <?= $isUnread ? '#fde68a' : '#f0f0f0' ?>;
                            transition: all 0.2s ease;">
                    <div style="width: 44px; height: 44px; min-width: 44px; border-radius: 50%; 
                                background: <?= $isUnread ? 'linear-gradient(135deg, #D4A853, #B8860B)' : '#f0edf5' ?>; 
                                display: flex; align-items: center; justify-content: center; 
                                color: <?= $isUnread ? '#fff' : '#666' ?>; font-size: 18px;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <strong style="color: <?= $isUnread ? '#92400E' : 'var(--text-dark)' ?>;">
                                    <?= htmlspecialchars($m['name']) ?>
                                    <?php if ($isUnread): ?>
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #D4A853; margin-left: 6px;"></span>
                                    <?php endif; ?>
                                </strong>
                                <span style="color: var(--text-muted); font-size: 12px; margin-left: 8px;">
                                    &lt;<?= htmlspecialchars($m['email']) ?>&gt;
                                </span>
                                <?php if ($m['phone']): ?>
                                    <span style="color: var(--text-muted); font-size: 12px; margin-left: 8px;">
                                        <i class="fas fa-phone"></i> <?= htmlspecialchars($m['phone']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size: 11px; color: var(--text-muted); white-space: nowrap;">
                                <?= formatDate($m['created_at'], 'd M Y H:i') ?>
                            </span>
                        </div>
                        <p style="margin-top: 12px; line-height: 1.7; color: var(--text-secondary); font-size: 14px;">
                            <?= nl2br(htmlspecialchars($m['message'])) ?>
                        </p>
                        <div style="margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php if ($isUnread): ?>
                            <a href="messages.php?read=<?= $m['id'] ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-check"></i> Tandai Dibaca
                            </a>
                            <?php else: ?>
                            <a href="messages.php?unread=<?= $m['id'] ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-envelope"></i> Tandai Belum Dibaca
                            </a>
                            <?php endif; ?>
                            <?php if ($m['phone']): ?>
                            <a href="https://wa.me/62<?= preg_replace('/[^0-9]/', '', $m['phone']) ?>" 
                               class="btn btn-outline btn-sm" target="_blank" style="color: #25D366; border-color: #25D366;">
                                <i class="fab fa-whatsapp"></i> WA
                            </a>
                            <?php endif; ?>
                            <a href="mailto:<?= htmlspecialchars($m['email']) ?>" class="btn btn-outline btn-sm">
                                <i class="fas fa-reply"></i> Email
                            </a>
                            <a href="messages.php?delete=<?= $m['id'] ?>" class="btn btn-outline btn-sm" 
                               onclick="return confirm('Hapus pesan dari <?= htmlspecialchars($m['name']) ?>?')"
                               style="color: #EF4444; border-color: #EF4444;">
                                <i class="fas fa-trash"></i> Hapus
                            </a>
                        </div>
                    </div>
                </div>
                <?php endwhile; else: ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size: 3rem; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                    <p style="font-size: 16px;">Tidak ada pesan</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
