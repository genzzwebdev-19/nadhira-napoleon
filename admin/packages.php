<?php
// ============================================
// ADMIN - PAKET SPESIAL (paket oleh-oleh homepage)
// CRUD + sinkronisasi produk terkait agar bisa
// ditambahkan ke keranjang & di-checkout.
// Website Nadhira Napoleon Pekanbaru
// ============================================
$currentPage = 'packages';
$pageTitle = 'Paket Spesial';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary

$conn = getConnection();

// Helper: pastikan kategori produk 'paket-spesial' ada
function ensurePaketCategory() {
    $conn = getConnection();
    $r = $conn->query("SELECT id FROM product_categories WHERE slug = 'paket-spesial' LIMIT 1");
    if ($r && $r->num_rows > 0) return (int)$r->fetch_assoc()['id'];
    $conn->query("INSERT INTO product_categories (name, slug, description, sort_order, is_active) VALUES ('Paket Spesial', 'paket-spesial', 'Paket oleh-oleh spesial', 98, 1)");
    return (int)$conn->insert_id;
}

// Helper: slug produk unik (exclude id sendiri saat edit)
function uniquePaketSlug($base, $excludeId = 0) {
    $conn = getConnection();
    $slug = generateSlug($base);
    $baseSlug = $slug;
    $i = 2;
    $ex = $excludeId > 0 ? " AND id <> $excludeId" : '';
    while (($r = $conn->query("SELECT id FROM products WHERE slug = '$slug'$ex LIMIT 1")) && $r->num_rows > 0) {
        $slug = $baseSlug . '-' . $i++;
    }
    return $slug;
}

