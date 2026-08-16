<?php
$currentPage = 'products';
// Guard aksi sebelum layout: form tambah = create, form edit = edit
$requiredAction = (($_GET['action'] ?? 'add') === 'edit') ? 'edit' : 'create';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/cloudinary.php'; // penyimpanan foto Cloudinary

$conn = getConnection();
$action = $_GET['action'] ?? 'add';
$editId = (int)($_GET['id'] ?? 0);
$product = null;
$errors = [];
$success = '';

// Get categories for dropdown
$categories = $conn->query("SELECT * FROM product_categories ORDER BY sort_order ASC");

// Get active branches for availability checkboxes
$branches = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$allBranches = [];
if ($branches) { while ($b = $branches->fetch_assoc()) $allBranches[] = $b; }

// Load product for editing
if ($action === 'edit' && $editId > 0) {
    $r = $conn->query("SELECT * FROM products WHERE id = $editId LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $product = $r->fetch_assoc();
    } else {
        $errors[] = 'Produk tidak ditemukan';
        $action = 'add';
    }
}

// Cabang tempat produk tersedia (untuk pre-check saat edit)
$productBranchIds = [];
if ($product) {
    $br = $conn->query("SELECT branch_id FROM branch_products WHERE product_id = {$product['id']} AND is_available = 1");
    if ($br) { while ($row = $br->fetch_assoc()) $productBranchIds[] = (int)$row['branch_id']; }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $composition = trim($_POST['composition'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $expiration = trim($_POST['expiration'] ?? '');
    $storage = trim($_POST['storage_instructions'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $discount_price = (float)($_POST['discount_price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_best_seller = isset($_POST['is_best_seller']) ? 1 : 0;
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_desc = trim($_POST['meta_description'] ?? '');

    // Validation
    if (empty($name)) $errors[] = 'Nama produk wajib diisi';
    if ($category_id <= 0) $errors[] = 'Kategori wajib dipilih';
    if ($price <= 0) $errors[] = 'Harga harus lebih dari 0';

    if (empty($errors)) {
        $slug = generateSlug($name);
        $name_e = $conn->real_escape_string($name);
        $desc_e = $conn->real_escape_string($description);
        $comp_e = $conn->real_escape_string($composition);
        $weight_e = $conn->real_escape_string($weight);
        $exp_e = $conn->real_escape_string($expiration);
        $stor_e = $conn->real_escape_string($storage);
        $mt_e = $conn->real_escape_string($meta_title ?: $name);
        $md_e = $conn->real_escape_string($meta_desc ?: $description);

        // Check slug uniqueness
        $slugCheck = $conn->query("SELECT id FROM products WHERE slug = '$slug' " . ($editId > 0 ? "AND id != $editId" : "") . " LIMIT 1");
        if ($slugCheck && $slugCheck->num_rows > 0) {
            $slug .= '-' . time();
        }

        if ($editId > 0) {
            // Update
            $sql = "UPDATE products SET 
                category_id = $category_id, name = '$name_e', slug = '$slug',
                description = '$desc_e', composition = '$comp_e', weight = '$weight_e',
                expiration = '$exp_e', storage_instructions = '$stor_e',
                price = $price, discount_price = $discount_price, stock = $stock,
                is_featured = $is_featured, is_best_seller = $is_best_seller,
                meta_title = '$mt_e', meta_description = '$md_e'
                WHERE id = $editId";
            if ($conn->query($sql)) {
                $success = 'Produk berhasil diperbarui!';
                logActivity('update', 'products', "Mengubah produk: $name (ID $editId)");
                // Reload product
                $r = $conn->query("SELECT * FROM products WHERE id = $editId LIMIT 1");
                $product = $r->fetch_assoc();
            } else {
                $errors[] = 'Gagal memperbarui produk: ' . $conn->error;
            }
        } else {
            // Insert
            $sql = "INSERT INTO products (category_id, name, slug, description, composition, weight, expiration, storage_instructions, price, discount_price, stock, is_featured, is_best_seller, meta_title, meta_description) 
                    VALUES ($category_id, '$name_e', '$slug', '$desc_e', '$comp_e', '$weight_e', '$exp_e', '$stor_e', $price, $discount_price, $stock, $is_featured, $is_best_seller, '$mt_e', '$md_e')";
            if ($conn->query($sql)) {
                $success = 'Produk berhasil ditambahkan!';
                logActivity('create', 'products', "Menambahkan produk: $name");
                $editId = $conn->insert_id;
                $action = 'edit';
                // Reload
                $r = $conn->query("SELECT * FROM products WHERE id = $editId LIMIT 1");
                $product = $r->fetch_assoc();
            } else {
                $errors[] = 'Gagal menambahkan produk: ' . $conn->error;
            }
        }

        // Simpan ketersediaan produk di cabang (tabel branch_products)
        if ($editId > 0) {
            $conn->query("DELETE FROM branch_products WHERE product_id = $editId");
            $branchIds = $_POST['branches'] ?? [];
            foreach ((array)$branchIds as $bid) {
                $bid = (int)$bid;
                if ($bid > 0) {
                    $conn->query("INSERT INTO branch_products (branch_id, product_id, is_available) VALUES ($bid, $editId, 1)");
                }
            }
            // Refresh daftar cabang tercentang agar checkbox tampil sesuai state terbaru
            $productBranchIds = [];
            foreach ((array)$branchIds as $bid) { if ((int)$bid > 0) $productBranchIds[] = (int)$bid; }
        }
    }
}

// ============================================
// IMAGE MANAGEMENT
// ============================================

// Handle delete image
if (isset($_GET['delete_image']) && $editId > 0) {
    requirePermission('products', 'edit');
    $imgId = (int)$_GET['delete_image'];
    $img = $conn->query("SELECT image FROM product_images WHERE id = $imgId AND product_id = $editId LIMIT 1");
    if ($img && $img->num_rows > 0) {
        $imgRow = $img->fetch_assoc();
        // Hapus dari Cloudinary bila tersimpan di sana
        if (isCloudinaryUrl($imgRow['image'] ?? '')) {
            cloudinaryDeleteByUrl($imgRow['image']);
        }
        // Delete file from server
        // Only delete if it's a local uploaded file (not external URL like Unsplash)
        if (strpos($imgRow['image'], SITE_URL . '/uploads/') === 0) {
            $relPath = substr($imgRow['image'], strlen(SITE_URL) + 1);
            $filePath = __DIR__ . '/../' . $relPath;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $conn->query("DELETE FROM product_images WHERE id = $imgId");
        echo '<script>window.location.href="product-form.php?action=edit&id=' . $editId . '";</script>';
        exit;
    }
}

// Handle set primary image
if (isset($_GET['set_primary']) && $editId > 0) {
    requirePermission('products', 'edit');
    $imgId = (int)$_GET['set_primary'];
    $conn->query("UPDATE product_images SET is_primary = 0 WHERE product_id = $editId");
    $conn->query("UPDATE product_images SET is_primary = 1 WHERE id = $imgId AND product_id = $editId");
    echo '<script>window.location.href="product-form.php?action=edit&id=' . $editId . '";</script>';
    exit;
}

// Handle image upload in POST
$uploadDir = __DIR__ . '/../uploads/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$productId = $editId;
if (isset($_FILES['product_images']) && $productId > 0) {
    $files = $_FILES['product_images'];
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        
        $tmpName = $files['tmp_name'][$i];
        $origName = $files['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        
        if (!in_array($ext, $allowedExts)) continue;
        
        $filename = 'product_' . $productId . '_' . time() . '_' . $i . '.' . $ext;
        $destPath = $uploadDir . $filename;
        
        $imageUrl = '';
        // Jika Cloudinary aktif, upload langsung ke Cloudinary (tanpa simpan lokal)
        if (cloudinaryEnabled()) {
            $up = cloudinaryUploadFile($tmpName, 'nadhira/products', 'product_' . $productId . '_' . time() . '_' . $i . '_' . $ext);
            if ($up['success']) {
                $imageUrl = $up['url'];
            } else {
                $errors[] = 'Gagal upload foto ke Cloudinary: ' . $up['message'];
            }
        } elseif (move_uploaded_file($tmpName, $destPath)) {
            $imageUrl = SITE_URL . '/uploads/products/' . $filename;
        }

        if ($imageUrl !== '') {
            $imageUrl_e = $conn->real_escape_string($imageUrl);
            
            // Check if this is the first image (set as primary)
            $existingCount = $conn->query("SELECT COUNT(*) as c FROM product_images WHERE product_id = $productId");
            $isPrimary = ($existingCount && $existingCount->fetch_assoc()['c'] == 0) ? 1 : 0;
            
            // Get max sort_order
            $maxSort = $conn->query("SELECT MAX(sort_order) as m FROM product_images WHERE product_id = $productId");
            $nextSort = ($maxSort && $maxSort->fetch_assoc()['m'] !== null) ? (int)$maxSort->fetch_assoc()['m'] + 1 : 0;
            
            $conn->query("INSERT INTO product_images (product_id, image, is_primary, sort_order) 
                          VALUES ($productId, '$imageUrl_e', $isPrimary, $nextSort)");
        }
    }
}

// Get product images for display
$productImages = [];
if ($editId > 0) {
    $imgResult = $conn->query("SELECT * FROM product_images WHERE product_id = $editId ORDER BY is_primary DESC, sort_order ASC");
    if ($imgResult) {
        while ($imgRow = $imgResult->fetch_assoc()) {
            $productImages[] = $imgRow;
        }
    }
}

$pageTitle = $action === 'edit' ? 'Edit Produk' : 'Tambah Produk';
?>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="admin-card">
                <h3 class="admin-card-title"><?= $action === 'edit' ? 'Edit Produk' : 'Tambah Produk Baru' ?></h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Produk <span style="color: #EF4444;">*</span></label>
                            <input type="text" name="name" class="form-input" required
                                   value="<?= htmlspecialchars($product['name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kategori <span style="color: #EF4444;">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Pilih Kategori --</option>
                                <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($product && $product['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-textarea" style="min-height: 100px;"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Komposisi</label>
                            <textarea name="composition" class="form-textarea"><?= htmlspecialchars($product['composition'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Petunjuk Penyimpanan</label>
                            <textarea name="storage_instructions" class="form-textarea"><?= htmlspecialchars($product['storage_instructions'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Berat</label>
                            <input type="text" name="weight" class="form-input" placeholder="Contoh: 350 gram"
                                   value="<?= htmlspecialchars($product['weight'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Masa Kadaluarsa</label>
                            <input type="text" name="expiration" class="form-input" placeholder="Contoh: 7 hari dalam kulkas"
                                   value="<?= htmlspecialchars($product['expiration'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Harga (Rp) <span style="color: #EF4444;">*</span></label>
                            <input type="number" name="price" class="form-input" required min="0"
                                   value="<?= $product['price'] ?? 0 ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Harga Diskon (Rp) <small style="color: var(--text-light);">(isi 0 jika tidak ada diskon)</small></label>
                            <input type="number" name="discount_price" class="form-input" min="0"
                                   value="<?= $product['discount_price'] ?? 0 ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Stok</label>
                            <input type="number" name="stock" class="form-input" min="0" value="<?= $product['stock'] ?? 0 ?>">
                        </div>
                        <div class="form-group" style="display: flex; align-items: flex-end; gap: var(--space-lg); padding-bottom: 14px;">
                            <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                                <input type="checkbox" name="is_featured" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);"
                                    <?= ($product && $product['is_featured']) ? 'checked' : '' ?>>
                                <span>Produk Unggulan</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                                <input type="checkbox" name="is_best_seller" value="1" style="width: 18px; height: 18px; accent-color: var(--soft-gold);"
                                    <?= ($product && $product['is_best_seller']) ? 'checked' : '' ?>>
                                <span>Best Seller</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Meta Title (SEO)</label>
                            <input type="text" name="meta_title" class="form-input" value="<?= htmlspecialchars($product['meta_title'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Meta Description (SEO)</label>
                            <textarea name="meta_description" class="form-textarea"><?= htmlspecialchars($product['meta_description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-store"></i> Ketersediaan di Cabang</label>
                            <p style="font-size: 12px; color: var(--text-muted); margin: -6px 0 10px;">Centang cabang tempat produk ini tersedia. Jika tidak ada yang dicentang, produk tersedia di semua cabang.</p>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <?php foreach ($allBranches as $b): ?>
                                <label style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--warm-white); border: 1.5px solid var(--soft-grey); border-radius: var(--radius-md); cursor: pointer; font-size: var(--text-sm); transition: var(--transition-base);">
                                    <input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>" style="width: 17px; height: 17px; accent-color: var(--soft-gold);" <?= in_array((int)$b['id'], $productBranchIds) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($b['name']) ?></span>
                                </label>
                                <?php endforeach; ?>
                                <?php if (empty($allBranches)): ?>
                                <span style="color: var(--text-muted); font-size: var(--text-sm);">Belum ada cabang aktif. Tambah cabang di menu Cabang.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($editId > 0): ?>
                    <!-- ============ IMAGE UPLOAD SECTION ============ -->
                    <div class="admin-card" style="margin-top: var(--space-xl); padding: var(--space-xl);">
                        <h3 class="admin-card-title" style="margin-bottom: var(--space-lg);">
                            <i class="fas fa-images"></i> Foto Produk
                        </h3>
                        
                        <!-- Existing Images -->
                        <?php if (!empty($productImages)): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: var(--space-md); margin-bottom: var(--space-xl);">
                            <?php foreach ($productImages as $img): 
                                $isPrimary = $img['is_primary'] ? true : false;
                            ?>
                            <div style="position: relative; border-radius: var(--radius-md); overflow: hidden; border: 3px solid <?= $isPrimary ? 'var(--soft-gold)' : 'var(--soft-grey)' ?>; background: var(--warm-white);">
                                <img src="<?= htmlspecialchars($img['image']) ?>" 
                                     alt="Product Image" 
                                     style="width: 100%; aspect-ratio: 1; object-fit: cover; display: block;"
                                     loading="lazy">
                                <div style="padding: 6px;">
                                    <?php if ($isPrimary): ?>
                                        <span style="display: inline-block; font-size: 10px; background: var(--soft-gold); color: #fff; padding: 2px 8px; border-radius: var(--radius-full); font-weight: 600;">
                                            <i class="fas fa-star"></i> Utama
                                        </span>
                                    <?php else: ?>
                                        <a href="?action=edit&id=<?= $editId ?>&set_primary=<?= $img['id'] ?>" 
                                           class="btn btn-sm" 
                                           style="font-size: 10px; padding: 2px 8px; background: var(--soft-grey); color: var(--text-primary); border-radius: var(--radius-full); text-decoration: none;"
                                           onclick="return confirm('Jadikan sebagai foto utama?')">
                                            <i class="fas fa-star"></i> Utamakan
                                        </a>
                                    <?php endif; ?>
                                    <a href="?action=edit&id=<?= $editId ?>&delete_image=<?= $img['id'] ?>" 
                                       style="font-size: 10px; padding: 2px 8px; color: #EF4444; border-radius: var(--radius-full); text-decoration: none; margin-left: 4px;"
                                       onclick="return confirm('Hapus foto ini?')">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p style="color: var(--text-muted); font-size: var(--text-sm); margin-bottom: var(--space-md);">
                            <i class="fas fa-info-circle"></i> Belum ada foto. Upload foto produk di bawah.
                        </p>
                        <?php endif; ?>
                        
                        <!-- Upload New Images -->
                        <div style="padding: var(--space-lg); border: 2px dashed var(--soft-grey); border-radius: var(--radius-md); text-align: center;">
                            <label style="cursor: pointer; display: block;">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--soft-gold); margin-bottom: var(--space-sm); display: block;"></i>
                                <span style="font-weight: 500; color: var(--text-primary);">Klik untuk upload foto</span>
                                <span style="display: block; font-size: var(--text-sm); color: var(--text-muted); margin-top: 4px;">Format: JPG, PNG, WebP (maks 2MB per foto)</span>
                                <input type="file" name="product_images[]" accept="image/jpeg,image/png,image/webp" 
                                       multiple style="display: none;"
                                       onchange="document.getElementById('uploadBtn').style.display='inline-flex'; this.parentElement.querySelector('span').textContent = this.files.length + ' file dipilih';">
                            </label>
                            <button type="submit" id="uploadBtn" class="btn btn-primary btn-sm" style="display: none; margin-top: var(--space-md);" onclick="this.form.submit()">
                                <i class="fas fa-upload"></i> Upload Foto
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="admin-card" style="margin-top: var(--space-xl); padding: var(--space-xl); background: var(--soft-gold-gradient);">
                        <p style="color: var(--text-muted); font-size: var(--text-sm);">
                            <i class="fas fa-info-circle"></i> Simpan produk terlebih dahulu untuk dapat mengupload foto.
                        </p>
                    </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: var(--space-md); margin-top: var(--space-xl);">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> <?= $action === 'edit' ? 'Simpan Perubahan' : 'Simpan Produk' ?>
                        </button>
                        <a href="products.php" class="btn btn-outline btn-lg">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
