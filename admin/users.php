<?php
$currentPage = 'users';
$pageTitle = 'Manajemen User';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('users', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan user (create / edit)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('users', $editId > 0 ? 'edit' : 'create');

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $roles = $_POST['roles'] ?? [];
    $branches = $_POST['branches'] ?? []; // kosong = semua cabang
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($fullName)) $errors[] = 'Nama lengkap wajib diisi';
    if (empty($username)) $errors[] = 'Username wajib diisi';
    if (empty($email)) $errors[] = 'Email wajib diisi';
    if ($editId === 0 && strlen($password) < 6) $errors[] = 'Password minimal 6 karakter (untuk user baru)';
    if (empty($roles)) $errors[] = 'Pilih minimal satu role';

    // Cek duplikat
    if (empty($errors)) {
        $un_e = $conn->real_escape_string($username);
        $em_e = $conn->real_escape_string($email);
        $dup = $conn->query("SELECT id FROM users WHERE (username = '$un_e' OR email = '$em_e') " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            $errors[] = 'Username atau email sudah digunakan user lain';
        }
    }

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($fullName);
        $ph_e = $conn->real_escape_string($phone);

        if ($editId > 0) {
            $sql = "UPDATE users SET full_name = '$name_e', username = '$un_e', email = '$em_e', phone = '$ph_e', is_active = $isActive WHERE id = $editId";
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $sql = "UPDATE users SET full_name = '$name_e', username = '$un_e', email = '$em_e', phone = '$ph_e', is_active = $isActive, password = '$hash' WHERE id = $editId";
            }
            $conn->query($sql);
            $uid = $editId;
            $success = 'User berhasil diperbarui!';
            logActivity('update', 'users', "Mengubah user: $fullName (ID $uid)");
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $conn->query("INSERT INTO users (full_name, username, email, phone, password, role, is_active) VALUES ('$name_e', '$un_e', '$em_e', '$ph_e', '$hash', 'customer', $isActive)");
            $uid = (int)$conn->insert_id;
            $success = 'User berhasil dibuat!';
            logActivity('create', 'users', "Membuat user admin: $fullName (ID $uid)");
        }            // Assign roles
            if ($uid > 0) {
                $conn->query("DELETE FROM user_roles WHERE user_id = $uid");
                $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $rid) {
                    $ridInt = (int)$rid; // PHP 8.1+: bind_param wajib variabel, bukan expression
                    $stmt->bind_param('ii', $uid, $ridInt);
                    $stmt->execute();
                }
                // Assign cabang (kosong = semua)
                $conn->query("DELETE FROM user_branches WHERE user_id = $uid");
                $stmt2 = $conn->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                foreach ($branches as $bid) {
                    $bidInt = (int)$bid; // PHP 8.1+: bind_param wajib variabel, bukan expression
                    $stmt2->bind_param('ii', $uid, $bidInt);
                    $stmt2->execute();
                }
            }

        header('Location: users.php');
        exit;
    }
}

// ============================================
// ACTION: Reset password
// ============================================
if (isset($_GET['reset_pwd'])) {
    verifyCsrf();
    requirePermission('users', 'edit');
    $uid = (int)$_GET['reset_pwd'];
    $newPwd = substr(bin2hex(random_bytes(4)), 0, 8); // 8 karakter
    $hash = password_hash($newPwd, PASSWORD_BCRYPT);
    $conn->query("UPDATE users SET password = '$hash', failed_attempts = 0, locked_until = NULL WHERE id = $uid");
    $info = 'Password user #' . $uid . ' direset menjadi: <strong>' . $newPwd . '</strong> (salin sekarang!)';
    logActivity('settings', 'users', "Reset password user #$uid");
}

// ============================================
// ACTION: Lock / Unlock
// ============================================
if (isset($_GET['lock'])) {
    verifyCsrf();
    requirePermission('users', 'edit');
    $uid = (int)$_GET['lock'];
    if ($uid === getCurrentUserId()) {
        $info = 'Anda tidak dapat mengunci akun sendiri.';
    } else {
        $conn->query("UPDATE users SET is_locked = NOT is_locked WHERE id = $uid");
        logActivity('edit', 'users', "Toggle lock user #$uid");
    }
    header('Location: users.php');
    exit;
}

