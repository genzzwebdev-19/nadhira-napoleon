<?php
$currentPage = 'categories';
$pageTitle = 'Kategori';
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();
require_once __DIR__ . '/../config/rbac.php';
requirePermission('categories', 'view');

$errors = [];
$success = '';

// Handle POST/GET actions BEFORE layout.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    requirePermission('categories', (int)($_POST['id'] ?? 0) > 0 ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['id'] ?? 0);

    if (empty($name)) {
        $errors[] = 'Nama kategori wajib diisi';
    } else {
        $slug = generateSlug($name);
        $name_e = $conn->real_escape_string($name);
        $desc_e = $conn->real_escape_string($description);

        if ($editId > 0) {
            $sql = "UPDATE product_categories SET name='$name_e', slug='$slug', description='$desc_e', sort_order=$sort_order WHERE id=$editId";
        } else {
            $sql = "INSERT INTO product_categories (name, slug, description, sort_order) VALUES ('$name_e', '$slug', '$desc_e', $sort_order)";
        }

        if ($conn->query($sql)) {
            $success = 'Kategori berhasil ' . ($editId > 0 ? 'diperbarui' : 'ditambahkan') . '!';
            logActivity($editId > 0 ? 'update' : 'create', 'categories', ($editId > 0 ? 'Mengubah' : 'Menambahkan') . " kategori: $name");
        } else {
            $errors[] = 'Gagal: ' . $conn->error;
        }
    }
}

if (isset($_GET['delete'])) {
    requirePermission('categories', 'delete');
    $delId = (int)$_GET['delete'];
    $conn->query("DELETE FROM product_categories WHERE id = $delId");
    logActivity('delete', 'categories', "Menghapus kategori #$delId");
    header('Location: categories.php');
    exit;
}

$categories = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count FROM product_categories c ORDER BY sort_order ASC");

require_once __DIR__ . '/layout.php';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <div class="admin-card">
                <h3 class="admin-card-title">Tambah Kategori Baru</h3>
                <form method="POST" style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: flex-end;">
                    <?= csrfField() ?>
                    <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom: 0;">
                        <label class="form-label">Nama Kategori</label>
                        <input type="text" name="name" class="form-input" placeholder="Nama kategori" required>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 100px; margin-bottom: 0;">
                        <label class="form-label">Urutan</label>
                        <input type="number" name="sort_order" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group" style="flex: 3; min-width: 200px; margin-bottom: 0;">
                        <label class="form-label">Deskripsi</label>
                        <input type="text" name="description" class="form-input" placeholder="Deskripsi kategori">
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-bottom: 1px;">
                        <i class="fas fa-plus"></i> Tambah
                    </button>
                </form>
            </div>

            <div class="admin-card">
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th>Nama</th>
                                <th>Slug</th>
                                <th>Urutan</th>
                                <th>Produk</th>
                                <th style="width: 140px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($categories && $categories->num_rows > 0): 
                                while ($cat = $categories->fetch_assoc()): 
                                $editSlug = generateSlug($cat['name']);
                            ?>
                            <tr>
                                <td>#<?= $cat['id'] ?></td>
                                <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                                <td><code style="background: var(--soft-grey); padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($cat['slug']) ?></code></td>
                                <td><?= $cat['sort_order'] ?></td>
                                <td>
                                    <span class="status-badge <?= $cat['product_count'] > 0 ? 'active' : 'inactive' ?>">
                                        <?= $cat['product_count'] ?> produk
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn btn-outline btn-sm" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES) ?>', <?= $cat['sort_order'] ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($cat['product_count'] == 0): ?>
                                        <a href="categories.php?delete=<?= $cat['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus kategori <?= htmlspecialchars($cat['name']) ?>?')"
                                           style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada kategori</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Kategori</h3>
                        <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('active')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" id="edit-id">
                            <div class="form-group">
                                <label class="form-label">Nama Kategori</label>
                                <input type="text" name="name" id="edit-name" class="form-input" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Deskripsi</label>
                                <input type="text" name="description" id="edit-description" class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Urutan</label>
                                <input type="number" name="sort_order" id="edit-sort" class="form-input" min="0">
                            </div>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function editCategory(id, name, description, sortOrder) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-name').value = name;
                document.getElementById('edit-description').value = description;
                document.getElementById('edit-sort').value = sortOrder;
                document.getElementById('editModal').classList.add('active');
            }
            </script>
        </main>
    </div>
</body>
</html>
