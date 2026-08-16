<?php
$currentPage = 'products';
$pageTitle = 'Produk';
require_once __DIR__ . '/layout.php';

$conn = getConnection();

// Handle search & filter
$search = $_GET['search'] ?? '';
$categoryFilter = (int)($_GET['category'] ?? 0);
$where = "WHERE p.is_active = TRUE";
$params = [];

if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.name LIKE '%$s%' OR p.slug LIKE '%$s%')";
}
if ($categoryFilter > 0) {
    $where .= " AND p.category_id = $categoryFilter";
}

$products = $conn->query("
    SELECT p.*, c.name as category_name,
        (SELECT image FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
    FROM products p 
    LEFT JOIN product_categories c ON p.category_id = c.id 
    $where 
    ORDER BY p.updated_at DESC
");

$categories = $conn->query("SELECT * FROM product_categories ORDER BY sort_order ASC");
?>

        <!-- Search & Filter Bar -->
        <div class="admin-card">
            <form method="GET" style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                    <label class="form-label">Cari Produk</label>
                    <input type="text" name="search" class="form-input" placeholder="Nama produk..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group" style="min-width: 180px; margin-bottom: 0;">
                    <label class="form-label">Kategori</label>
                    <select name="category" class="form-select">
                        <option value="0">Semua Kategori</option>
                        <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom: 1px;">
                    <i class="fas fa-search"></i> Cari
                </button>
                <a href="products.php" class="btn btn-outline" style="margin-bottom: 1px;">
                    <i class="fas fa-times"></i> Reset
                </a>
                <a href="product-form.php?action=add" class="btn btn-primary" style="margin-bottom: 1px;">
                    <i class="fas fa-plus"></i> Tambah Produk
                </a>
            </form>
        </div>

        <!-- Products Table -->
        <div class="admin-card">
            <div style="overflow-x: auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 70px;">Foto</th>
                            <th>Nama Produk</th>
                            <th>Kategori</th>
                            <th>Harga</th>
                            <th>Stok</th>
                            <th>Terjual</th>
                            <th>Rating</th>
                            <th style="width: 140px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products && $products->num_rows > 0): ?>
                            <?php while ($p = $products->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= $p['id'] ?></td>
                                <td>
                                    <?php $img = $p['product_image'] ?? ''; ?>
                                    <?php if ($img): ?>
                                        <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" 
                                             style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; display: block;">
                                    <?php else: ?>
                                        <div style="width: 50px; height: 50px; background: var(--soft-grey); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 14px;">
                                            <i class="fas fa-box"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($p['name']) ?></strong>
                                    <br><small style="color: var(--text-light);">Slug: <?= htmlspecialchars($p['slug']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                                <td>
                                    Rp <?= number_format($p['price'], 0, ',', '.') ?>
                                    <?php if ($p['discount_price'] > 0): ?>
                                        <br><small style="color: #EF4444;">Diskon: Rp <?= number_format($p['discount_price'], 0, ',', '.') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $p['stock'] > 5 ? 'active' : ($p['stock'] > 0 ? 'pending' : 'inactive') ?>">
                                        <?= $p['stock'] ?>
                                    </span>
                                </td>
                                <td><?= number_format($p['total_sold']) ?></td>
                                <td><?= $p['rating'] ?> ★</td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <a href="product-form.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="product-delete.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="Hapus"
                                           onclick="return confirm('Hapus produk <?= htmlspecialchars($p['name']) ?>?')" style="color: #EF4444; border-color: #EF4444;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-box-open" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                                Belum ada produk. <a href="product-form.php?action=add" style="color: var(--soft-gold);">Tambah produk pertama</a>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </main>
    </div>
</body>
</html>
