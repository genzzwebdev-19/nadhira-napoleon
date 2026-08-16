<?php
$currentPage = 'widgets';
$pageTitle = 'Widget Dashboard';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('widgets', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan widget (create / edit)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_widget'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('widgets', $editId > 0 ? 'edit' : 'create');

    $slug = trim($_POST['slug'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $icon = trim($_POST['icon'] ?? 'fa-chart-bar');
    $size = in_array($_POST['size'] ?? '', ['small', 'medium', 'large', 'full'], true) ? $_POST['size'] : 'medium';
    $description = trim($_POST['description'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($title)) $errors[] = 'Judul widget wajib diisi';
    if (empty($slug)) $errors[] = 'Slug widget wajib diisi';
    if ($slug === '') $slug = generateSlug($title);

    if (empty($errors)) {
        $slug_e = $conn->real_escape_string($slug);
        $title_e = $conn->real_escape_string($title);
        $icon_e = $conn->real_escape_string($icon);
        $desc_e = $conn->real_escape_string($description);

        $dup = $conn->query("SELECT id FROM widgets WHERE slug = '$slug_e' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            $errors[] = 'Slug sudah digunakan widget lain';
        } else {
            if ($editId > 0) {
                $conn->query("UPDATE widgets SET slug = '$slug_e', title = '$title_e', icon = '$icon_e', size = '$size', description = '$desc_e', sort_order = $sortOrder, is_active = $isActive WHERE id = $editId");
                $success = 'Widget berhasil diperbarui!';
                logActivity('update', 'widgets', "Mengubah widget: $title");
            } else {
                $conn->query("INSERT INTO widgets (slug, title, icon, size, description, sort_order, is_active) VALUES ('$slug_e', '$title_e', '$icon_e', '$size', '$desc_e', $sortOrder, $isActive)");
                $success = 'Widget berhasil ditambahkan!';
                logActivity('create', 'widgets', "Menambahkan widget: $title");
            }
            header('Location: widgets.php');
            exit;
        }
    }
}

// ============================================
// ACTION: Toggle aktif/nonaktif
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('widgets', 'edit');
    $wid = (int)$_GET['toggle'];
    $conn->query("UPDATE widgets SET is_active = NOT is_active WHERE id = $wid");
    logActivity('edit', 'widgets', "Toggle status widget #$wid");
    header('Location: widgets.php');
    exit;
}

// ============================================
// ACTION: Hapus widget
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('widgets', 'delete');
    $wid = (int)$_GET['delete'];
    $r = $conn->query("SELECT title FROM widgets WHERE id = $wid LIMIT 1");
    $nm = $r && $r->num_rows > 0 ? $r->fetch_assoc()['title'] : "Widget #$wid";
    $conn->query("DELETE FROM widgets WHERE id = $wid");
    $conn->query("DELETE FROM role_widgets WHERE widget_id = $wid");
    $success = "Widget \"" . htmlspecialchars($nm) . "\" berhasil dihapus.";
    logActivity('delete', 'widgets', "Menghapus widget: $nm");
    header('Location: widgets.php');
    exit;
}

// ============================================
// ACTION: Naik/turun urutan
// ============================================
if (isset($_GET['move'])) {
    verifyCsrf();
    requirePermission('widgets', 'edit');
    $wid = (int)$_GET['move'];
    $dir = $_GET['dir'] === 'up' ? 'up' : 'down';

    $r = $conn->query("SELECT sort_order FROM widgets WHERE id = $wid LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $curSort = (int)$r->fetch_assoc()['sort_order'];
        if ($dir === 'up') {
            $neighbor = $conn->query("SELECT id, sort_order FROM widgets WHERE (sort_order < $curSort OR (sort_order = $curSort AND id < $wid)) AND id != $wid ORDER BY sort_order DESC, id DESC LIMIT 1");
        } else {
            $neighbor = $conn->query("SELECT id, sort_order FROM widgets WHERE (sort_order > $curSort OR (sort_order = $curSort AND id > $wid)) AND id != $wid ORDER BY sort_order ASC, id ASC LIMIT 1");
        }
        if ($neighbor && $neighbor->num_rows > 0) {
            $nRow = $neighbor->fetch_assoc();
            $conn->query("UPDATE widgets SET sort_order = {$nRow['sort_order']} WHERE id = $wid");
            $conn->query("UPDATE widgets SET sort_order = $curSort WHERE id = {$nRow['id']}");
            logActivity('edit', 'widgets', "Mengubah urutan widget #$wid");
        }
    }
    header('Location: widgets.php');
    exit;
}

// ============================================
// ACTION: Simpan assignment widget -> role
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_widgets'])) {
    verifyCsrf();
    requirePermission('widgets', 'edit');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $widgetIds = array_map('intval', $_POST['widget_ids'] ?? []);

    $rRole = $conn->query("SELECT name FROM roles WHERE id = $roleId LIMIT 1");
    $roleName = $rRole && $rRole->num_rows > 0 ? $rRole->fetch_assoc()['name'] : "Role #$roleId";

    $conn->query("DELETE FROM role_widgets WHERE role_id = $roleId");
    $sort = 1;
    $stmt = $conn->prepare("INSERT INTO role_widgets (role_id, widget_id, sort_order) VALUES (?, ?, ?)");
    foreach ($widgetIds as $wid) {
        $stmt->bind_param('iii', $roleId, $wid, $sort);
        $stmt->execute();
        $sort++;
    }
    $success = 'Widget untuk role "' . htmlspecialchars($roleName) . '" berhasil diperbarui! (' . count($widgetIds) . ' widget)';
    logActivity('edit', 'widgets', "Memperbarui widget dashboard role: $roleName");
    header('Location: widgets.php');
    exit;
}

