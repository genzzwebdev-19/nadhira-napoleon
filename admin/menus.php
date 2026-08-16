<?php
$currentPage = 'menus';
$pageTitle = 'Menu Management';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('menus', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Simpan menu (create / edit)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_menu'])) {
    verifyCsrf();
    $editId = (int)($_POST['id'] ?? 0);
    requirePermission('menus', $editId > 0 ? 'edit' : 'create');

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? 'fa-circle');
    $module = trim($_POST['module'] ?? '');
    $section = trim($_POST['section'] ?? 'Menu Utama');
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name)) $errors[] = 'Nama menu wajib diisi';
    if (empty($url)) $errors[] = 'URL menu wajib diisi';
    if ($slug === '') $slug = generateSlug($name);

    if (empty($errors)) {
        $name_e = $conn->real_escape_string($name);
        $slug_e = $conn->real_escape_string($slug);
        $url_e = $conn->real_escape_string($url);
        $icon_e = $conn->real_escape_string($icon);
        $module_e = $conn->real_escape_string($module);
        $section_e = $conn->real_escape_string($section);
        $parent = $parentId > 0 ? $parentId : 'NULL';

        // Cegah menjadikan dirinya sendiri sebagai parent
        if ($parentId > 0 && $parentId === $editId) {
            $errors[] = 'Menu tidak dapat menjadi parent dari dirinya sendiri';
        } else {
            // Cegah siklus parent (A anak B, lalu B jadi anak A)
            $cycle = false;
            if ($parentId > 0 && $editId > 0) {
                $cur = $parentId;
                $guard = 0;
                while ($cur > 0 && $guard < 50) {
                    if ($cur === $editId) { $cycle = true; break; }
                    $pr = $conn->query("SELECT parent_id FROM menus WHERE id = $cur LIMIT 1");
                    if (!$pr || $pr->num_rows === 0) break;
                    $cur = (int)$pr->fetch_assoc()['parent_id'];
                    $guard++;
                }
            }
            if ($cycle) {
                $errors[] = 'Tidak dapat menjadikan submenu sebagai parent (menyebabkan siklus)';
            } else {
                $dup = $conn->query("SELECT id FROM menus WHERE slug = '$slug_e' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
                if ($dup && $dup->num_rows > 0) {
                    $errors[] = 'Slug sudah digunakan menu lain';
                } else {
                    if ($editId > 0) {
                        $conn->query("UPDATE menus SET name = '$name_e', slug = '$slug_e', url = '$url_e', icon = '$icon_e', module = '$module_e', section = '$section_e', parent_id = $parent, sort_order = $sortOrder, is_active = $isActive WHERE id = $editId");
                        $success = 'Menu berhasil diperbarui!';
                        logActivity('update', 'menus', "Mengubah menu: $name");
                    } else {
                        $conn->query("INSERT INTO menus (name, slug, url, icon, module, section, parent_id, sort_order, is_active) VALUES ('$name_e', '$slug_e', '$url_e', '$icon_e', '$module_e', '$section_e', $parent, $sortOrder, $isActive)");
                        $success = 'Menu berhasil ditambahkan!';
                        logActivity('create', 'menus', "Menambahkan menu: $name");
                    }
                    header('Location: menus.php');
                    exit;
                }
            }
        }
    }
}

// ============================================
// ACTION: Toggle aktif/nonaktif
// ============================================
if (isset($_GET['toggle'])) {
    verifyCsrf();
    requirePermission('menus', 'edit');
    $mid = (int)$_GET['toggle'];
    $conn->query("UPDATE menus SET is_active = NOT is_active WHERE id = $mid");
    logActivity('edit', 'menus', "Toggle status menu #$mid");
    header('Location: menus.php');
    exit;
}

// ============================================
// ACTION: Hapus menu
// ============================================
if (isset($_GET['delete'])) {
    verifyCsrf();
    requirePermission('menus', 'delete');
    $mid = (int)$_GET['delete'];
    $r = $conn->query("SELECT name FROM menus WHERE id = $mid LIMIT 1");
    $nm = $r && $r->num_rows > 0 ? $r->fetch_assoc()['name'] : "Menu #$mid";

    // Cek apakah punya submenu
    $childCount = $conn->query("SELECT COUNT(*) c FROM menus WHERE parent_id = $mid")->fetch_assoc()['c'];
    if ($childCount > 0) {
        $info = "Menu \"" . htmlspecialchars($nm) . "\" masih memiliki $childCount submenu. Hapus atau pindahkan submenu terlebih dahulu.";
    } else {
        $conn->query("DELETE FROM menus WHERE id = $mid");
        $success = "Menu \"" . htmlspecialchars($nm) . "\" berhasil dihapus.";
        logActivity('delete', 'menus', "Menghapus menu: $nm");
    }
    header('Location: menus.php');
    exit;
}

