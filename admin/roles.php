<?php
$currentPage = 'roles';
$pageTitle = 'Manajemen Role';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('roles', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan role baru / edit role
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('roles', $editId > 0 ? 'edit' : 'create');

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if (empty($name)) {
        $errors[] = 'Nama role wajib diisi';
    } else {
        if ($slug === '') $slug = generateSlug($name);
        $name_e = $conn->real_escape_string($name);
        $slug_e = $conn->real_escape_string($slug);
        $desc_e = $conn->real_escape_string($desc);

        // Cek duplikat slug
        $dup = $conn->query("SELECT id FROM roles WHERE slug = '$slug_e' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            $errors[] = 'Slug sudah digunakan role lain';
        } else {
            if ($editId > 0) {
                $conn->query("UPDATE roles SET name = '$name_e', slug = '$slug_e', description = '$desc_e' WHERE id = $editId");
                $success = 'Role berhasil diperbarui!';
                logActivity('update', 'roles', "Mengubah role: $name");
            } else {
                $conn->query("INSERT INTO roles (name, slug, description, is_system) VALUES ('$name_e', '$slug_e', '$desc_e', 0)");
                $newId = (int)$conn->insert_id;

                // ================================================
                // DEFAULT: beri permission dasar + widget otomatis
                // (sama dengan $alwaysGrant & widget wajib di seeder)
                // ================================================
                $alwaysGrant = [
                    'dashboard' => ['view'],
                    'profile' => ['view', 'edit'],
                    'notifications' => ['view', 'delete'],
                    'changelog' => ['view'],
                ];
                $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($alwaysGrant as $module => $actions) {
                    foreach ($actions as $action) {
                        $perm = $conn->query("SELECT id FROM permissions WHERE module = '$module' AND action = '$action' LIMIT 1");
                        if ($perm && $perm->num_rows > 0) {
                            $pid = (int)$perm->fetch_assoc()['id'];
                            $stmt->bind_param('ii', $newId, $pid);
                            $stmt->execute();
                        }
                    }
                }

                // Widget default: dashboard, profil, notifikasi
                $defaultWidgets = ['dashboard_summary', 'profile_summary', 'notifications_list'];
                $sort = 1;
                $stmtW = $conn->prepare("INSERT INTO role_widgets (role_id, widget_id, sort_order) VALUES (?, ?, ?)");
                foreach ($defaultWidgets as $ws) {
                    $wid = $conn->query("SELECT id FROM widgets WHERE slug = '$ws' LIMIT 1");
                    if ($wid && $wid->num_rows > 0) {
                        $widId = (int)$wid->fetch_assoc()['id'];
                        $stmtW->bind_param('iii', $newId, $widId, $sort);
                        $stmtW->execute();
                        $sort++;
                    }
                }

                $success = 'Role berhasil dibuat! Permission dasar & widget default (dashboard, profil, notifikasi) diberikan otomatis.';
                logActivity('create', 'roles', "Membuat role baru: $name");
                header('Location: roles.php?edit_perms=' . $newId);
                exit;
            }
        }
    }
}

// ============================================
// ACTION: Simpan permission role
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_perms'])) {
    verifyCsrf();
    requirePermission('roles', 'edit');
    $roleId = (int)($_POST['role_id'] ?? 0);

    $checkRole = $conn->query("SELECT * FROM roles WHERE id = $roleId LIMIT 1");
    if ($checkRole && $checkRole->num_rows > 0) {
        $role = $checkRole->fetch_assoc();
        $perms = $_POST['perms'] ?? [];
        $permIds = array_map('intval', $perms);

        $conn->query("DELETE FROM role_permissions WHERE role_id = $roleId");
        $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($permIds as $pid) {
            $stmt->bind_param('ii', $roleId, $pid);
            $stmt->execute();
        }
        $success = 'Permission role "' . htmlspecialchars($role['name']) . '" berhasil disimpan! (' . count($permIds) . ' permission)';
        logActivity('edit', 'roles', "Memperbarui permission role: {$role['name']} ({$role['slug']})");
    } else {
        $errors[] = 'Role tidak ditemukan';
    }
}

// ============================================
// ACTION: Toggle aktif/nonaktif
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('roles', 'edit');
    $roleId = (int)$_GET['toggle'];
    $r = $conn->query("SELECT slug, is_active FROM roles WHERE id = $roleId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        if ($row['slug'] === 'super-admin') {
            $info = 'Role Super Admin tidak dapat dinonaktifkan.';
        } else {
            $conn->query("UPDATE roles SET is_active = NOT is_active WHERE id = $roleId");
            logActivity('edit', 'roles', "Toggle status role #$roleId");
        }
    }
    header('Location: roles.php');
    exit;
}