// ============================================
// DATA
// ============================================
$widgets = $conn->query("SELECT * FROM widgets ORDER BY sort_order ASC, id ASC");
$roles = $conn->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY id ASC");

// Widget aktif per role (untuk render checkbox)
$roleWidgetMap = [];
if ($roles && $widgets) {
    $r = $conn->query("SELECT role_id, widget_id FROM role_widgets");
    if ($r) while ($row = $r->fetch_assoc()) $roleWidgetMap[(int)$row['role_id']][] = (int)$row['widget_id'];
}

$sizeNames = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'full' => 'Full Width'];

// Edit mode
$editWidget = null;
if (isset($_GET['edit'])) {
    $wid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM widgets WHERE id = $wid LIMIT 1");
    if ($r && $r->num_rows > 0) $editWidget = $r->fetch_assoc();
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

<!-- ============ FORM WIDGET ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><?= $editWidget ? 'Edit Widget' : 'Tambah Widget Baru' ?></h3>
    <form method="POST">
        <input type="hidden" name="save_widget" value="1">
        <input type="hidden" name="id" value="<?= $editWidget['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Judul Widget <span style="color: #EF4444;">*</span></label>
                <input type="text" name="title" class="form-input" placeholder="Contoh: Stok Menipis" required value="<?= htmlspecialchars($editWidget['title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Slug <span style="color: #EF4444;">*</span></label>
                <input type="text" name="slug" class="form-input" placeholder="low_stock (dipakai kode render widget)" required value="<?= htmlspecialchars($editWidget['slug'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Icon (Font Awesome)</label>
                <input type="text" name="icon" class="form-input" placeholder="fa-exclamation-triangle" value="<?= htmlspecialchars($editWidget['icon'] ?? 'fa-chart-bar') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Ukuran</label>
                <select name="size" class="form-select">
                    <?php foreach ($sizeNames as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= (($editWidget['size'] ?? 'medium') === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-textarea" placeholder="Penjelasan singkat widget ini"><?= htmlspecialchars($editWidget['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Urutan</label>
                <input type="number" name="sort_order" class="form-input" value="<?= (int)($editWidget['sort_order'] ?? 0) ?>" min="0">
                <div class="form-group" style="margin-top: 16px;">
                    <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);" <?= (!isset($editWidget) || $editWidget['is_active']) ? 'checked' : '' ?>>
                        <span>Widget aktif</span>
                    </label>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editWidget ? 'Simpan Perubahan' : 'Tambah Widget' ?>
            </button>
            <?php if ($editWidget): ?>
            <a href="widgets.php" class="btn btn-outline">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============ DAFTAR WIDGET ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Widget (<?= $widgets ? $widgets->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Widget</th>
                    <th>Slug</th>
                    <th>Ukuran</th>
                    <th>Status</th>
                    <th style="width: 200px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($widgets && $widgets->num_rows > 0): while ($w = $widgets->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $w['id'] ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="width: 34px; height: 34px; border-radius: 10px; background: rgba(212,168,83,0.12); color: #D4A853; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas <?= htmlspecialchars($w['icon']) ?>"></i>
                            </span>
                            <div>
                                <strong><?= htmlspecialchars($w['title']) ?></strong>
                                <br><small style="color: var(--text-light); font-size: 11px;"><?= htmlspecialchars(mb_substr($w['description'] ?: '-', 0, 60)) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><code style="font-size: 12px;"><?= htmlspecialchars($w['slug']) ?></code></td>
                    <td><span class="status-badge silver"><?= $sizeNames[$w['size']] ?? $w['size'] ?></span></td>
                    <td>
                        <span class="status-badge <?= $w['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $w['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <a href="widgets.php?move=<?= $w['id'] ?>&dir=up&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm sort-arrow" title="Naik"><i class="fas fa-chevron-up"></i></a>
                            <a href="widgets.php?move=<?= $w['id'] ?>&dir=down&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm sort-arrow" title="Turun"><i class="fas fa-chevron-down"></i></a>
                            <a href="widgets.php?edit=<?= $w['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="widgets.php?toggle=<?= $w['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $w['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                <i class="fas <?= $w['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                            </a>
                            <a href="widgets.php?delete=<?= $w['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus"
                               onclick="return confirm('Hapus widget <?= htmlspecialchars($w['title']) ?>?')"
                               style="color: #EF4444; border-color: #EF4444;">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada widget.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ WIDGET PER ROLE ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Widget per Role</h3>
    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
        Atur widget dashboard yang tampil untuk setiap role. Super Admin selalu melihat semua widget.
        Pilih role untuk mengatur widget-nya:
    </p>

    <?php
    $roles->data_seek(0);
    while ($role = $roles->fetch_assoc()):
        $isSuperRole = $role['slug'] === 'super-admin';
        $checked = $roleWidgetMap[(int)$role['id']] ?? [];
    ?>
    <div class="perm-group" style="padding: 18px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div class="perm-group-title" style="margin-bottom: 0;">
                <i class="fas fa-user-shield" style="color: var(--soft-gold);"></i>
                <?= htmlspecialchars($role['name']) ?>
                <?php if ($isSuperRole): ?><span class="status-badge diamond" style="font-size: 10px;">AKSES PENUH</span><?php endif; ?>
            </div>
            <?php if (!$isSuperRole): ?>
            <form method="POST" onsubmit="return confirm('Simpan pengaturan widget untuk role <?= htmlspecialchars($role['name'], ENT_QUOTES) ?>?')">
                <input type="hidden" name="save_role_widgets" value="1">
                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                <?= csrfField() ?>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="toggleAllRole(<?= (int)$role['id'] ?>, true)">Pilih Semua</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="toggleAllRole(<?= (int)$role['id'] ?>, false)">Kosongkan</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <div class="perm-checkboxes" style="gap: 8px 14px;">
            <?php
            $widgets->data_seek(0);
            while ($w = $widgets->fetch_assoc()):
                $cbName = 'widget_ids[]';
                $cbId = 'rw_' . $role['id'] . '_' . $w['id'];
            ?>
            <label class="perm-checkbox <?= in_array((int)$w['id'], $checked, true) ? 'checked' : '' ?>" for="<?= $cbId ?>" style="cursor: pointer;">
                <input type="checkbox" id="<?= $cbId ?>" name="<?= $cbName ?>" value="<?= (int)$w['id'] ?>"
                       <?= $isSuperRole ? 'checked disabled' : (in_array((int)$w['id'], $checked, true) ? 'checked' : '') ?>
                       onchange="this.closest('.perm-checkbox').classList.toggle('checked', this.checked);">
                <i class="fas <?= htmlspecialchars($w['icon']) ?>"></i>
                <?= htmlspecialchars($w['title']) ?>
            </label>
            <?php endwhile; ?>
            <?php if ($isSuperRole): ?>
            <div style="width: 100%; margin-top: 4px; font-size: 12px; color: var(--text-light);">
                <i class="fas fa-info-circle"></i> Role Super Admin tidak dapat diubah — selalu mendapat semua widget.
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; ?>
</div>

<script>
function toggleAllRole(roleId, checked) {
    document.querySelectorAll('input[name="widget_ids[]"][id^="rw_' + roleId + '_"]').forEach(function (cb) {
        if (!cb.disabled) {
            cb.checked = checked;
            cb.closest('.perm-checkbox').classList.toggle('checked', checked);
        }
    });
}
</script>
        </main>
    </div>
</body>
</html>