// ============================================
// ACTION: Suspend / Activate
// ============================================
if (isset($_GET['suspend'])) {
    verifyCsrf();
    requirePermission('users', 'edit');
    $uid = (int)$_GET['suspend'];
    if ($uid === getCurrentUserId()) {
        $info = 'Anda tidak dapat menonaktifkan akun sendiri.';
    } else {
        $conn->query("UPDATE users SET is_active = NOT is_active WHERE id = $uid");
        logActivity('edit', 'users', "Toggle status aktif user #$uid");
    }
    header('Location: users.php');
    exit;
}

// ============================================
// ACTION: Hapus user
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('users', 'delete');
    $uid = (int)$_GET['delete'];
    if ($uid === getCurrentUserId()) {
        $info = 'Anda tidak dapat menghapus akun sendiri.';
    } elseif (isSuperAdmin($uid) && !isSuperAdmin()) {
        $info = 'Hanya Super Admin yang dapat menghapus Super Admin lain.';
    } else {
        $r = $conn->query("SELECT full_name FROM users WHERE id = $uid LIMIT 1");
        $nm = $r ? $r->fetch_assoc()['full_name'] : "User #$uid";
        $conn->query("DELETE FROM users WHERE id = $uid");
        $success = "User \"$nm\" berhasil dihapus.";
        logActivity('delete', 'users', "Menghapus user: $nm");
    }
    header('Location: users.php');
    exit;
}

// ============================================
// DATA
// ============================================
$roles = $conn->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY id ASC");
$branches = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order ASC");
$allBranches = $conn->query("SELECT * FROM branches ORDER BY sort_order ASC");