// ============================================
// ACTION: Naik/turun urutan (dalam satu section)
// ============================================
if (isset($_GET['move'])) {
    verifyCsrf();
    requirePermission('menus', 'edit');
    $mid = (int)$_GET['move'];
    $dir = $_GET['dir'] === 'up' ? 'up' : 'down';

    $r = $conn->query("SELECT section, sort_order FROM menus WHERE id = $mid LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row = $r->fetch_assoc();
        $sec_e = $conn->real_escape_string($row['section']);
        $curSort = (int)$row['sort_order'];

        if ($dir === 'up') {
            $neighbor = $conn->query("SELECT id, sort_order FROM menus WHERE section = '$sec_e' AND (sort_order < $curSort OR (sort_order = $curSort AND id < $mid)) AND id != $mid ORDER BY sort_order DESC, id DESC LIMIT 1");
        } else {
            $neighbor = $conn->query("SELECT id, sort_order FROM menus WHERE section = '$sec_e' AND (sort_order > $curSort OR (sort_order = $curSort AND id > $mid)) AND id != $mid ORDER BY sort_order ASC, id ASC LIMIT 1");
        }
        if ($neighbor && $neighbor->num_rows > 0) {
            $nRow = $neighbor->fetch_assoc();
            $conn->query("UPDATE menus SET sort_order = {$nRow['sort_order']} WHERE id = $mid");
            $conn->query("UPDATE menus SET sort_order = $curSort WHERE id = {$nRow['id']}");
            logActivity('edit', 'menus', "Mengubah urutan menu #$mid");
        }
    }
    header('Location: menus.php');
    exit;
}

// ============================================
// DATA
// ============================================
$menus = $conn->query("SELECT m.*, p.name AS parent_name FROM menus m LEFT JOIN menus p ON p.id = m.parent_id ORDER BY m.section, m.sort_order ASC, m.id ASC");

// Semua section yang ada (untuk dropdown)
$sections = [];
$rSections = $conn->query("SELECT DISTINCT section FROM menus ORDER BY section");
if ($rSections) while ($row = $rSections->fetch_assoc()) $sections[] = $row['section'];

// Menu untuk opsi parent (hanya menu top-level)
$parents = $conn->query("SELECT id, name FROM menus WHERE parent_id IS NULL OR parent_id = 0 ORDER BY section, sort_order");

// Daftar modul yang punya permission (untuk auto-suggest module)
$modules = [];
$rMods = $conn->query("SELECT DISTINCT module FROM permissions WHERE is_active = 1 ORDER BY module");
if ($rMods) while ($row = $rMods->fetch_assoc()) $modules[] = $row['module'];

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

