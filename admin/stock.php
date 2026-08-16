<?php
$currentPage = 'stock';
$pageTitle = 'Stock / Gudang';
require_once __DIR__ . '/../config/rbac.php';

requirePermission('stock', 'view');
$conn = getConnection();

$errors = [];
$success = '';
$info = '';

// ============================================
// ACTION: Catat pergerakan stock (masuk/keluar/opname)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_movement'])) {
    verifyCsrf();
    requirePermission('stock', 'create');
    $productId = (int)($_POST['product_id'] ?? 0);
    $type = in_array($_POST['type'] ?? '', ['in', 'out', 'opname'], true) ? $_POST['type'] : 'adjust';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $branchId = (int)($_POST['branch_id'] ?? 0);

    $r = $conn->query("SELECT id, name, stock FROM products WHERE id = $productId LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        $errors[] = 'Produk tidak ditemukan';
    } elseif ($quantity < 0) {
        $errors[] = 'Jumlah tidak boleh negatif';
    } else {
        $prod = $r->fetch_assoc();
        $stockBefore = (int)$prod['stock'];
        if ($type === 'in') {
            $stockAfter = $stockBefore + $quantity;
            $qty = $quantity;
        } elseif ($type === 'out') {
            if ($quantity > $stockBefore) {
                $errors[] = 'Stock keluar melebihi stock tersedia (' . $stockBefore . ')';
            } else {
                $stockAfter = $stockBefore - $quantity;
                $qty = $quantity;
            }
        } else { // opname: quantity = hasil hitung fisik
            $stockAfter = $quantity;
            $qty = $quantity;
        }

        if (empty($errors)) {
            $notes_e = $conn->real_escape_string($notes);
            $bid = $branchId > 0 ? $branchId : 'NULL';
            $conn->query(
                "INSERT INTO stock_movements (product_id, branch_id, type, quantity, stock_before, stock_after, notes, created_by)
                 VALUES ($productId, $bid, '$type', $qty, $stockBefore, $stockAfter, '$notes_e', " . getCurrentUserId() . ")"
            );
            $conn->query("UPDATE products SET stock = $stockAfter WHERE id = $productId");

            // Sinkron stok per cabang bila movement memilih cabang tertentu.
            // Hanya row BARU yang di-set is_available=1; row existing TIDAK diubah
            // status ketersediaannya (menghormati "Tidak Dijual" yang diatur admin).
            if ($branchId > 0) {
                ensureBranchProductsStock();
                $conn->query("INSERT INTO branch_products (branch_id, product_id, is_available, stock)
                    VALUES ($branchId, $productId, 1, $stockAfter)
                    ON DUPLICATE KEY UPDATE stock = $stockAfter");
            }

            $typeLabel = ['in' => 'Stock Masuk', 'out' => 'Stock Keluar', 'opname' => 'Stock Opname'][$type];
            $success = "$typeLabel berhasil dicatat untuk: {$prod['name']} (stok: $stockBefore → $stockAfter)";
            logActivity('create', 'stock', "$typeLabel: {$prod['name']} ($stockBefore → $stockAfter)");
            header('Location: stock.php');
            exit;
        }
    }
}

// ============================================
// ACTION: Hapus riwayat pergerakan (koreksi)
// ============================================
if (isset($_GET['delete_movement'])) {
    verifyCsrf();
    requirePermission('stock', 'delete');
    $mid = (int)$_GET['delete_movement'];
    $conn->query("DELETE FROM stock_movements WHERE id = $mid");
    $success = 'Riwayat pergerakan stock dihapus.';
    logActivity('delete', 'stock', "Menghapus riwayat stock #$mid");
    header('Location: stock.php');
    exit;
}

// ============================================
// DATA
// ============================================
// Ringkasan
$totalProducts = $conn->query("SELECT COUNT(*) c FROM products WHERE is_active = TRUE")->fetch_assoc()['c'];
$lowStock = $conn->query("SELECT COUNT(*) c FROM products WHERE stock > 0 AND stock <= 5 AND is_active = TRUE")->fetch_assoc()['c'];
$outStock = $conn->query("SELECT COUNT(*) c FROM products WHERE stock <= 0 AND is_active = TRUE")->fetch_assoc()['c'];
$totalStock = $conn->query("SELECT COALESCE(SUM(stock),0) c FROM products WHERE is_active = TRUE")->fetch_assoc()['c'];

// Filter
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$where = "WHERE p.is_active = TRUE";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.name LIKE '%$s%' OR p.slug LIKE '%$s%')";
}
if ($filter === 'low') $where .= " AND p.stock > 0 AND p.stock <= 5";
if ($filter === 'out') $where .= " AND p.stock <= 0";

$products = $conn->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN product_categories c ON c.id = p.category_id $where ORDER BY p.stock ASC, p.name ASC");
$branches = $conn->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY sort_order");