// ============================================
// ACTION: Hapus role
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('roles', 'delete');
    $roleId = (int)$_GET['delete'];
    $r = $conn->query("SELECT slug, is_system, name FROM roles WHERE id = $roleId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        if ((int)$row['is_system'] || $row['slug'] === 'super-admin') {
            $info = 'Role sistem tidak dapat dihapus.';
        } else {
            $memberCount = $conn->query("SELECT COUNT(*) c FROM user_roles WHERE role_id = $roleId")->fetch_assoc()['c'];
            if ($memberCount > 0) {
                $info = "Role masih dipakai $memberCount user. Pindahkan user tersebut ke role lain terlebih dahulu.";
            } else {
                $conn->query("DELETE FROM roles WHERE id = $roleId");
                $success = 'Role "' . htmlspecialchars($row['name']) . '" berhasil dihapus.';
                logActivity('delete', 'roles', "Menghapus role: {$row['name']}");
            }
        }
    }
    header('Location: roles.php');
    exit;
}

// ============================================
// DATA
// ============================================
$roles = $conn->query("
    SELECT r.*,
           (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count,
           (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
    FROM roles r ORDER BY r.id ASC
");

// Mode edit permission
$editRole = null;
if (isset($_GET['edit_perms'])) {
    $rid = (int)$_GET['edit_perms'];
    $r = $conn->query("SELECT * FROM roles WHERE id = $rid LIMIT 1");
    if ($r && $r->num_rows > 0) $editRole = $r->fetch_assoc();
}

$allPermissions = $conn->query("SELECT * FROM permissions WHERE is_active = 1 ORDER BY module, FIELD(action,'view','create','edit','delete','approve','publish','export','import','verify','backup','restore','settings')");
$permissionGroups = [];
if ($allPermissions) {
    while ($p = $allPermissions->fetch_assoc()) {
        $permissionGroups[$p['module']][] = $p;
    }
}

$moduleNames = [
    'dashboard' => 'Dashboard', 'products' => 'Produk', 'categories' => 'Kategori',
    'orders' => 'Pesanan', 'customers' => 'Pelanggan', 'promo' => 'Promo',
    'testimonials' => 'Testimoni', 'articles' => 'Artikel', 'branches' => 'Cabang',
    'faq' => 'FAQ', 'videos' => 'Video Gallery', 'messages' => 'Pesan Masuk',
    'hero_slides' => 'Hero Slider', 'notifications' => 'Notifikasi', 'changelog' => 'Changelog',
    'profile' => 'Profil', 'roles' => 'Role Management', 'users' => 'User Management',
    'activity_logs' => 'Audit Log', 'login_history' => 'Riwayat Login', 'sessions' => 'Sesi Aktif',
    'backup' => 'Backup & Restore', 'api' => 'API Integrasi', 'settings' => 'Pengaturan',
    'stock' => 'Inventory / Gudang', 'payments' => 'Pembayaran', 'shipping' => 'Pengiriman',
    'membership' => 'Membership', 'marketing' => 'Marketing', 'affiliate' => 'Affiliate',
    'reports' => 'Laporan', 'support' => 'Ticket Support', 'invoices' => 'Invoice',
    'security' => 'Keamanan', 'menus' => 'Menu Management', 'widgets' => 'Widget Dashboard',
];

$currentPerms = [];
if ($editRole) {
    $r = $conn->query("SELECT permission_id FROM role_permissions WHERE role_id = {$editRole['id']}");
    if ($r) while ($row = $r->fetch_assoc()) $currentPerms[] = (int)$row['permission_id'];
}

// Role yang sedang diedit (form)
$editRoleForm = null;
if (isset($_GET['edit'])) {
    $rid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM roles WHERE id = $rid LIMIT 1");
    if ($r && $r->num_rows > 0) $editRoleForm = $r->fetch_assoc();
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

<!-- ============ FORM ROLE ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><?= $editRoleForm ? 'Edit Role' : 'Buat Role Baru' ?></h3>
    <form method="POST" style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="save_role" value="1">
        <input type="hidden" name="id" value="<?= $editRoleForm['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
            <label class="form-label">Nama Role <span style="color: #EF4444;">*</span></label>
            <input type="text" name="name" class="form-input" placeholder="Contoh: Admin Event" required value="<?= htmlspecialchars($editRoleForm['name'] ?? '') ?>">
        </div>
        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
            <label class="form-label">Slug</label>
            <input type="text" name="slug" class="form-input" placeholder="admin-event (kosong = otomatis)" value="<?= htmlspecialchars($editRoleForm['slug'] ?? '') ?>">
        </div>
        <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom: 0;">
            <label class="form-label">Deskripsi</label>
            <input type="text" name="description" class="form-input" placeholder="Jelaskan tugas role ini" value="<?= htmlspecialchars($editRoleForm['description'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="margin-bottom: 1px;">
            <i class="fas fa-save"></i> <?= $editRoleForm ? 'Simpan' : 'Buat Role' ?>
        </button>
        <?php if ($editRoleForm): ?>
            <a href="roles.php" class="btn btn-outline" style="margin-bottom: 1px;">Batal</a>
        <?php endif; ?>
    </form>
</div>

<!-- ============ DAFTAR ROLE ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Role (<?= $roles ? $roles->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Role</th>
                    <th>Deskripsi</th>
                    <th>Permission</th>
                    <th>User</th>
                    <th>Tipe</th>
                    <th>Status</th>
                    <th style="width: 210px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($roles && $roles->num_rows > 0): while ($role = $roles->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $role['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($role['name']) ?></strong>
                        <br><small style="color: var(--text-light); font-size: 11px;"><?= htmlspecialchars($role['slug']) ?></small>
                    </td>
                    <td style="max-width: 260px; font-size: 12px; color: var(--text-muted);">
                        <?= htmlspecialchars($role['description'] ?: '-') ?>
                    </td>
                    <td><span class="status-badge active"><?= (int)$role['perm_count'] ?> perms</span></td>
                    <td><span class="status-badge <?= $role['user_count'] > 0 ? 'processing' : 'inactive' ?>"><?= (int)$role['user_count'] ?> user</span></td>
                    <td>
                        <?php if ($role['is_system']): ?>
                            <span class="status-badge diamond">Sistem</span>
                        <?php else: ?>
                            <span class="status-badge silver">Dinamis</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?= $role['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $role['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="roles.php?edit_perms=<?= $role['id'] ?>" class="btn btn-primary btn-sm" title="Atur Permission">
                                <i class="fas fa-shield-alt"></i> Permission
                            </a>
                            <a href="roles.php?edit=<?= $role['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if (!$role['is_system']): ?>
                            <a href="roles.php?toggle=<?= $role['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $role['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                <i class="fas <?= $role['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                            </a>
                            <a href="roles.php?delete=<?= $role['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus"
                               onclick="return confirm('Hapus role <?= htmlspecialchars($role['name']) ?>?')"
                               style="color: #EF4444; border-color: #EF4444;">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada role</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($editRole): ?>
<!-- ============ PERMISSION MATRIX ============ -->
<div class="admin-card" id="permEditor">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <h3 class="admin-card-title" style="margin-bottom: 0;">
            <i class="fas fa-shield-alt" style="color: var(--soft-gold);"></i>
            Permission: <?= htmlspecialchars($editRole['name']) ?>
        </h3>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAllPerms(true)">Pilih Semua</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="selectAllPerms(false)">Kosongkan</button>
            <span style="font-size: 12px; color: var(--text-muted);" id="permCountLabel">0 permission dipilih</span>
        </div>
    </div>

    <form method="POST" id="permForm">
        <input type="hidden" name="save_perms" value="1">
        <input type="hidden" name="role_id" value="<?= $editRole['id'] ?>">
        <?= csrfField() ?>
        <?php foreach ($permissionGroups as $module => $perms): ?>
        <div class="perm-group">
            <div class="perm-group-title">
                <i class="fas fa-folder" style="color: var(--soft-gold);"></i>
                <?= htmlspecialchars($moduleNames[$module] ?? ucfirst($module)) ?>
                <span style="font-size: 11px; color: var(--text-light); font-weight: 400;">(<?= $module ?>)</span>
            </div>
            <div class="perm-checkboxes">
                <?php foreach ($perms as $p): ?>
                <label class="perm-checkbox <?= in_array((int)$p['id'], $currentPerms, true) ? 'checked' : '' ?>">
                    <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                           onchange="this.closest('.perm-checkbox').classList.toggle('checked', this.checked); updatePermCount();"
                           <?= in_array((int)$p['id'], $currentPerms, true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($p['action']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="display: flex; gap: 12px; margin-top: 20px;">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Simpan Permission
            </button>
            <a href="roles.php" class="btn btn-outline btn-lg">Selesai</a>
        </div>
    </form>
</div>

<script>
function selectAllPerms(select) {
    document.querySelectorAll('#permForm input[name="perms[]"]').forEach(function (cb) {
        cb.checked = select;
        cb.closest('.perm-checkbox').classList.toggle('checked', select);
    });
    updatePermCount();
}
function updatePermCount() {
    var n = document.querySelectorAll('#permForm input[name="perms[]"]:checked').length;
    document.getElementById('permCountLabel').textContent = n + ' permission dipilih';
}
updatePermCount();
</script>
<?php endif; ?>
        </main>
    </div>
</body>
</html>
