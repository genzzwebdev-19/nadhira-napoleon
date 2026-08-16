<?php
$currentPage = 'sessions';
$pageTitle = 'Sesi Aktif';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('sessions', 'view');
$conn = getConnection();

$info = '';
$success = '';

// ============================================
// ACTION: Revoke sesi tertentu
// ============================================
if (isset($_GET['revoke'])) {
    requirePermission('sessions', 'delete');
    $sid = (int)$_GET['revoke'];
    $conn->query("UPDATE user_sessions SET is_active = 0 WHERE id = $sid");
    $success = 'Sesi berhasil dicabut.';
    logActivity('delete', 'sessions', "Mencabut sesi #$sid");
    header('Location: sessions.php');
    exit;
}

// ============================================
// ACTION: Logout semua perangkat lain (kecuali sesi ini)
// ============================================
if (isset($_GET['logout_others'])) {
    requirePermission('sessions', 'delete');
    $uid = getCurrentUserId();
    $token = $_SESSION['session_token'] ?? '';
    revokeOtherSessions($uid, $token);
    $success = 'Semua perangkat lain berhasil logout. Sesi ini tetap aktif.';
    logActivity('delete', 'sessions', 'Logout semua perangkat lain');
    header('Location: sessions.php');
    exit;
}

// Data sesi
$uid = getCurrentUserId();
$myToken = $_SESSION['session_token'] ?? '';
$sessions = $conn->query("
    SELECT us.*, u.full_name, u.email
    FROM user_sessions us
    JOIN users u ON u.id = us.user_id
    WHERE us.is_active = 1
    ORDER BY us.last_activity DESC
    LIMIT 200
");

require_once __DIR__ . '/layout.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($info): ?>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= $info ?></div>
<?php endif; ?>

<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin-bottom: 0;"><i class="fas fa-plug" style="color: var(--soft-gold);"></i> Sesi Aktif</h3>
        <a href="?logout_others=1" class="btn btn-danger btn-sm" onclick="return confirm('Logout semua perangkat lain?')">
            <i class="fas fa-sign-out-alt"></i> Logout Semua Perangkat Lain
        </a>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>User</th>
                    <th>Perangkat</th>
                    <th>Browser</th>
                    <th>IP</th>
                    <th>Aktivitas Terakhir</th>
                    <th>Kadaluarsa</th>
                    <th style="width: 90px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sessions && $sessions->num_rows > 0): while ($s = $sessions->fetch_assoc()):
                    $isMine = $s['session_token'] === $myToken;
                ?>
                <tr style="<?= $isMine ? 'background: rgba(212,168,83,0.05);' : '' ?>">
                    <td>#<?= $s['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                        <?php if ($isMine): ?><span class="status-badge active" style="margin-left: 4px; font-size: 10px;">PERANGKAT INI</span><?php endif; ?>
                        <br><small style="font-size: 11px; color: var(--text-light);"><?= htmlspecialchars($s['email']) ?></small>
                    </td>
                    <td style="font-size: 12px;">
                        <i class="fas <?= $s['device'] === 'Mobile' ? 'fa-mobile-alt' : ($s['device'] === 'Tablet' ? 'fa-tablet-alt' : 'fa-desktop') ?>" style="color: var(--soft-gold);"></i>
                        <?= htmlspecialchars($s['device'] ?: '-') ?>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($s['browser'] ?: '-') ?></td>
                    <td><code style="background: var(--soft-grey); padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?= htmlspecialchars($s['ip_address']) ?></code></td>
                    <td style="font-size: 12px;"><?= date('d M H:i', strtotime($s['last_activity'])) ?></td>
                    <td style="font-size: 12px;"><?= date('d M Y', strtotime($s['expires_at'])) ?></td>
                    <td>
                        <?php if (!$isMine): ?>
                        <a href="?revoke=<?= $s['id'] ?>" class="btn btn-outline btn-sm" title="Cabut sesi"
                           onclick="return confirm('Cabut sesi ini? User akan diminta login ulang.')"
                           style="color: #EF4444; border-color: #EF4444;">
                            <i class="fas fa-ban"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Tidak ada sesi aktif</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