$users = $conn->query("
    SELECT u.*,
           (SELECT GROUP_CONCAT(r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_names,
           (SELECT GROUP_CONCAT(r.slug SEPARATOR ',') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_slugs
    FROM users u
    WHERE (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = u.id) > 0 OR u.role = 'admin'
    ORDER BY u.id ASC
");

// Edit mode
$editUser = null;
$editUserRoles = [];
$editUserBranches = [];
if (isset($_GET['edit'])) {
    $uid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM users WHERE id = $uid LIMIT 1");
    if ($r && $r->num_rows > 0) $editUser = $r->fetch_assoc();
    if ($editUser) {
        $r = $conn->query("SELECT role_id FROM user_roles WHERE user_id = $uid");
        if ($r) while ($row = $r->fetch_assoc()) $editUserRoles[] = (int)$row['role_id'];
        $r = $conn->query("SELECT branch_id FROM user_branches WHERE user_id = $uid");
        if ($r) while ($row = $r->fetch_assoc()) $editUserBranches[] = (int)$row['branch_id'];
    }
}

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

<!-- ============ FORM USER ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><?= $editUser ? 'Edit User' : 'Tambah Admin Baru' ?></h3>
    <form method="POST">
        <input type="hidden" name="save_user" value="1">
        <input type="hidden" name="id" value="<?= $editUser['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Lengkap <span style="color: #EF4444;">*</span></label>
                <input type="text" name="full_name" class="form-input" required value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email <span style="color: #EF4444;">*</span></label>
                <input type="email" name="email" class="form-input" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input" required value="<?= htmlspecialchars($editUser['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">No. HP</label>
                <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Password <?= $editUser ? '<small style="color: var(--text-light);">(kosongkan jika tidak diganti)</small>' : '<span style="color: #EF4444;">*</span>' ?></label>
                <input type="password" name="password" class="form-input" <?= $editUser ? '' : 'required' ?> placeholder="Minimal 6 karakter">
            </div>
            <div class="form-group">
                <label class="form-label">Role <span style="color: #EF4444;">*</span></label>
                <select name="roles[]" class="form-select" multiple required size="4" style="min-height: 96px;">
                    <?php if ($roles): while ($role = $roles->fetch_assoc()): ?>
                    <option value="<?= $role['id'] ?>" <?= in_array((int)$role['id'], $editUserRoles, true) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role['name']) ?><?= $role['is_system'] ? ' (Sistem)' : '' ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
                <small style="color: var(--text-light);">Tekan Ctrl/Cmd untuk pilih banyak</small>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Akses Cabang <small style="color: var(--text-light);">(kosongkan = SEMUA cabang)</small></label>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; padding: 12px; border: 1.5px dashed #e5e0db; border-radius: 10px;">
                <?php if ($allBranches && $allBranches->num_rows > 0): while ($b = $allBranches->fetch_assoc()): ?>
                <label class="perm-checkbox" style="<?= in_array((int)$b['id'], $editUserBranches, true) ? 'border-color:#D4A853; background:rgba(212,168,83,0.08);' : '' ?>">
                    <input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $editUserBranches, true) ? 'checked' : '' ?> onchange="this.closest('.perm-checkbox').style.borderColor = this.checked ? '#D4A853' : '#e5e0db';">
                    <i class="fas fa-store" style="color: var(--soft-gold);"></i> <?= htmlspecialchars($b['name']) ?>
                </label>
                <?php endwhile; else: ?>
                <span style="color: var(--text-muted); font-size: 13px;">Belum ada cabang. Tambahkan cabang terlebih dahulu.</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);" <?= (!isset($editUser) || $editUser['is_active']) ? 'checked' : '' ?>>
                <span>Akun aktif</span>
            </label>
        </div>
        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editUser ? 'Simpan Perubahan' : 'Buat User' ?>
            </button>
            <?php if ($editUser): ?>
            <a href="users.php" class="btn btn-outline">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============ DAFTAR USER ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Admin (<?= $users ? $users->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Nama</th>
                    <th>Kontak</th>
                    <th>Role</th>
                    <th>Akses Cabang</th>
                    <th>Status</th>
                    <th>Terakhir Login</th>
                    <th style="width: 250px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): while ($u = $users->fetch_assoc()):
                    $roleSlugs = explode(',', (string)$u['role_slugs']);
                    $isSuper = in_array('super-admin', $roleSlugs, true) || $u['role'] === 'admin';
                ?>
                <tr>
                    <td>#<?= $u['id'] ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="admin-avatar" style="width: 32px; height: 32px; font-size: 13px;">
                                <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                                <?php if ($isSuper): ?><span class="status-badge diamond" style="margin-left: 4px; font-size: 10px;">SUPER</span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="font-size: 12px;">
                        <?= htmlspecialchars($u['email']) ?><br>
                        <small style="color: var(--text-light);">@<?= htmlspecialchars($u['username']) ?></small>
                    </td>
                    <td style="max-width: 180px;">
                        <?php
                        $roleNames = array_filter(array_map('trim', explode(',', (string)$u['role_names'])));
                        foreach (array_slice($roleNames, 0, 2) as $rn): ?>
                            <span class="status-badge gold" style="margin-bottom: 3px;"><?= htmlspecialchars($rn) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($roleNames) > 2): ?><span class="status-badge silver">+<?= count($roleNames) - 2 ?></span><?php endif; ?>
                    </td>
                    <td style="font-size: 12px;">
                        <?php
                        $bIds = [];
                        $r = $conn->query("SELECT branch_id FROM user_branches WHERE user_id = " . (int)$u['id']);
                        if ($r) while ($row = $r->fetch_assoc()) $bIds[] = (int)$row['branch_id'];
                        if (empty($bIds)): ?>
                            <span class="status-badge active">Semua</span>
                        <?php else: ?>
                            <span class="status-badge processing"><?= count($bIds) ?> cabang</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$u['is_active']): ?>
                            <span class="status-badge cancelled">Suspend</span>
                        <?php elseif ($u['is_locked']): ?>
                            <span class="status-badge rejected">Terkunci</span>
                        <?php elseif (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                            <span class="status-badge pending">Lock Sementara</span>
                        <?php else: ?>
                            <span class="status-badge active">Aktif</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px;"><?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : '-' ?></td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="users.php?reset_pwd=<?= $u['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Reset Password"
                               onclick="return confirm('Reset password user <?= htmlspecialchars($u['full_name']) ?>? Password baru akan ditampilkan.')">
                                <i class="fas fa-key"></i>
                            </a>
                            <?php if ($u['id'] !== getCurrentUserId()): ?>
                            <a href="users.php?lock=<?= $u['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $u['is_locked'] ? 'Buka Kunci' : 'Kunci' ?>">
                                <i class="fas <?= $u['is_locked'] ? 'fa-unlock' : 'fa-lock' ?>"></i>
                            </a>
                            <a href="users.php?suspend=<?= $u['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $u['is_active'] ? 'Suspend' : 'Aktifkan' ?>">
                                <i class="fas <?= $u['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                            </a>
                            <a href="users.php?delete=<?= $u['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus"
                               onclick="return confirm('Hapus user <?= htmlspecialchars($u['full_name']) ?>?')"
                               style="color: #EF4444; border-color: #EF4444;">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada admin</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