// Riwayat pergerakan (hanya yang punya permission view lanjutan)
$movements = $conn->query("
    SELECT sm.*, p.name AS product_name, b.name AS branch_name, u.full_name AS user_name
    FROM stock_movements sm
    LEFT JOIN products p ON p.id = sm.product_id
    LEFT JOIN branches b ON b.id = sm.branch_id
    LEFT JOIN users u ON u.id = sm.created_by
    ORDER BY sm.created_at DESC LIMIT 50
");

$typeLabels = ['in' => 'Masuk', 'out' => 'Keluar', 'opname' => 'Opname', 'adjust' => 'Penyesuaian'];

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

<!-- ============ STATS ============ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon"><i class="fas fa-boxes-stacked"></i></div><div><div class="stat-card-value"><?= number_format($totalProducts) ?></div></div></div><div class="stat-card-label">Produk Aktif</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon info"><i class="fas fa-cubes"></i></div><div><div class="stat-card-value"><?= number_format($totalStock) ?></div></div></div><div class="stat-card-label">Total Unit Stock</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-card-value"><?= $lowStock ?></div></div></div><div class="stat-card-label">Stok Menipis (≤5)</div></div>
    <div class="stat-card"><div class="stat-card-header"><div class="stat-card-icon" style="background: rgba(239,68,68,0.12); color: #EF4444;"><i class="fas fa-ban"></i></div><div><div class="stat-card-value"><?= $outStock ?></div></div></div><div class="stat-card-label">Stok Habis</div></div>
</div>

<!-- ============ FORM PERGERAKAN STOCK ============ -->
<?php if (hasPermission('stock', 'create')): ?>
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-arrows-up-down" style="color: var(--soft-gold);"></i> Catat Pergerakan Stock</h3>
    <form method="POST">
        <input type="hidden" name="save_movement" value="1">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Produk <span style="color: #EF4444;">*</span></label>
                <select name="product_id" class="form-select" required>
                    <option value="">— Pilih Produk —</option>
                    <?php $products->data_seek(0); while ($p = $products->fetch_assoc()): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (stok: <?= (int)$p['stock'] ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Tipe Pergerakan</label>
                <select name="type" class="form-select">
                    <option value="in">Stock Masuk</option>
                    <option value="out">Stock Keluar</option>
                    <option value="opname">Stock Opname (isi ulang hitung fisik)</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Jumlah <span style="color: #EF4444;">*</span></label>
                <input type="number" name="quantity" class="form-input" min="0" required value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Cabang</label>
                <select name="branch_id" class="form-select">
                    <option value="0">— Pusat / Semua —</option>
                    <?php if ($branches): while ($b = $branches->fetch_assoc()): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Catatan</label>
            <input type="text" name="notes" class="form-input" placeholder="Contoh: Restock dari supplier, rusak, dll">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pergerakan</button>
    </form>
</div>
<?php endif; ?>

<!-- ============ DAFTAR STOCK ============ -->
<div class="admin-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <h3 class="admin-card-title" style="margin: 0;">Daftar Stock Produk</h3>
        <?php if (hasPermission('stock', 'edit')): ?>
        <a href="branch-stock.php?mode=matrix" class="btn btn-outline btn-sm"><i class="fas fa-warehouse"></i> Stok per Cabang</a>
        <?php endif; ?>
        <form method="GET" style="display: flex; gap: 8px; align-items: center;">
            <input type="text" name="search" class="form-input" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>" style="width: 220px;">
            <select name="filter" class="form-select" style="width: auto;">
                <option value="">Semua</option>
                <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Stok Menipis</option>
                <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>Stok Habis</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-search"></i></button>
            <a href="stock.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
        </form>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th>Harga</th>
                    <th>Stock</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $products->data_seek(0); if ($products && $products->num_rows > 0): while ($p = $products->fetch_assoc()): ?>
                <tr>
                    <td>#<?= $p['id'] ?></td>
                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($p['category_name'] ?: '-') ?></td>
                    <td style="font-size: 12px;">Rp <?= number_format($p['price'], 0, ',', '.') ?></td>
                    <td><strong style="font-size: 15px;"><?= (int)$p['stock'] ?></strong></td>
                    <td>
                        <?php if ((int)$p['stock'] <= 0): ?>
                            <span class="status-badge cancelled">Habis</span>
                        <?php elseif ((int)$p['stock'] <= 5): ?>
                            <span class="status-badge pending">Menipis</span>
                        <?php else: ?>
                            <span class="status-badge active">Tersedia</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada produk</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ RIWAYAT PERGERAKAN ============ -->
<div class="admin-card">
    <h3 class="admin-card-title"><i class="fas fa-history" style="color: var(--soft-gold);"></i> Riwayat Pergerakan Stock (50 terakhir)</h3>
    <div style="overflow-x: auto;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Tipe</th>
                    <th>Qty</th>
                    <th>Sebelum → Sesudah</th>
                    <th>Cabang</th>
                    <th>Oleh</th>
                    <th>Tanggal</th>
                    <?php if (hasPermission('stock', 'delete')): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($movements && $movements->num_rows > 0): while ($m = $movements->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($m['product_name'] ?: "Produk #{$m['product_id']}") ?></strong></td>
                    <td>
                        <?php $cls = $m['type'] === 'in' ? 'active' : ($m['type'] === 'out' ? 'cancelled' : 'processing'); ?>
                        <span class="status-badge <?= $cls ?>"><?= $typeLabels[$m['type']] ?? $m['type'] ?></span>
                    </td>
                    <td><strong><?= number_format((int)$m['quantity']) ?></strong></td>
                    <td style="font-size: 12px;"><?= (int)$m['stock_before'] ?> → <strong><?= (int)$m['stock_after'] ?></strong></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($m['branch_name'] ?: '-') ?></td>
                    <td style="font-size: 12px;"><?= htmlspecialchars($m['user_name'] ?: '-') ?></td>
                    <td style="font-size: 12px; white-space: nowrap;"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                    <?php if (hasPermission('stock', 'delete')): ?>
                    <td>
                        <a href="stock.php?delete_movement=<?= $m['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-outline btn-sm" title="Hapus"
                           onclick="return confirm('Hapus riwayat ini?')" style="color: #EF4444; border-color: #EF4444;">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">Belum ada pergerakan stock</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
        </main>
    </div>
</body>
</html>