// ============================================
// HANDLE POST - Save (add/edit) + upload image
// ============================================
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_package'])) {
    $editId = (int)($_POST['edit_id'] ?? 0);
    requirePermission('packages', $editId > 0 ? 'edit' : 'create');

    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $price = max(0, (float)($_POST['price'] ?? 0));
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $imageUrl = trim($_POST['image_url'] ?? '');

    // Simpan foto paket lama (untuk dibersihkan saat diganti dengan yang baru)
    $oldPackageImage = '';
    if ($editId > 0) {
        $oldP = $conn->query("SELECT image FROM packages WHERE id = $editId LIMIT 1");
        if ($oldP && $oldP->num_rows > 0) $oldPackageImage = $oldP->fetch_assoc()['image'] ?? '';
    }

    // Upload gambar
    if (isset($_FILES['package_image']) && $_FILES['package_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/packages/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $tmpPath = $_FILES['package_image']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['package_image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if ($_FILES['package_image']['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Ukuran foto terlalu besar (maks 20MB).';
        } elseif (!in_array($ext, $allowedExts) || !@getimagesize($tmpPath)) {
            $errors[] = 'Format foto tidak didukung (JPG, PNG, WebP, GIF).';
        } else {
            $destPath = $uploadDir . 'paket_' . time() . '_' . uniqid() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $uploaded = false;
            // Jika Cloudinary aktif, upload langsung ke Cloudinary (tanpa simpan lokal)
            if (cloudinaryEnabled()) {
                $up = cloudinaryUploadFromUploaded($tmpPath, $_FILES['package_image']['name'], 'nadhira/packages', 'paket');
                if ($up['success']) {
                    $imageUrl = $up['url'];
                    $uploaded = true;
                } else {
                    $errors[] = 'Gagal upload foto ke Cloudinary: ' . $up['message'];
                }
            } elseif (move_uploaded_file($tmpPath, $destPath)) {
                $imageUrl = SITE_URL . '/uploads/packages/' . basename($destPath);
                $uploaded = true;
            } else {
                $errors[] = 'Gagal mengunggah foto paket.';
            }
            // Foto lama diganti → hapus dari Cloudinary / lokal
            if ($uploaded && $oldPackageImage !== '' && $oldPackageImage !== $imageUrl) {
                if (isCloudinaryUrl($oldPackageImage)) {
                    cloudinaryDeleteByUrl($oldPackageImage);
                } elseif (strpos($oldPackageImage, '/uploads/packages/') !== false) {
                    $oldPath = __DIR__ . '/../uploads/packages/' . basename(parse_url($oldPackageImage, PHP_URL_PATH));
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
            }
        }
    }

    if ($name === '') $errors[] = 'Nama paket wajib diisi.';
    if ($price <= 0) $errors[] = 'Harga paket wajib diisi (lebih dari 0).';
    if ($editId <= 0 && $imageUrl === '') $errors[] = 'Foto paket wajib diisi (upload file atau isi URL).';

    if (empty($errors)) {
        $catId = ensurePaketCategory();
        $name_e = $conn->real_escape_string($name);
        $desc_e = $conn->real_escape_string($desc);
        $img_e = $conn->real_escape_string($imageUrl);
        $price = number_format($price, 2, '.', '');

        if ($editId > 0) {
            $pkgRow = null;
            $rp = $conn->query("SELECT * FROM packages WHERE id = $editId LIMIT 1");
            if ($rp && $rp->num_rows > 0) $pkgRow = $rp->fetch_assoc();
            $productId = (int)($pkgRow['product_id'] ?? 0);

            $conn->query("UPDATE packages SET name = '$name_e', description = '$desc_e', price = $price, image = '$img_e', sort_order = $sortOrder, is_active = $isActive WHERE id = $editId");

            // Sinkronisasi produk terkait
            if ($productId > 0) {
                $slug = uniquePaketSlug($name, $productId);
                $conn->query("UPDATE products SET name = '$name_e', slug = '$slug', description = '$desc_e', price = $price, is_active = $isActive WHERE id = $productId");
                if ($imageUrl !== '') {
                    $imgChk = $conn->query("SELECT id FROM product_images WHERE product_id = $productId AND is_primary = 1 LIMIT 1");
                    if ($imgChk && $imgChk->num_rows > 0) {
                        $conn->query("UPDATE product_images SET image = '$img_e' WHERE product_id = $productId AND is_primary = 1");
                    } else {
                        $conn->query("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES ($productId, '$img_e', 1, 0)");
                    }
                }
            }
            $success = 'Paket berhasil diperbarui!';
            logActivity('update', 'packages', "Mengubah paket #$editId");
        } else {
            // Buat produk terkait dulu, lalu paketnya
            $slug = uniquePaketSlug($name);
            $conn->query("INSERT INTO products (category_id, name, slug, description, price, stock, is_active, meta_title, meta_description) VALUES ($catId, '$name_e', '$slug', '$desc_e', $price, 999, $isActive, '$name_e', '$desc_e')");
            $productId = (int)$conn->insert_id;
            $conn->query("INSERT INTO packages (product_id, name, description, price, image, sort_order, is_active) VALUES ($productId, '$name_e', '$desc_e', $price, '$img_e', $sortOrder, $isActive)");
            if ($imageUrl !== '') {
                $conn->query("INSERT INTO product_images (product_id, image, is_primary, sort_order) VALUES ($productId, '$img_e', 1, 0)");
            }
            $success = 'Paket berhasil ditambahkan!';
            logActivity('create', 'packages', 'Menambahkan paket baru');
        }
    }
}

// ============================================
// HANDLE GET - Toggle active
// ============================================
if (isset($_GET['toggle'])) {
    requirePermission('packages', 'edit');
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE packages SET is_active = NOT is_active WHERE id = $id");
    $pkgRow = null;
    $rp = $conn->query("SELECT product_id, is_active FROM packages WHERE id = $id LIMIT 1");
    if ($rp && $rp->num_rows > 0) $pkgRow = $rp->fetch_assoc();
    if ($pkgRow && (int)$pkgRow['product_id'] > 0) {
        $conn->query("UPDATE products SET is_active = " . (int)$pkgRow['is_active'] . " WHERE id = " . (int)$pkgRow['product_id']);
    }
    logActivity('edit', 'packages', "Toggle status paket #$id");
    echo '<script>window.location.href="packages.php";</script>';
    exit;
}

// ============================================
// HANDLE GET - Delete (produk ikut dinonaktifkan)
// ============================================
if (isset($_GET['delete'])) {
    requirePermission('packages', 'delete');
    $id = (int)$_GET['delete'];
    $pkgRow = null;
    $rp = $conn->query("SELECT product_id, image FROM packages WHERE id = $id LIMIT 1");
    if ($rp && $rp->num_rows > 0) $pkgRow = $rp->fetch_assoc();
    // Bersihkan foto paket (Cloudinary / lokal)
    if ($pkgRow && !empty($pkgRow['image'])) {
        if (isCloudinaryUrl($pkgRow['image'])) {
            cloudinaryDeleteByUrl($pkgRow['image']);
        } elseif (strpos($pkgRow['image'], '/uploads/packages/') !== false) {
            $oldPath = __DIR__ . '/../uploads/packages/' . basename(parse_url($pkgRow['image'], PHP_URL_PATH));
            if (file_exists($oldPath)) @unlink($oldPath);
        }
    }
    if ($pkgRow && (int)$pkgRow['product_id'] > 0) {
        $conn->query("UPDATE products SET is_active = 0 WHERE id = " . (int)$pkgRow['product_id']);
    }
    $conn->query("DELETE FROM packages WHERE id = $id");
    logActivity('delete', 'packages', "Menghapus paket #$id");
    echo '<script>window.location.href="packages.php";</script>';
    exit;
}

// ============================================
// HANDLE GET - Move up/down (sort order)
// ============================================
if (isset($_GET['move'])) {
    requirePermission('packages', 'edit');
    $id = (int)$_GET['move'];
    $dir = $_GET['dir'] === 'up' ? 'up' : 'down';

    $rows = [];
    $r = $conn->query("SELECT id, sort_order FROM packages ORDER BY sort_order ASC, id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    $idx = -1;
    foreach ($rows as $i => $s) {
        if ((int)$s['id'] === $id) { $idx = $i; break; }
    }
    if ($idx > 0 && $dir === 'up') {
        $a = $rows[$idx]; $b = $rows[$idx - 1];
        $conn->query("UPDATE packages SET sort_order = " . (int)$b['sort_order'] . " WHERE id = " . (int)$a['id']);
        $conn->query("UPDATE packages SET sort_order = " . (int)$a['sort_order'] . " WHERE id = " . (int)$b['id']);
    } elseif ($idx >= 0 && $idx < count($rows) - 1 && $dir === 'down') {
        $a = $rows[$idx]; $b = $rows[$idx + 1];
        $conn->query("UPDATE packages SET sort_order = " . (int)$b['sort_order'] . " WHERE id = " . (int)$a['id']);
        $conn->query("UPDATE packages SET sort_order = " . (int)$a['sort_order'] . " WHERE id = " . (int)$b['id']);
    }
    echo '<script>window.location.href="packages.php";</script>';
    exit;
}

// ============================================
// LOAD DATA
// ============================================
$packages = $conn->query("SELECT * FROM packages ORDER BY sort_order ASC, id ASC");

$editPackage = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $r = $conn->query("SELECT * FROM packages WHERE id = $editId LIMIT 1");
    if ($r && $r->num_rows > 0) $editPackage = $r->fetch_assoc();
}
?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if (!empty($errors)): foreach ($errors as $err): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
<?php endforeach; endif; ?>

<!-- Add/Edit Form -->
<div class="admin-card">
    <h3 class="admin-card-title">
        <i class="fas fa-<?= $editPackage ? 'edit' : 'plus-circle' ?>"></i>
        <?= $editPackage ? 'Edit Paket' : 'Tambah Paket Baru' ?>
    </h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_package" value="1">
        <?php if ($editPackage): ?>
            <input type="hidden" name="edit_id" value="<?= (int)$editPackage['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Paket <span style="color: #EF4444;">*</span></label>
                <input type="text" name="name" class="form-input" required
                       value="<?= htmlspecialchars($editPackage['name'] ?? '') ?>"
                       placeholder="Contoh: Paket Keluarga">
            </div>
            <div class="form-group">
                <label class="form-label">Harga (Rp) <span style="color: #EF4444;">*</span></label>
                <input type="number" name="price" class="form-input" min="0" step="0.01" required
                       value="<?= (float)($editPackage['price'] ?? 0) > 0 ? (float)$editPackage['price'] : '' ?>"
                       placeholder="Contoh: 275000">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Isi Paket / Deskripsi</label>
                <textarea name="description" class="form-textarea" rows="3"
                          placeholder="Contoh: Berisi Napoleon 2 box + Pancake Durian + Brownies + Mochi..."><?= htmlspecialchars($editPackage['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Foto Paket</label>
                <input type="file" name="package_image" class="form-input" accept="image/jpeg,image/png,image/webp,image/gif">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                    <i class="fas fa-info-circle"></i> Maks 20MB (JPG, PNG, WebP, GIF).
                    <?= $editPackage ? 'Kosongkan jika tidak ingin mengubah foto.' : '' ?>
                </small>
                <?php if ($editPackage && !empty($editPackage['image'])): ?>
                    <img src="<?= htmlspecialchars($editPackage['image']) ?>" alt="Preview"
                         style="margin-top: 10px; max-width: 100%; max-height: 180px; object-fit: cover; border-radius: 10px; border: 2px solid var(--border-color);">
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">URL Foto (opsional, alternatif upload)</label>
                <input type="url" name="image_url" class="form-input"
                       value="<?= htmlspecialchars($editPackage['image'] ?? '') ?>"
                       placeholder="URL gambar — mis. https://domain-anda.com/uploads/packages/....jpg">
            </div>
            <div class="form-group">
                <label class="form-label">Urutan</label>
                <input type="number" name="sort_order" class="form-input" min="0"
                       value="<?= (int)($editPackage['sort_order'] ?? 0) ?>">
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">Semakin kecil angka, semakin awal tampil di homepage.</small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);"
                        <?= !$editPackage || $editPackage['is_active'] ? 'checked' : '' ?>>
                    Tampilkan di homepage
                </label>
                <small style="color: var(--text-muted); display: block;">Nonaktif = paket disembunyikan dari homepage & produknya tidak bisa dibeli.</small>
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $editPackage ? 'Simpan Perubahan' : 'Tambah Paket' ?>
                </button>
                <?php if ($editPackage): ?>
                    <a href="packages.php" class="btn btn-outline" style="margin-left: 8px;">
                        <i class="fas fa-times"></i> Batal
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Packages List -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-list"></i> Daftar Paket</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 110px;">Foto</th>
                    <th>Nama</th>
                    <th>Harga</th>
                    <th>Urutan</th>
                    <th>Status</th>
                    <th style="width: 230px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($packages && $packages->num_rows > 0): ?>
                    <?php
                    $pkgRows = [];
                    while ($p = $packages->fetch_assoc()) { $pkgRows[] = $p; }
                    $totalRows = count($pkgRows);
                    foreach ($pkgRows as $i => $p):
                    ?>
                    <tr>
                        <td>
                            <img src="<?= htmlspecialchars($p['image'] ?: 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=200&q=80') ?>" alt="Paket"
                                 style="width: 110px; height: 70px; object-fit: cover; border-radius: 6px; display: block;">
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($p['name']) ?></strong>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                <?= htmlspecialchars(mb_substr(strip_tags($p['description'] ?? ''), 0, 70)) ?><?= mb_strlen(strip_tags($p['description'] ?? '')) > 70 ? '...' : '' ?>
                            </div>
                        </td>
                        <td style="font-weight: 600; color: var(--soft-gold);">Rp <?= number_format((float)$p['price'], 0, ',', '.') ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <span style="font-size: 12px; color: var(--text-muted); min-width: 20px;"><?= (int)$p['sort_order'] ?></span>
                                <a href="packages.php?move=<?= $p['id'] ?>&dir=up" class="btn btn-outline btn-sm sort-arrow <?= $i === 0 ? 'sort-disabled' : '' ?>" title="Naik">
                                    <i class="fas fa-arrow-up"></i>
                                </a>
                                <a href="packages.php?move=<?= $p['id'] ?>&dir=down" class="btn btn-outline btn-sm sort-arrow <?= $i === $totalRows - 1 ? 'sort-disabled' : '' ?>" title="Turun">
                                    <i class="fas fa-arrow-down"></i>
                                </a>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= $p['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <a href="packages.php?edit=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="packages.php?toggle=<?= $p['id'] ?>" class="btn btn-outline btn-sm"
                                   title="<?= $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                   style="<?= $p['is_active'] ? '' : 'color: #10B981; border-color: #10B981;' ?>">
                                    <i class="fas <?= $p['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                </a>
                                <a href="packages.php?delete=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                   onclick="return confirm('Hapus paket <?= htmlspecialchars($p['name']) ?>? Produk terkait ikut dinonaktifkan.')"
                                   style="color: #EF4444; border-color: #EF4444;">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-gift" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                        Belum ada paket. Tambahkan paket pertama di atas.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main></div></body></html>
