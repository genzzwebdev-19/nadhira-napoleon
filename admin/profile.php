<?php
$currentPage = 'profile';
$pageTitle = 'Profil Saya';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('profile', 'view');
$conn = getConnection();
$uid = getCurrentUserId();

$success = '';
$errors = [];

// ============================================
// UPDATE PROFIL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    verifyCsrf();
    requirePermission('profile', 'edit');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($fullName)) {
        $errors[] = 'Nama tidak boleh kosong';
    } else {
        $name_e = $conn->real_escape_string($fullName);
        $ph_e = $conn->real_escape_string($phone);
        $conn->query("UPDATE users SET full_name = '$name_e', phone = '$ph_e' WHERE id = $uid");
        $_SESSION['user_name'] = $fullName;
        $success = 'Profil berhasil diperbarui!';
        logActivity('update', 'profile', 'Memperbarui profil sendiri');
    }
}

// ============================================
// GANTI PASSWORD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pwd'])) {
    verifyCsrf();
    requirePermission('profile', 'edit');
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $r = $conn->query("SELECT password FROM users WHERE id = $uid LIMIT 1");
    $hash = $r ? $r->fetch_assoc()['password'] : '';

    if (!password_verify($current, $hash)) {
        $errors[] = 'Password saat ini salah';
    } elseif (strlen($new) < 6) {
        $errors[] = 'Password baru minimal 6 karakter';
    } elseif ($new !== $confirm) {
        $errors[] = 'Konfirmasi password tidak cocok';
    } else {
        $newHash = password_hash($new, PASSWORD_BCRYPT);
        $conn->query("UPDATE users SET password = '$newHash' WHERE id = $uid");
        $success = 'Password berhasil diganti!';
        logActivity('settings', 'profile', 'Mengganti password sendiri');
    }
}

$user = $conn->query("SELECT * FROM users WHERE id = $uid LIMIT 1")->fetch_assoc();
$roles = getUserRoles($uid);
$roleNames = array_map(fn($r) => $r['name'], $roles);

// Timeline aktivitas sendiri
$timeline = $conn->query("SELECT * FROM activity_logs WHERE user_id = $uid ORDER BY id DESC LIMIT 15");
// Riwayat login sendiri
$myLogins = $conn->query("SELECT * FROM login_history WHERE user_id = $uid ORDER BY id DESC LIMIT 10");