// Edit mode
$editMenu = null;
if (isset($_GET['edit'])) {
    $mid = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM menus WHERE id = $mid LIMIT 1");
    if ($r && $r->num_rows > 0) $editMenu = $r->fetch_assoc();
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

<!-- ============ FORM MENU ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><?= $editMenu ? 'Edit Menu' : 'Tambah Menu Baru' ?></h3>
    <form method="POST">
        <input type="hidden" name="save_menu" value="1">
        <input type="hidden" name="id" value="<?= $editMenu['id'] ?? 0 ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Menu <span style="color: #EF4444;">*</span></label>
                <input type="text" name="name" class="form-input" placeholder="Contoh: Laporan Keuangan" required value="<?= htmlspecialchars($editMenu['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Slug</label>
                <input type="text" name="slug" class="form-input" placeholder="laporan-keuangan (kosong = otomatis)" value="<?= htmlspecialchars($editMenu['slug'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">URL <span style="color: #EF4444;">*</span></label>
                <input type="text" name="url" class="form-input" placeholder="reports.php" required value="<?= htmlspecialchars($editMenu['url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Icon (Font Awesome)</label>
                <input type="text" name="icon" class="form-input" placeholder="fa-chart-line" value="<?= htmlspecialchars($editMenu['icon'] ?? 'fa-circle') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Module (Permission)</label>
                <input type="text" name="module" class="form-input" list="module-list" placeholder="Modul permission yang mengatur menu ini" value="<?= htmlspecialchars($editMenu['module'] ?? '') ?>">
                <datalist id="module-list">
                    <?php foreach ($modules as $mod): ?>
                        <option value="<?= htmlspecialchars($mod) ?>"><?= htmlspecialchars($moduleNames[$mod] ?? $mod) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <small style="color: var(--text-light);">Menu hanya tampil untuk role yang punya permission <code>module:view</code></small>
            </div>
            <div class="form-group">
                <label class="form-label">Section</label>
                <input type="text" name="section" class="form-input" list="section-list" placeholder="Menu Utama" value="<?= htmlspecialchars($editMenu['section'] ?? 'Menu Utama') ?>">
                <datalist id="section-list">
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                    <?php endforeach; ?>
                    <option value="Menu Utama"></option>
                    <option value="Akses & Keamanan"></option>
                </datalist>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Parent (Submenu)</label>
                <select name="parent_id" class="form-select">
                    <option value="0">— Menu Utama (Top Level) —</option>
                    <?php if ($parents): while ($p = $parents->fetch_assoc()): ?>
                    <option value="<?= $p['id'] ?>" <?= (isset($editMenu) && (int)$editMenu['parent_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Urutan</label>
                <input type="number" name="sort_order" class="form-input" value="<?= (int)($editMenu['sort_order'] ?? 0) ?>" min="0">
            </div>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);" <?= (!isset($editMenu) || $editMenu['is_active']) ? 'checked' : '' ?>>
                <span>Menu aktif (tampil di sidebar)</span>
            </label>
        </div>
        <div style="display: flex; gap: 12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editMenu ? 'Simpan Perubahan' : 'Tambah Menu' ?>
            </button>
            <?php if ($editMenu): ?>
            <a href="menus.php" class="btn btn-outline">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============ DAFTAR MENU ============ -->
<div class="admin-card">
    <h3 class="admin-card-title">Daftar Menu Sidebar (<?= $menus ? $menus->num_rows : 0 ?>)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Menu</th>
                    <th>URL</th>
                    <th>Module</th>
                    <th>Section</th>
                    <th>Parent</th>
                    <th>Status</th>
                    <th style="width: 200px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($menus && $menus->num_rows > 0): while ($menu = $menus->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $menu['id'] ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="width: 30px; height: 30px; border-radius: 8px; background: rgba(212,168,83,0.12); color: #D4A853; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas <?= htmlspecialchars($menu['icon']) ?>"></i>
                            </span>
                            <div>
                                <strong><?= htmlspecialchars($menu['name']) ?></strong>
                                <?php if ($menu['parent_id']): ?>
                                    <span class="status-badge silver" style="font-size: 10px; margin-left: 4px;">SUB</span>
                                <?php endif; ?>
                                <br><small style="color: var(--text-light); font-size: 11px;"><?= htmlspecialchars($menu['slug']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><code style="font-size: 12px;"><?= htmlspecialchars($menu['url']) ?></code></td>
                    <td>
                        <?php if ($menu['module']): ?>
                            <span class="status-badge gold"><?= htmlspecialchars($menu['module']) ?></span>
                        <?php else: ?>
                            <span style="color: var(--text-light);">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($menu['section']) ?></td>
                    <td style="font-size: 12px;"><?= $menu['parent_name'] ? htmlspecialchars($menu['parent_name']) : '<span style="color: var(--text-light);">Top</span>' ?></td>
                    <td>
                        <span class="status-badge <?= $menu['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $menu['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <a href="menus.php?move=<?= $menu['id'] ?>&dir=up&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm sort-arrow" title="Naik"><i class="fas fa-chevron-up"></i></a>
                            <a href="menus.php?move=<?= $menu['id'] ?>&dir=down&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm sort-arrow" title="Turun"><i class="fas fa-chevron-down"></i></a>
                            <a href="menus.php?edit=<?= $menu['id'] ?>" class="btn btn-outline btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="menus.php?toggle=<?= $menu['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="<?= $menu['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                <i class="fas <?= $menu['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                            </a>
                            <a href="menus.php?delete=<?= $menu['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus"
                               onclick="return confirm('Hapus menu <?= htmlspecialchars($menu['name']) ?>?')"
                               style="color: #EF4444; border-color: #EF4444;">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada menu. Tambahkan menu pertama Anda.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