require_once __DIR__ . '/layout.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="stat-card">
        <div class="stat-card-header">
            <div class="admin-avatar" style="width: 56px; height: 56px; font-size: 22px;">
                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 15px; color: var(--text-dark);"><?= htmlspecialchars($user['full_name']) ?></div>
                <div class="admin-profile-role"><?= htmlspecialchars(getPrimaryRoleName()) ?></div>
            </div>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
            <div><i class="fas fa-envelope" style="width: 16px;"></i> <?= htmlspecialchars($user['email']) ?></div>
            <?php if ($user['phone']): ?><div style="margin-top: 4px;"><i class="fas fa-phone" style="width: 16px;"></i> <?= htmlspecialchars($user['phone']) ?></div><?php endif; ?>
            <div style="margin-top: 4px;"><i class="fas fa-clock" style="width: 16px;"></i> Bergabung <?= formatDate($user['created_at'], 'd M Y') ?></div>
        </div>
    </div>
    <?php foreach ($roles as $role): ?>
    <div class="stat-card" style="display: flex; align-items: center; gap: 14px;">
        <div class="stat-card-icon <?= $role['is_system'] ? 'warning' : '' ?>"><i class="fas fa-user-shield"></i></div>
        <div>
            <div style="font-weight: 700; color: var(--text-dark);"><?= htmlspecialchars($role['name']) ?></div>
            <div class="stat-card-label"><?= htmlspecialchars($role['slug']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="form-row" style="grid-template-columns: 1fr 1fr;">
    <!-- Edit Profil -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-user-edit" style="color: var(--soft-gold);"></i> Edit Profil</h3>
        <form method="POST">
            <input type="hidden" name="save_profile" value="1">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Nama Lengkap</label>
                <input type="text" name="full_name" class="form-input" required value="<?= htmlspecialchars($user['full_name']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">No. HP</label>
                <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Profil</button>
        </form>
    </div>

    <!-- Ganti Password -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-key" style="color: var(--soft-gold);"></i> Ganti Password</h3>
        <form method="POST">
            <input type="hidden" name="change_pwd" value="1">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Password Saat Ini</label>
                <input type="password" name="current_password" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password Baru</label>
                <input type="password" name="new_password" class="form-input" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" class="form-input" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Ganti Password</button>
        </form>
    </div>
</div>

<div class="form-row" style="grid-template-columns: 1fr 1fr;">
    <!-- Timeline Aktivitas -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-stream" style="color: var(--soft-gold);"></i> Timeline Aktivitas</h3>
        <?php if ($timeline && $timeline->num_rows > 0): ?>
            <div style="border-left: 2px solid rgba(212,168,83,0.3); padding-left: 20px;">
                <?php while ($t = $timeline->fetch_assoc()): ?>
                <div style="position: relative; margin-bottom: 16px;">
                    <div style="position: absolute; left: -27px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #D4A853; border: 2px solid #fff; box-shadow: 0 0 0 2px rgba(212,168,83,0.3);"></div>
                    <div style="font-size: 12px; color: var(--text-muted);"><?= formatDate($t['created_at'], 'd M Y H:i') ?></div>
                    <div style="font-size: 13px; font-weight: 600; color: var(--text-dark); text-transform: capitalize;"><?= htmlspecialchars($t['action']) ?> <?= $t['module'] ? '<span style="color: var(--soft-gold);">(' . htmlspecialchars($t['module']) . ')</span>' : '' ?></div>
                    <?php if ($t['description']): ?><div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars(mb_substr($t['description'], 0, 120)) ?></div><?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 13px;">Belum ada aktivitas</p>
        <?php endif; ?>
    </div>    <!-- Riwayat Login Pribadi -->
    <div class="admin-card">
        <h3 class="admin-card-title"><i class="fas fa-sign-in-alt" style="color: var(--soft-gold);"></i> Riwayat Login</h3>
        <?php if ($myLogins && $myLogins->num_rows > 0): ?>
            <table class="admin-table">
                <thead><tr><th>Waktu</th><th>Status</th><th>Perangkat</th><th>IP</th></tr></thead>
                <tbody>
                <?php while ($l = $myLogins->fetch_assoc()): ?>
                    <tr>
                        <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                        <td><?= $l['success'] ? '<span class="status-badge active">Berhasil</span>' : '<span class="status-badge rejected">Gagal</span>' ?></td>
                        <td style="font-size: 12px;"><?= htmlspecialchars($l['device'] ?: '-') ?></td>
                        <td><code style="background: var(--soft-grey); padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?= htmlspecialchars($l['ip_address']) ?></code></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 13px;">Belum ada riwayat login</p>
        <?php endif; ?>
    </div>
</div>

<!-- Logout Section -->
<div class="admin-card" style="margin-top: 20px;">
    <h3 class="admin-card-title"><i class="fas fa-shield-alt" style="color: var(--soft-gold);"></i> Keamanan Akun</h3>
    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
        <a href="?logout_others=1" class="btn btn-outline" onclick="return confirm('Logout semua perangkat lain? Sesi ini akan tetap aktif.')">
            <i class="fas fa-sign-out-alt"></i> Logout Semua Perangkat Lain
        </a>
        <a href="<?= SITE_URL ?>/auth/logout.php" class="btn btn-danger">
            <i class="fas fa-sign-out-alt"></i> Keluar (Logout)
        </a>
    </div>
</div>

<?php
// Handle logout other devices
if (isset($_GET['logout_others'])) {
    requirePermission('profile', 'edit');
    $token = $_SESSION['session_token'] ?? '';
    revokeOtherSessions($uid, $token);
    $success = 'Semua perangkat lain berhasil logout. Sesi ini tetap aktif.';
    logActivity('delete', 'sessions', 'Logout semua perangkat lain');
    echo '<script>location.href="profile.php?success=1";</script>';
    exit;
}

if (isset($_GET['success'])) {
    $success = 'Semua perangkat lain berhasil logout. Sesi ini tetap aktif.';
}
?>

